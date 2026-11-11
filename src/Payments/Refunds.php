<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Refund;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Jojeringnax\AirwallexLaravel\Timestamp;
use Throwable;

class Refunds
{
    public function __construct(private readonly Context $context, private readonly Customers $customers, private readonly Intents $intents, private readonly Operations $operations, private readonly Observations $observations) {}

    public function create(Customer $customer, Intent $intent, string $key, mixed $amount, array $options = []): Refund
    {
        $customer = $this->customers->current($customer);
        $intent = $this->intents->owned($customer, $intent);
        $amount = Amount::canonical($amount, $intent->currency);
        if (Amount::units($amount, $intent->currency) === '0' || $intent->remote_id === null
            || array_diff(array_keys($options), ['reason', 'metadata']) !== []) {
            throw new ConflictException('A positive explicit refund on an owned confirmed intent is required; targets/currency/request identity cannot be overridden.');
        }
        $body = [...$options, 'amount' => $amount, 'payment_intent_id' => $intent->remote_id];
        try {
            [$operation, $refund] = DB::transaction(function () use ($customer, $intent, $key, $body, $amount): array {
                $operation = $this->operations->prepare($customer, $key, 'refund.create', $body);
                $refund = Refund::query()->firstOrCreate(['operation_id' => $operation->id], [
                    ...$this->context->attributes(), 'customer_id' => $customer->id, 'intent_id' => $intent->id, 'amount' => $amount, 'currency' => $intent->currency,
                ]);
                if ($refund->customer_id !== $customer->id || $refund->intent_id !== $intent->id || $refund->amount !== $amount || $refund->currency !== $intent->currency) {
                    throw new OwnershipException('Refund command is already associated with a different original payment.');
                }

                return [$operation, $refund];
            });
        } catch (QueryException) {
            throw new ConflictException('A concurrent refund holds the original payment reservation.');
        }
        if ($operation->status !== 'prepared') {
            return $this->recover($customer, $operation);
        }
        try {
            $canonical = $this->intents->reconcile($customer, $intent);
            $remote = $this->allRemote($canonical);
            $check = function () use ($customer, $canonical, $remote, $refund): void {
                $current = $this->intents->owned($customer, $canonical);
                if ($current->revision !== $canonical->revision || ! $current->paid()) {
                    throw new ConflictException('Refund requires an unchanged canonical successful capture.');
                }
                $available = $this->availableUnits($current, $remote, $refund->id);
                if (Amount::compare(Amount::units($refund->amount, $refund->currency), $available) > 0) {
                    throw new ConflictException('Refund exceeds the conservatively observed unreserved captured amount.');
                }
            };
            DB::transaction(function () use ($customer, $check): void {
                $this->lockCustomer($customer);
                $check();
            });

            return $this->operations->execute($operation,
                fn () => app(Client::class)->sdk->payments()->refunds()->create([...$operation->intent, 'request_id' => $operation->request_id]),
                fn (array $response) => $this->confirm($customer, $refund, $operation, $response['id']),
                $check,
            );
        } catch (Throwable $error) {
            // Only an unattempted local precondition can release its financial mutex.
            $this->operations->rejectPrepared($operation);
            throw $error;
        }
    }

    public function refundableAmount(Customer $customer, Intent $intent): string
    {
        $customer = $this->customers->current($customer);
        $intent = $this->intents->reconcile($customer, $this->intents->owned($customer, $intent));
        $remote = $this->allRemote($intent);

        return DB::transaction(function () use ($customer, $intent, $remote): string {
            $this->lockCustomer($customer);
            $current = $this->intents->owned($customer, $intent);
            if ($current->revision !== $intent->revision || ! $current->paid()) {
                throw new EvidenceException('Refundable amount requires fresh positive captured evidence.');
            }

            return Amount::fromMinor($this->availableUnits($current, $remote), $current->currency);
        });
    }

    public function reconcile(Customer $customer, Refund $refund): Refund
    {
        [$customer, $refund, $intent] = $this->owned($customer, $refund);
        if ($refund->remote_id === null) {
            throw new ConflictException('Recover the original refund operation before reconciliation.', $refund->operation_id);
        }
        $intent = $this->intents->reconcile($customer, $intent);
        $operation = Operation::query()->findOrFail($refund->operation_id);

        return $this->observe($customer, $refund, $intent, $operation, $refund->remote_id);
    }

