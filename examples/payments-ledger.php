<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Events\PaymentObserved;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Amount;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Refund;

// Application-owned cumulative ledger example. Supply the expected customer and clearing account from trusted
// order/accounting mappings, never from callback input. These local rows are not a settlement or order-paid promise.
return static function (PaymentObserved $event, string $expectedCustomerId, string $ledgerTable, string $clearingAccount): array {
    $context = app(Context::class);
    if ($event->environment !== $context->environment || $event->accountId !== $context->accountId
        || $clearingAccount === '' || preg_match('/^[a-z][a-z0-9_]{0,48}$/D', $ledgerTable) !== 1) {
        throw new OwnershipException('Application ledger context or trusted clearing mapping is unavailable.');
    }
    $resource = match ($event->resourceType) {
        'airwallex_payment_intents' => Intent::query()->findOrFail($event->resourceId),
        'airwallex_payment_refunds' => Refund::query()->findOrFail($event->resourceId),
        default => throw new OwnershipException('Unsupported application financial resource.'),
    };
    $context->assertModel($resource);
    if ($resource->customer_id !== $expectedCustomerId || $resource->remote_id === null) {
        throw new OwnershipException('Financial observation does not belong to this application payable.');
    }
    $completed = $resource instanceof Intent ? $resource->paid() : $resource->completed();
    if (! $completed) {
        return ['outcome' => 'unfulfilled', 'delta_minor' => '0'];
    }
    $total = Amount::units($resource instanceof Intent ? $resource->captured_amount : $resource->amount, $resource->currency);
    if ($total === '0') {
        return ['outcome' => 'unfulfilled', 'delta_minor' => '0'];
    }
    $kind = $resource instanceof Intent ? 'cash_received' : 'refund_completed';
    $resourceKind = $resource instanceof Intent ? 'payment_intent' : 'refund';
    $identity = hash('sha256', json_encode(['airwallex', $context->environment, $context->accountId, $resourceKind, $resource->remote_id, $kind], JSON_THROW_ON_ERROR));

    return DB::transaction(static function () use ($event, $resource, $ledgerTable, $clearingAccount, $context, $total, $kind, $identity): array {
        DB::table($ledgerTable)->insertOrIgnore(['financial_identity' => $identity, 'provider' => 'airwallex',
            ...$context->attributes(), 'resource_id' => $resource->remote_id, 'invoice_id' => null, 'currency' => $resource->currency,
            'proof_reference' => 'canonical/'.$kind.'/'.$resource->remote_id, 'clearing_account' => $clearingAccount, 'amount_minor' => '0']);
        $state = DB::table($ledgerTable)->where('financial_identity', $identity)->lockForUpdate()->first();
        if ($state === null || $state->currency !== $resource->currency || $state->clearing_account !== $clearingAccount
            || Amount::compare($total, $state->amount_minor) < 0) {
            throw new LogicException('An existing financial identity has contradictory application accounting evidence.');
        }
        if ($total === $state->amount_minor) {
            return ['outcome' => 'duplicate', 'delta_minor' => '0'];
        }
        $delta = Amount::subtract($total, $state->amount_minor);
        DB::table($ledgerTable)->where('financial_identity', $identity)->update(['amount_minor' => $total]);
        DB::table($ledgerTable.'_entries')->insert(['id' => hash('sha256', $identity.'/'.$total), 'financial_identity' => $identity,
            'delivery_id' => $event->id, 'kind' => $kind, 'currency' => $resource->currency, 'delta_minor' => $delta]);

        if ($resource instanceof Refund) {
            // Application handoff only: accounting must authorize tax/order adjustments and issue the credit note.
            DB::table($ledgerTable.'_credit_notes')->insertOrIgnore(['financial_identity' => $identity,
                'refund_id' => $resource->remote_id, 'intent_id' => $resource->intent_id, 'currency' => $resource->currency,
                'amount_minor' => $total, 'status' => 'pending_review']);
        }

        return ['outcome' => 'recorded', 'delta_minor' => $delta, 'kind' => $kind, 'financial_identity' => $identity];
    });
};
