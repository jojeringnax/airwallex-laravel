<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Throwable;

class Operations
{
    public function __construct(private readonly Context $context) {}

    public function prepare(Customer $customer, string $key, string $kind, array $intent, ?string $target = null, ?string $lock = null, ?array $specification = null): Operation
    {
        $this->context->assertModel($customer);
        self::validateKey($key);
        $hash = hash('sha256', CanonicalJson::encode([$customer->id, $kind, $target, $specification ?? $intent]));
        try {
            return DB::transaction(function () use ($customer, $key, $kind, $intent, $target, $lock, $hash): Operation {
                app(CommitGuard::class)->assertMayCommit();
                $query = Customer::query()->whereKey($customer->id);
                $query->lockForUpdate();
                $current = $query->firstOrFail();
                $this->context->assertModel($current);
                $existing = Operation::query()->where([...$this->context->attributes(), 'action_key' => $key])->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->intent_hash, $hash)) {
                        throw new ConflictException('Payments command identity has a different original intent.', $existing->id);
                    }

                    return $existing;
                }
                if ($current->disabled_at !== null) {
                    throw new ConflictException('Payments customer is locally offboarded.');
                }
                if ($kind === 'customer.create' && $current->remote_id !== null) {
                    throw new ConflictException('Payments customer association changed; refresh the mapped customer.');
                }

                return Operation::query()->create([
                    ...$this->context->attributes(), 'customer_id' => $customer->id, 'action_key' => $key,
                    'kind' => $kind, 'target_id' => $target, 'intent_hash' => $hash, 'intent' => $intent,
                    'request_id' => (string) Str::uuid(), 'status' => 'prepared', 'lock_key' => $lock ?? 'customer/'.$customer->id,
                ]);
            });
        } catch (QueryException) {
            throw new ConflictException('A concurrent Payments operation holds the reservation.');
        }
    }

    /** @param Closure():array $write @param Closure(array,Operation):mixed $confirm */
    public function execute(Operation $operation, Closure $write, Closure $confirm, ?Closure $beforeClaim = null): mixed
    {
        $this->context->assertModel($operation);
        DB::transaction(function () use ($operation, $beforeClaim): void {
            app(CommitGuard::class)->assertMayCommit();
            $query = Customer::query()->whereKey($operation->customer_id);
            $query->lockForUpdate();
            $customer = $query->firstOrFail();
            $this->context->assertModel($customer);
            if ($customer->disabled_at !== null) {
                throw new ConflictException('Offboarded Payments customer cannot start a new mutation.');
            }
            $beforeClaim?->__invoke();
            if (Operation::query()->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'in_flight']) !== 1) {
                throw new AmbiguousOperationException($operation->id);
            }
        });
        $responded = false;
        try {
            $remote = $write();
            $responded = true;
            if (! is_string($remote['id'] ?? null) || $remote['id'] === '') {
                throw new EvidenceException('Payments response has no resource identity.');
            }
            // Persist identity before any further HTTP or interpretation of the result.
            $operation->refresh()->forceFill(['remote_id' => $remote['id']])->save();
            if ($operation->target_id !== null && $remote['id'] !== $operation->target_id) {
                throw new EvidenceException('Payments mutation response contradicts the original target.');
            }
            $result = $confirm($remote, $operation);
            if ($operation->fresh()->status !== 'acknowledged') {
                $this->succeed($operation);
            }

            return $result;
        } catch (Throwable $exception) {
            $definite = $exception instanceof ValidationException && ! $responded;
            try {
                Operation::query()->whereKey($operation->id)->where('status', 'in_flight')->update([
                    'status' => $definite ? 'failed' : 'ambiguous', 'error_code' => $definite ? 'sdk_validation' : 'reconciliation_required',
                    ...($definite ? ['lock_key' => null] : []),
                ]);
            } catch (Throwable) {
                // The pre-I/O durable row still prohibits mutation replay.
            }
            if ($definite) {
                throw $exception;
            }
            throw new AmbiguousOperationException($operation->id, $exception);
        }
    }

    public function succeed(Operation $operation): void
    {
        $this->context->assertModel($operation);
        app(CommitGuard::class)->assertMayCommit();
        Operation::query()->whereKey($operation->id)->update(['status' => 'succeeded', 'lock_key' => null, 'error_code' => null]);
    }

    public static function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > 255) {
            throw new ConflictException('Payments action key must be a nonempty bounded string.');
        }
    }

    /** No provider mutation has begun; a failed local precondition can release its hold. */
    public function rejectPrepared(Operation $operation): void
    {
        $this->context->assertModel($operation);
        Operation::query()->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'failed', 'lock_key' => null, 'error_code' => 'precondition_failed']);
    }
}