    public function recover(Customer $customer, Operation $operation, ?string $knownId = null): Refund
    {
        $this->context->assertModel($operation);
        $operation = Operation::query()->findOrFail($operation->id);
        $this->context->assertModel($operation);
        $customer = $this->customers->current($customer);
        if ($operation->customer_id !== $customer->id || $operation->kind !== 'refund.create') {
            throw new OwnershipException('Refund operation does not belong to this Payments customer.');
        }
        if (! in_array($operation->status, ['in_flight', 'ambiguous', 'acknowledged', 'succeeded'], true)) {
            throw new ConflictException('Read recovery cannot submit an unattempted or rejected refund.', $operation->id);
        }
        $refund = Refund::query()->where('operation_id', $operation->id)->firstOrFail();
        $id = $operation->remote_id ?? $refund->remote_id ?? $knownId;
        if ($id === null) {
            throw new AmbiguousOperationException($operation->id);
        }
        if ($knownId !== null && $id !== $knownId) {
            throw new OwnershipException('Refund recovery cannot replace its original recorded identity.');
        }
        try {
            $result = $this->confirm($customer, $refund, $operation, $id);
            if ($operation->remote_id === null) {
                $operation->remote_id = $id;
                $operation->save();
            }
            $this->operations->succeed($operation);

            return $result;
        } catch (Throwable $error) {
            throw new AmbiguousOperationException($operation->id, $error);
        }
    }

    private function confirm(Customer $customer, Refund $refund, Operation $operation, string $id): Refund
    {
        [$customer, $refund, $intent] = $this->owned($customer, $refund);
        $intent = $this->intents->reconcile($customer, $intent);

        return $this->observe($customer, $refund, $intent, $operation, $id);
    }

    private function observe(Customer $customer, Refund $refund, Intent $intent, Operation $operation, string $id): Refund
    {
        $remote = app(Client::class)->sdk->payments()->refunds()->retrieve($id);
        Proof::identity($remote, $id, $this->context);
        $this->proof($remote, $intent);
        if (($remote['request_id'] ?? null) !== $operation->request_id
            || Amount::units($remote['amount'], $intent->currency) !== Amount::units($refund->amount, $intent->currency)) {
            throw new EvidenceException('Canonical refund does not correlate to the original request and exact reserved amount.');
        }

        return DB::transaction(function () use ($customer, $refund, $intent, $remote, $id): Refund {
            $this->lockCustomer($customer);
            [, $current, $currentIntent] = $this->owned($customer, $refund);
            if ($current->revision !== $refund->revision || $currentIntent->revision !== $intent->revision
                || ($current->remote_id !== null && $current->remote_id !== $id)) {
                throw new ConflictException('Refund or its original payment changed during canonical observation.');
            }
            $previous = $current->snapshot['updated_at'] ?? null;
            if (is_string($previous)) {
                $order = Timestamp::compare($remote['updated_at'], $previous);
                if ($order < 0 || ($order === 0 && $current->status !== $remote['status'])) {
                    throw new EvidenceException('A stale or contradictory refund observation cannot replace retained evidence.');
                }
            }
            $current->forceFill(['remote_id' => $id, 'status' => $remote['status'], 'snapshot' => SubscriptionSynchronizer::sanitize($remote), 'revision' => $current->revision + 1])->save();
            $this->observations->record($current, ['remote_id' => $id, 'status' => $current->status, 'amount' => $current->amount, 'currency' => $current->currency,
                'payment_intent_id' => $currentIntent->remote_id, 'financial_identity' => 'refund/'.$id]);

            return $current->refresh();
        });
    }

    /** @return array{Customer,Refund,Intent} */
    private function owned(Customer $customer, Refund $refund): array
    {
        $this->context->assertModel($refund);
        $customer = $this->customers->current($customer);
        $current = Refund::query()->findOrFail($refund->id);
        $this->context->assertModel($current);
        if ($current->customer_id !== $customer->id) {
            throw new OwnershipException('Refund belongs to a different Payments customer.');
        }
        $intent = $this->intents->owned($customer, Intent::query()->findOrFail($current->intent_id));

        return [$customer, $current, $intent];
    }

