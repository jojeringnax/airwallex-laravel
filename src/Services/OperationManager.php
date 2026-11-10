<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Throwable;

class OperationManager
{
    public function __construct(private readonly BillingContext $context) {}

    public function reserveCustomer(Model $billable): Customer
    {
        if (! $billable->exists || $billable->getKey() === null) {
            throw new ConflictException('A persisted billable is required.');
        }
        $identity = [...$this->context->attributes(), 'billable_type' => $billable->getMorphClass(), 'billable_id' => (string) $billable->getKey()];

        /** @var Customer */
        return ModelRegistry::query('customer')->firstOrCreate($identity);
    }

    /** @param array<string,mixed> $specification @param array<string,mixed> $intent */
    public function prepare(Customer $customer, string $actionKey, string $kind, array $specification, array $intent, ?Subscription $subscription = null, ?string $name = null, ?string $lockKey = null): Operation
    {
        $this->context->assertModel($customer);
        if ($actionKey === '' || strlen($actionKey) > 255 || ($name !== null && ($name === '' || strlen($name) > 255))) {
            throw new ConflictException('Action key and subscription name must be nonempty bounded strings.');
        }
        if ($subscription !== null) {
            $this->context->assertModel($subscription);
            if ($subscription->customer_id !== $customer->id) {
                throw new ConflictException('Subscription does not belong to this customer.');
            }
        }
        $hash = hash('sha256', CanonicalJson::encode([$kind, $customer->getKey(), $subscription?->getKey(), $name, $specification]));
        $identity = [...$this->context->attributes(), 'action_key' => $actionKey];
        try {
            /** @var Operation */
            return DB::transaction(function () use ($identity, $hash, $customer, $kind, $intent, $subscription, $name, $lockKey): Operation {
                app(CommitGuard::class)->assertMayCommit();
                $existing = ModelRegistry::query('operation')->where($identity)->first();
                if ($existing) {
                    if (! hash_equals($existing->intent_hash, $hash)) {
                        throw new ConflictException('Action key was already used for a different intent.', $existing->id);
                    }

                    return $existing;
                }
                /** @var Operation $operation */
                $operation = ModelRegistry::make('operation', [...$identity, 'customer_id' => $customer->id, 'subscription_id' => $subscription?->id, 'kind' => $kind, 'intent_hash' => $hash, 'intent' => $intent, 'request_id' => (string) Str::uuid(), 'status' => 'prepared', 'lock_key' => $lockKey ?? ($subscription ? 'subscription/'.$subscription->id : 'customer/'.$customer->id)]);
                $operation->save();
                if ($name !== null) {
                    if (ModelRegistry::query('subscription_slot')->where('customer_id', $customer->id)->where('name', $name)->exists()) {
                        throw new ConflictException('Subscription name is already reserved.');
                    }
                    ModelRegistry::make('subscription_slot', ['customer_id' => $customer->id, 'name' => $name, 'operation_id' => $operation->id])->save();
                }

                return $operation;
            });
        } catch (QueryException $exception) {
            // SQLite may choose either uniqueness or a busy conflict in a genuine race.
            // Both preserve the committed operation and prohibit a second financial write.
            throw new ConflictException('A concurrent billing action holds this reservation.');
        }
    }

    /** @param Closure():array<array-key,mixed> $write @param Closure(array,Operation):mixed $confirm */
    public function execute(Operation $operation, Closure $write, Closure $confirm): mixed
    {
        $this->context->assertModel($operation);
        $changed = ModelRegistry::query('operation')->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'in_flight']);
        if ($changed !== 1) {
            throw new AmbiguousOperationException($operation->id);
        }
        $responseReceived = false;
        try {
            $result = $write();
            $responseReceived = true;
            if (! isset($result['id']) || ! is_string($result['id']) || $result['id'] === '') {
                throw new EvidenceException('Mutation response has no resource identity.');
            }
            $operation->refresh();
            $operation->remote_id = $result['id'];
            $operation->save();
            $confirmed = $confirm($result, $operation);
            $operation->refresh();
            if ($operation->status !== 'acknowledged') {
                $this->succeed($operation);
            }

            return $confirmed;
        } catch (ValidationException $exception) {
            if ($responseReceived) {
                ModelRegistry::query('operation')->whereKey($operation->id)->where('status', 'in_flight')->update(['status' => 'ambiguous', 'error_code' => 'reconciliation_required']);
                throw new AmbiguousOperationException($operation->id, $exception);
            }
            DB::transaction(function () use ($operation): void {
                app(CommitGuard::class)->assertMayCommit();
                $operation->refresh();
                $operation->forceFill(['status' => 'failed', 'lock_key' => null, 'error_code' => 'sdk_validation'])->save();
                ModelRegistry::query('subscription_slot')->where('operation_id', $operation->id)->whereNull('subscription_id')->delete();
            });
            throw $exception;
        } catch (Throwable $exception) {
            // Even a local storage failure after remote success must never invite replay.
            try {
                ModelRegistry::query('operation')->whereKey($operation->id)->where('status', 'in_flight')->update(['status' => 'ambiguous', 'error_code' => 'reconciliation_required']);
            } catch (Throwable) {
                // The pre-I/O in_flight row still durably proves the attempted action.
            }
            throw new AmbiguousOperationException($operation->id, $exception);
        }
    }

    /** @internal Known response, but an item postcondition remains unobserved. */
    public function acknowledgeItemEffect(Operation $operation): void
    {
        $this->context->assertModel($operation);
        DB::transaction(function () use ($operation): void {
            app(CommitGuard::class)->assertMayCommit();
            $operation->refresh();
            if (! in_array($operation->kind, ['subscription.quantity', 'subscription.items', 'subscription.swap', 'subscription.add_item', 'subscription.remove_item'], true)
                || ! in_array($operation->status, ['in_flight', 'ambiguous', 'acknowledged'], true) || $operation->remote_id === null || $operation->subscription_id === null) {
                throw new ConflictException('Only a recorded item-action response can be acknowledged without observed fulfillment.', $operation->id);
            }
            // A parallel canonical recovery may already have established fulfillment.
            // Preserve that terminal proof instead of saving a stale model over it.
            ModelRegistry::query('operation')->whereKey($operation->id)
                ->whereIn('status', ['in_flight', 'ambiguous', 'acknowledged'])
                ->update(['status' => 'acknowledged', 'lock_key' => null, 'error_code' => 'item_effect_unconfirmed']);
            $operation->refresh();
        });
    }

    public function succeed(Operation $operation): void
    {
        $this->context->assertModel($operation);
        DB::transaction(function () use ($operation): void {
            app(CommitGuard::class)->assertMayCommit();
            $operation->refresh();
            $operation->forceFill(['status' => 'succeeded', 'lock_key' => null, 'error_code' => null])->save();
        });
    }

    public function releaseTerminated(Subscription $subscription): void
    {
        $this->context->assertModel($subscription);
        if ($subscription->status !== 'CANCELLED') {
            throw new ConflictException('Only confirmed provider termination releases a subscription name.');
        }
        DB::transaction(function () use ($subscription): void {
            app(CommitGuard::class)->assertMayCommit();
            if (! ModelRegistry::query('subscription')->whereKey($subscription->id)->where('status', 'CANCELLED')->exists()) {
                throw new ConflictException('Subscription termination is not persisted.');
            }
            ModelRegistry::query('subscription_slot')->where('subscription_id', $subscription->id)->delete();
        });
    }
}
