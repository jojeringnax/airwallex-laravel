<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Throwable;

class Customers
{
    public function __construct(private readonly Context $context, private readonly Operations $operations) {}

    public function local(Model $billable): ?Customer
    {
        return Customer::query()->where($this->identity($billable))->first();
    }

    public function create(Model $billable, string $key, array $profile = []): Customer
    {
        Operations::validateKey($key);
        $this->profile($profile);
        $customer = $this->reserve($billable);
        if ($customer->merchant_customer_id === null) {
            $customer = DB::transaction(function () use ($customer): Customer {
                $query = Customer::query()->whereKey($customer->id);
                $query->lockForUpdate();
                $current = $query->firstOrFail();
                if ($current->merchant_customer_id === null) {
                    $current->merchant_customer_id = $current->id;
                    $current->save();
                }

                return $current;
            });
        }
        $existing = Operation::query()->where([...$this->context->attributes(), 'action_key' => $key])->exists();
        if ($customer->remote_id !== null && ! $existing) {
            return $customer;
        }
        $intent = [...$profile, 'merchant_customer_id' => $customer->merchant_customer_id];
        $operation = $this->operations->prepare($customer, $key, 'customer.create', $intent);
        if ($operation->status !== 'prepared') {
            return $this->recover($operation);
        }

        return $this->operations->execute($operation,
            fn () => app(Client::class)->sdk->payments()->customers()->create([...$intent, 'request_id' => $operation->request_id]),
            fn (array $remote) => $this->confirm($customer, $operation, $remote['id']),
        );
    }

    public function link(Model $billable, string $id): Customer
    {
        $customer = $this->reserve($billable);
        if ($customer->remote_id !== null && $customer->remote_id !== $id) {
            throw new OwnershipException('Payments customer cannot be reassigned.');
        }
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->customers()->retrieve($id));

        try {
            return $this->attach($customer, $remote, $id, explicit: true);
        } catch (QueryException) {
            throw new ConflictException('Payments customer is already associated or changed concurrently.');
        }
    }

    public function sync(Customer $customer, string $key, array $profile): Customer
    {
        $customer = $this->current($customer);
        $this->profile($profile);
        if ($customer->remote_id === null) {
            throw new ConflictException('A confirmed Payments customer is required.');
        }
        $operation = $this->operations->prepare($customer, $key, 'customer.update', $profile, $customer->remote_id);
        if ($operation->status !== 'prepared') {
            return $this->recover($operation);
        }

        return $this->operations->execute($operation,
            fn () => app(Client::class)->sdk->payments()->customers()->update($customer->remote_id, [...$profile, 'request_id' => $operation->request_id]),
            fn () => $this->confirm($customer, $operation, $customer->remote_id),
        );
    }

    public function recover(Operation $operation, ?string $knownId = null): Customer
    {
        $this->context->assertModel($operation);
        if (! in_array($operation->kind, ['customer.create', 'customer.update'], true) || $operation->status === 'failed') {
            throw new ConflictException('This operation has no recoverable Payments customer action.');
        }
        $customer = Customer::query()->findOrFail($operation->customer_id);
        $id = $operation->remote_id ?? $operation->target_id ?? $knownId;
        if ($id === null) {
            // No request-id lookup/replay/absence guarantee is assumed. Operator-known IDs
            // must pass the same canonical request and intent checks as known responses.
            throw new AmbiguousOperationException($operation->id);
        }
        if ($knownId !== null && $knownId !== $id) {
            throw new OwnershipException('Recovery identity contradicts the recorded Payments action.');
        }
        try {
            $result = $this->confirm($customer, $operation, $id);
            if ($operation->remote_id === null) {
                $operation->remote_id = $id;
                $operation->save();
            }
            $this->operations->succeed($operation);

            return $result;
        } catch (Throwable $exception) {
            throw new AmbiguousOperationException($operation->id, $exception);
        }
    }

    public function current(Customer $customer): Customer
    {
        $this->context->assertModel($customer);
        $current = Customer::query()->find($customer->id);
        if ($current === null) {
            throw new OwnershipException('Payments customer mapping is unavailable.');
        }
        $this->context->assertModel($current);

        return $current;
    }

    public function offboard(Customer $customer): void
    {
        $customer = $this->current($customer);
        $customer->forceFill(['disabled_at' => now()->utc()->format('Y-m-d H:i:s.u')])->save();
    }

    private function confirm(Customer $customer, Operation $operation, string $id): Customer
    {
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->customers()->retrieve($id));
        if (($remote['request_id'] ?? null) !== $operation->request_id || ! self::contains($remote, $operation->intent)) {
            throw new EvidenceException('Payments customer action is not canonically correlated.');
        }

        return $this->attach($customer, $remote, $id);
    }

    private function attach(Customer $customer, array $remote, string $id, bool $explicit = false): Customer
    {
        return DB::transaction(function () use ($customer, $remote, $id, $explicit): Customer {
            app(CommitGuard::class)->assertMayCommit();
            $this->context->assertModel($customer);
            $query = Customer::query()->whereKey($customer->id);
            $query->lockForUpdate();
            $customer = $query->firstOrFail();
            $this->context->assertModel($customer);
            if ($explicit && Operation::query()->where('customer_id', $customer->id)->whereNotNull('lock_key')->exists()) {
                throw new ConflictException('An unresolved Payments customer command prevents explicit association.');
            }
            Proof::identity($remote, $id, $this->context);
            $merchantId = $remote['merchant_customer_id'] ?? null;
            if (! is_string($merchantId) || $merchantId === '' || (! $explicit && $merchantId !== $customer->merchant_customer_id)) {
                throw new OwnershipException('Payments customer merchant identity does not match.');
            }
            if ($customer->remote_id !== null && $customer->remote_id !== $id) {
                throw new OwnershipException('Payments customer mapping cannot be reassigned.');
            }
            // The local correlation identity is established exactly once on explicit link.
            $customer->forceFill(['merchant_customer_id' => $merchantId, 'remote_id' => $id, 'snapshot' => SubscriptionSynchronizer::sanitize($remote), 'synced_at' => now()])->save();

            return $customer;
        });
    }

    private function reserve(Model $billable): Customer
    {
        try {
            return Customer::query()->firstOrCreate($this->identity($billable));
        } catch (QueryException) {
            throw new ConflictException('Concurrent Payments customer association must be refreshed.');
        }
    }

    private function identity(Model $billable): array
    {
        if (! $billable->exists || $billable->getKey() === null) {
            throw new ConflictException('A persisted authorized billable is required.');
        }

        return [...$this->context->attributes(), 'billable_type' => $billable->getMorphClass(), 'billable_id' => (string) $billable->getKey()];
    }

    private function profile(array $profile): void
    {
        if (array_diff(array_keys($profile), ['address', 'business_name', 'email', 'first_name', 'last_name', 'metadata', 'phone_number']) !== []) {
            throw new ConflictException('Payments customer identity and request fields are managed by the package.');
        }
    }

    /** Exact SDK-decoded intent proof; ambiguous numeric-key maps fail closed. */
    public static function contains(mixed $remote, mixed $intent): bool
    {
        if (is_array($intent) && ! array_is_list($intent)) {
            if (! is_array($remote) || array_is_list($remote)) {
                return false;
            }
            foreach ($intent as $key => $value) {
                if (! array_key_exists($key, $remote) || ! self::contains($remote[$key], $value)) {
                    return false;
                }
            }

            return true;
        }

        return CanonicalJson::encode($remote) === CanonicalJson::encode($intent);
    }
}
