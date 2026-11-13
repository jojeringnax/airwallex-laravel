<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation as PaymentOperation;
use Jojeringnax\AirwallexLaravel\Payments\Observations;
use SensitiveParameter;

/** A new consumer command may risk a duplicate; it never proves noncreation. */
class CreationRetries
{
    public static function creation(string $kind): bool
    {
        return str_ends_with($kind, '.create') || in_array($kind, ['checkout.subscription', 'checkout.setup'], true);
    }

    /** @internal Called with the owning customer locked, before reserving a new command. */
    public function release(Model $customer, string $lock, string $newKey, string $kind): void
    {
        if (! self::creation($kind)) {
            return;
        }
        $payments = $customer instanceof Customer;
        $query = $payments ? PaymentOperation::query() : ModelRegistry::query('operation');
        $query->where('customer_id', $customer->getKey())->where('lock_key', $lock)->whereIn('status', ['in_flight', 'ambiguous'])->whereNull('remote_id');
        $query->lockForUpdate();
        $old = $query->first();
        if ($old === null || ! self::creation($old->getAttribute('kind'))) {
            return;
        }
        $this->supersede($old, 'consumer_new_action', 'new_creation_requested', $newKey);
    }

    /** Explicit consumer/operator choice, also usable after a crashed in-flight worker. */
    public function allowRetry(Operation|PaymentOperation $operation, #[SensitiveParameter] string $actor, #[SensitiveParameter] string $reason): void
    {
        if ($actor === '' || strlen($actor) > 255 || $reason === '' || strlen($reason) > 2048) {
            throw new ConflictException('A bounded actor and reason are required for creation retry.');
        }
        DB::transaction(function () use ($operation, $actor, $reason): void {
            app(CommitGuard::class)->assertMayCommit();
            if ($this->quarantined($operation)) {
                return;
            }
            if (! self::creation($operation->kind) || $operation->getAttribute('remote_id') !== null
                || ! in_array($operation->status, ['in_flight', 'ambiguous'], true)) {
                throw new ConflictException('Only an attempted creation with unknown resource identity can permit a duplicate retry.', $operation->id);
            }
            $this->supersede($operation, $actor, $reason, null);
        });
    }

    private function supersede(Operation|PaymentOperation $old, #[SensitiveParameter] string $actor, #[SensitiveParameter] string $reason, ?string $key): void
    {
        $old->forceFill(['status' => 'superseded', 'lock_key' => null, 'error_code' => 'new_creation_requested',
            'retry_release' => ['actor' => $actor, 'replacement_action_key' => $key, 'reason' => $reason,
                'released_at' => now()->utc()->format('Y-m-d\TH:i:s.uP'), 'outcome' => 'unknown'],
        ])->save();
        if ($old instanceof PaymentOperation) {
            DB::table('airwallex_payment_intents')->where('operation_id', $old->getKey())->update(['order_reservation' => null]);
            app(Observations::class)->record($old, ['status' => 'SUPERSEDED_UNKNOWN_CREATION']);
        } else {
            ModelRegistry::query('subscription_slot')->where('operation_id', $old->getKey())->whereNull('subscription_id')->delete();
            app(Outbox::class)->record('CreationRetryObserved', $old, ['operation_id' => $old->getKey(), 'outcome' => 'retry_permitted', 'reason' => 'unknown_creation']);
        }
    }

    /** A locator remains quarantined, never adopted into a replacement's projection. */
    public function quarantined(Operation|PaymentOperation $operation, ?string $candidate = null): bool
    {
        $context = $operation instanceof PaymentOperation ? app(Context::class) : app(BillingContext::class);
        $context->assertModel($operation);
        if (DB::transactionLevel() > 0) {
            $table = $operation instanceof PaymentOperation ? 'airwallex_payment_customers' : ModelRegistry::make('customer')->getTable();
            DB::table($table)->where('id', $operation->getAttribute('customer_id'))->lockForUpdate()->first();
            $query = $operation->newQuery()->whereKey($operation->id);
            $query->lockForUpdate();
            $current = $query->firstOrFail();
            $operation->setRawAttributes($current->getAttributes(), true);
        } else {
            $operation->refresh();
        }
        $context->assertModel($operation);
        if ($operation->getAttribute('status') !== 'superseded') {
            return false;
        }
        if ($candidate !== null) {
            DB::transaction(function () use ($operation, $candidate): void {
                if ($operation instanceof PaymentOperation) {
                    app(Observations::class)->record($operation, ['remote_id' => $candidate, 'status' => 'SUPERSEDED_CREATION_LOCATOR']);
                } else {
                    app(Outbox::class)->record('CreationRetryObserved', $operation, ['operation_id' => $operation->id, 'remote_id' => $candidate, 'outcome' => 'quarantined', 'reason' => 'superseded_creation_locator']);
                }
            });
        }

        return true;
    }

    public function during(Operation|PaymentOperation $operation, Closure $work): mixed
    {
        return app(CommitGuard::class)->during(fn () => $this->assertCurrent($operation), $work);
    }

    public function assertCurrent(Operation|PaymentOperation $operation, ?string $candidate = null): void
    {
        if ($this->quarantined($operation, $candidate)) {
            throw new ConflictException('This unknown creation was superseded by a new consumer command; reconcile its locator manually without adopting it.', $operation->id);
        }
    }
}
