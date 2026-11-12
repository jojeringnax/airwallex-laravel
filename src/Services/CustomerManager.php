<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\SdkException;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\BillingException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Throwable;

class CustomerManager
{
    public function __construct(private readonly BillingContext $context, private readonly OperationManager $operations) {}

    /** @param array<string,mixed> $profile */
    public function createOrGet(Model $billable, string $actionKey, array $profile = []): Customer
    {
        $profile = $this->profile($billable, $profile);
        if (array_key_exists('request_id', $profile)) {
            throw new ConflictException('Customer request identity is managed by its durable operation.');
        }
        $customer = $this->reserve($billable);
        if ($actionKey === '' || strlen($actionKey) > 255) {
            throw new ConflictException('Action key must be a nonempty bounded string.');
        }
        if ($customer->remote_id !== null) {
            $operation = $this->existingCreate($customer, $actionKey, $profile);
            if ($operation === null) {
                // Getting a known mapping neither acquires a mutation hold nor
                // consumes a new command identity. Profile changes require sync.
                return $customer;
            }
        } else {
            $operation = $this->operations->prepare($customer, $actionKey, 'customer.create', $profile, ['profile' => $profile]);
        }
        $this->refresh($customer);
        if ($operation->status === 'succeeded') {
            return $this->confirmedMapping($customer, $operation);
        }
        if ($operation->status === 'failed') {
            throw new ConflictException('The rejected customer command requires a new action key.', $operation->id);
        }
        if ($operation->status !== 'prepared') {
            return $this->recover($customer, $operation);
        }
        if ($customer->remote_id !== null) {
            // A link may have committed after the initial reservation but before
            // this operation acquired its durable hold. No write was sent.
            try {
                return DB::transaction(function () use ($customer, $operation): Customer {
                    app(CommitGuard::class)->assertMayCommit();
                    $operation->remote_id = $customer->remote_id;
                    $operation->save();
                    $this->operations->succeed($operation);

                    return $customer;
                });
            } catch (Throwable) {
                throw new ConflictException('Customer confirmation must be retried.', $operation->id);
            }
        }

        return $this->operations->execute(
            $operation,
            fn () => app(Client::class)->billing()->customers()->create([...$operation->intent['profile'], 'request_id' => $operation->request_id]),
            fn (array $remote) => $this->attach($customer, $remote),
        );
    }

    public function link(Model $billable, string $remoteId): Customer
    {
        $customer = $this->reserve($billable);
        if ($customer->remote_id !== null && $customer->remote_id !== $remoteId) {
            throw new OwnershipException('This billable already has a different customer mapping.');
        }
        $remote = SdkResponseShape::normalize(app(Client::class)->billing()->customers()->retrieve($remoteId));
        $this->verify($remote, $remoteId);

        return $this->attach($customer, $remote, linking: true);
    }

    /** @param array<string,mixed> $profile */
    public function sync(Model $billable, string $actionKey, array $profile = []): Customer
    {
        $customer = $this->requireCustomer($billable);
        $remoteId = $customer->remote_id;
        if ($remoteId === null) {
            throw new ConflictException('A confirmed customer mapping is required.');
        }
        $profile = $this->profile($billable, $profile);
        $operation = $this->operations->prepare($customer, $actionKey, 'customer.update', ['remote_id' => $customer->remote_id, 'profile' => $profile], ['profile' => $profile, 'remote_id' => $customer->remote_id]);
        if ($operation->status === 'succeeded') {
            return $this->confirmedMapping($customer, $operation);
        }
        if ($operation->status === 'failed') {
            throw new ConflictException('The rejected customer command requires a new action key.', $operation->id);
        }
        if ($operation->status !== 'prepared') {
            return $this->recover($customer, $operation);
        }

        return $this->operations->execute(
            $operation,
            fn () => app(Client::class)->billing()->customers()->update($remoteId, $operation->intent['profile']),
            fn (array $remote) => $this->attach($customer, $remote, expectedId: $remoteId),
        );
    }