    /** @return list<array> */
    private function allRemote(Intent $intent): array
    {
        $items = [];
        $seen = [];
        for ($page = 0; $page < 10000; $page++) {
            $remote = app(Client::class)->sdk->payments()->refunds()->list(['payment_intent_id' => $intent->remote_id, 'page_num' => $page, 'page_size' => 100]);
            if (! is_array($remote['items'] ?? null) || ! array_is_list($remote['items']) || ! is_bool($remote['has_more'] ?? null)
                || ($remote['has_more'] && $remote['items'] === [])) {
                throw new EvidenceException('Refund pagination is incomplete or malformed.');
            }
            foreach ($remote['items'] as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || isset($seen[$row['id']])) {
                    throw new EvidenceException('Refund pagination contains malformed or duplicate identities.');
                }
                $this->proof($row, $intent);
                $seen[$row['id']] = true;
                $items[] = $row;
            }
            if (! $remote['has_more']) {
                return $items;
            }
        }
        throw new EvidenceException('Refund pagination exceeded the bounded verification budget.');
    }

    private function proof(array $remote, Intent $intent): void
    {
        Proof::identity($remote, $remote['id'] ?? '', $this->context);
        $intentId = $remote['payment_intent_id'] ?? null;
        if (array_key_exists('payment_intent_id', $remote) && $intentId !== $intent->remote_id) {
            throw new OwnershipException('Refund does not belong to its original Payments intent.');
        }
        $attemptId = $remote['payment_attempt_id'] ?? null;
        if (array_key_exists('payment_attempt_id', $remote)) {
            if (! is_string($attemptId) || $attemptId === '') {
                throw new EvidenceException('Refund attempt linkage is malformed.');
            }
            $attempt = app(Client::class)->sdk->payments()->paymentAttempts()->retrieve($attemptId);
            Proof::identity($attempt, $attemptId, $this->context);
            if (($attempt['payment_intent_id'] ?? null) !== $intent->remote_id
                || (array_key_exists('currency', $attempt) && $attempt['currency'] !== $intent->currency)) {
                throw new OwnershipException('Refund attempt belongs to a different payment or currency.');
            }
        } elseif ($intentId === null) {
            throw new EvidenceException('Refund has no provable original payment linkage.');
        }
        if (($remote['currency'] ?? null) !== $intent->currency) {
            throw new OwnershipException('Refund currency contradicts the original payment.');
        }
        Amount::units($remote['amount'] ?? null, $intent->currency);
        if (! is_string($remote['status'] ?? null) || $remote['status'] === '' || ! is_string($remote['updated_at'] ?? null)) {
            throw new EvidenceException('Refund status or versioned timing is missing.');
        }
        Timestamp::compare($remote['updated_at'], $remote['updated_at']);
    }

    /** Captured minus all nonfailed remote refunds and unseen local obligations, in exact minor units. */
    private function availableUnits(Intent $intent, array $remote, ?string $excluding = null): string
    {
        if (! $intent->paid()) {
            throw new EvidenceException('No positive successful captured amount is available for a refund.');
        }
        $reserved = '0';
        $seen = [];
        foreach ($remote as $row) {
            $seen[$row['id']] = $row;
            if ($row['status'] !== 'FAILED') {
                $reserved = Amount::add($reserved, Amount::units($row['amount'], $intent->currency));
            }
        }
        foreach (Refund::query()->where('intent_id', $intent->id)->get() as $local) {
            $operation = Operation::query()->findOrFail($local->operation_id);
            if ($local->id === $excluding || $local->status === 'FAILED' || $operation->status === 'failed') {
                continue;
            }
            if ($local->remote_id !== null && isset($seen[$local->remote_id])) {
                $observation = $seen[$local->remote_id];
                $previous = $local->snapshot['updated_at'] ?? null;
                if (is_string($previous) && (Timestamp::compare($observation['updated_at'], $previous) < 0
                    || (Timestamp::compare($observation['updated_at'], $previous) === 0 && $observation['status'] !== $local->status))) {
                    throw new EvidenceException('A stale refund page cannot release a newer retained reservation.');
                }
                if (Amount::units($observation['amount'], $intent->currency) !== Amount::units($local->amount, $intent->currency)
                    || (array_key_exists('request_id', $observation) && $observation['request_id'] !== $operation->request_id)) {
                    throw new EvidenceException('Remote refund total contradicts the original reserved amount.');
                }

                continue;
            }
            $reserved = Amount::add($reserved, Amount::units($local->amount, $intent->currency));
        }
        $captured = Amount::units($intent->captured_amount, $intent->currency);

        return Amount::compare($reserved, $captured) >= 0 ? '0' : Amount::subtract($captured, $reserved);
    }

    private function lockCustomer(Customer $customer): void
    {
        app(CommitGuard::class)->assertMayCommit();
        $query = Customer::query()->whereKey($customer->id);
        $query->lockForUpdate();
        $this->context->assertModel($query->firstOrFail());
    }
}