    public function requireCustomer(Model $billable): Customer
    {
        if (! $billable->exists || $billable->getKey() === null) {
            throw new ConflictException('A persisted billable is required.');
        }
        try {
            $customer = ModelRegistry::query('customer')->where([
                ...$this->context->attributes(),
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $billable->getKey(),
            ])->first();
        } catch (QueryException) {
            throw new ConflictException('Customer mapping could not be read.');
        }
        if (! $customer || $customer->remote_id === null) {
            throw new ConflictException('A confirmed customer mapping is required.');
        }

        return $customer;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function profile(Model $billable, array $profile): array
    {
        $defaults = method_exists($billable, 'airwallexProfile') ? $billable->airwallexProfile() : [];

        return array_replace($defaults, $profile);
    }

    private function reserve(Model $billable): Customer
    {
        try {
            return $this->operations->reserveCustomer($billable);
        } catch (QueryException) {
            throw new ConflictException('A concurrent customer reservation must be retried.');
        }
    }

    /** @param array<array-key,mixed> $remote */
    private function verify(array $remote, ?string $expectedId = null): string
    {
        $id = $remote['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new EvidenceException('Customer response has no usable identity.');
        }
        if ($expectedId !== null && $id !== $expectedId) {
            throw new OwnershipException('Customer response identity does not match the requested customer.');
        }
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $field => $value) {
            if (array_key_exists($field, $remote) && $remote[$field] !== $value) {
                throw new OwnershipException('Customer response conflicts with the configured billing context.');
            }
        }

        return $id;
    }

    /** @param array<array-key,mixed> $remote */
    private function attach(Customer $customer, array $remote, bool $linking = false, ?string $expectedId = null): Customer
    {
        $id = $this->verify($remote, $expectedId);
        $this->context->assertModel($customer);
        try {
            return DB::transaction(function () use ($customer, $id, $linking): Customer {
                app(CommitGuard::class)->assertMayCommit();
                $locked = ModelRegistry::query('customer')->whereKey($customer->id);
                $locked->lockForUpdate();
                $customer = $locked->firstOrFail();
                if ($customer->remote_id !== null && $customer->remote_id !== $id) {
                    throw new OwnershipException('A customer mapping cannot be reassigned.');
                }
                if ($linking && $customer->remote_id === null && ModelRegistry::query('operation')->where('customer_id', $customer->id)->whereIn('status', ['prepared', 'in_flight', 'ambiguous'])->exists()) {
                    throw new ConflictException('An unresolved customer operation prevents linking.');
                }
                $foreign = ModelRegistry::query('customer')->where($this->context->attributes())->where('remote_id', $id)->whereKeyNot($customer->id)->exists();
                if ($foreign) {
                    throw new OwnershipException('The remote customer is already mapped to another billable.');
                }
                $customer->forceFill(['remote_id' => $id, 'synced_at' => now()->utc()])->save();

                return $customer;
            });
        } catch (QueryException) {
            throw new ConflictException('Customer mapping conflicts with a concurrent association.');
        } catch (BillingException|SdkException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new EvidenceException('Customer mapping could not be confirmed.');
        }
    }

    private function confirmedMapping(Customer $customer, Operation $operation): Customer
    {
        $this->refresh($customer);
        if ($customer->remote_id === null || $customer->remote_id !== $operation->remote_id) {
            throw new ConflictException('The completed operation has a different customer identity.', $operation->id);
        }

        return $customer;
    }

    /** @param array<string,mixed> $profile */
    private function existingCreate(Customer $customer, string $actionKey, array $profile): ?Operation
    {
        try {
            $operation = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => $actionKey])->first();
        } catch (QueryException) {
            throw new ConflictException('Customer command identity could not be checked.');
        }
        if ($operation !== null) {
            $hash = hash('sha256', CanonicalJson::encode(['customer.create', $customer->getKey(), null, null, $profile]));
            if (! hash_equals($operation->intent_hash, $hash)) {
                throw new ConflictException('Action key was already used for a different intent.', $operation->id);
            }
        }

        return $operation;
    }

    /** Refresh only an already mapped customer; never discover by profile/email. */
    public function reconcile(Customer $customer): Customer
    {
        $this->context->assertModel($customer);
        $customer = ModelRegistry::query('customer')->findOrFail($customer->id);
        $this->context->assertModel($customer);
        if ($customer->remote_id === null) {
            throw new ConflictException('A confirmed customer mapping is required.');
        }
        $remote = SdkResponseShape::normalize(app(Client::class)->billing()->customers()->retrieve($customer->remote_id));
        $this->verify($remote, $customer->remote_id);

        return $this->attach($customer, $remote, expectedId: $customer->remote_id);
    }

    /** @internal Attempted-operation recovery performs canonical reads only. */
    public function reconcileOperation(Operation $operation): Customer
    {
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->findOrFail($operation->id);
        $this->context->assertModel($operation);
        if (! in_array($operation->kind, ['customer.create', 'customer.update'], true)
            || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Customer operation has no recoverable attempt.', $operation->id);
        }
        $customer = ModelRegistry::query('customer')->findOrFail($operation->customer_id);
        $this->context->assertModel($customer);

        return $operation->status === 'succeeded' ? $this->confirmedMapping($customer, $operation) : $this->recover($customer, $operation);
    }

    private function recover(Customer $customer, Operation $operation): Customer
    {
        $id = $operation->kind === 'customer.update' ? ($operation->intent['remote_id'] ?? null) : $operation->remote_id;
        if (! in_array($operation->status, ['in_flight', 'ambiguous'], true) || ! is_string($id) || $id === '' || ($operation->remote_id !== null && $operation->remote_id !== $id) || ($customer->remote_id !== null && $customer->remote_id !== $id)) {
            throw new AmbiguousOperationException($operation->id);
        }
        try {
            $remote = SdkResponseShape::normalize(app(Client::class)->billing()->customers()->retrieve($id));
            $this->verify($remote, $id);
            if ($operation->kind === 'customer.update' && ! self::contains($remote, $operation->intent['profile'])) {
                throw new EvidenceException('The intended customer update is not confirmed.');
            }
            $customer = $this->attach($customer, $remote, expectedId: $id);
            if ($operation->remote_id === null) {
                $operation->remote_id = $id;
                $operation->save();
            }
            $this->operations->succeed($operation);

            return $customer;
        } catch (Throwable $exception) {
            throw new AmbiguousOperationException($operation->id, $exception);
        }
    }

    private static function contains(mixed $observed, mixed $intended): bool
    {
        if (is_array($intended) || $intended instanceof \stdClass) {
            if (! is_array($observed) && ! $observed instanceof \stdClass) {
                return false;
            }
            $intendedObject = $intended instanceof \stdClass || ! array_is_list($intended);
            $observedObject = $observed instanceof \stdClass || ! array_is_list($observed);
            if ($intendedObject !== $observedObject) {
                return false;
            }
            if (! $intendedObject) {
                return CanonicalJson::encode($observed) === CanonicalJson::encode($intended);
            }
            $members = is_array($intended) ? $intended : get_object_vars($intended);
            if ($members === []) {
                return CanonicalJson::encode($observed) === '{}';
            }
            $observed = (array) $observed;
            foreach ($members as $key => $value) {
                if (! array_key_exists($key, $observed) && ($value === null || $value === '')) {
                    continue;
                }
                if (! array_key_exists($key, $observed) || ! self::contains($observed[$key], $value)) {
                    return false;
                }
            }

            return true;
        }

        return ($intended === null || $intended === '') ? ($observed === null || $observed === '') : $observed === $intended;
    }

    private function refresh(Customer $customer): void
    {
        try {
            $customer->refresh();
        } catch (Throwable) {
            throw new ConflictException('Customer mapping could not be refreshed.');
        }
    }
}
