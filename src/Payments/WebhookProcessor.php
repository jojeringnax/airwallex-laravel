<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\MethodObservation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Receipt;
use Jojeringnax\AirwallexLaravel\Payments\Models\Refund;
use Jojeringnax\AirwallexLaravel\Services\ReceiptOutcome;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;

class WebhookProcessor
{
    public function __construct(private readonly Context $context) {}

    public function process(Receipt $receipt): ReceiptOutcome
    {
        $this->context->assertModel($receipt);
        $event = WebhookEnvelope::inspect(SdkResponseShape::webhook(WebhookEvent::fromJson($receipt->body)), $this->context, '2026-08-21');
        if ($event['status'] !== 'pending') {
            return new ReceiptOutcome($event['status'], $event['reason']);
        }
        $id = $event['resourceId'];
        $family = $event['family'];
        $model = match ($family) {
            'payment_intent' => Intent::query()->where($this->context->attributes())->where('remote_id', $id)->first(),
            'refund' => Refund::query()->where($this->context->attributes())->where('remote_id', $id)->first(),
            'payment_consent' => Instrument::query()->where($this->context->attributes())->where('remote_id', $id)->first(),
            'payment_method' => MethodObservation::query()->where($this->context->attributes())->where('remote_id', $id)->first(),
            default => null,
        };
        if ($model !== null) {
            $customer = Customer::query()->where($this->context->attributes())->findOrFail($model->getAttribute('customer_id'));
            $prefix = match ($family) {
                'payment_intent' => 'intent.', 'refund' => 'refund.', 'payment_consent' => 'consent.', default => 'method.',
            };
            $operations = Operation::query()->where($this->context->attributes())->where('customer_id', $customer->id)->where('kind', 'like', $prefix.'%');
            $operations->where(fn ($query) => $query->where('target_id', $id)->orWhere('remote_id', $id));
            $operations->whereIn('status', ['in_flight', 'ambiguous', 'acknowledged'])->whereNotNull('lock_key');
            $operation = $operations->first();
            if ($operation !== null && $family !== 'payment_method') {
                $this->recover($customer, $operation, $id);
            } else {
                match (true) {
                    $model instanceof Intent => app(Intents::class)->reconcile($customer, $model),
                    $model instanceof Refund => app(Refunds::class)->reconcile($customer, $model),
                    $model instanceof Instrument => app(Instruments::class)->complete($customer, $id),
                    default => app(Instruments::class)->reconcileMethod($customer, $id),
                };
            }

            return new ReceiptOutcome('processed', 'canonical_observed');
        }
        // Signed IDs/request IDs are locators. Recovery verifies original intent canonically.
        $requestId = $event['resource']['request_id'] ?? null;
        $kind = match ($family) {
            'payment_intent' => 'intent.create',
            'refund' => 'refund.create',
            'payment_consent' => 'consent.create',
            default => null,
        };
        $operation = null;
        if (is_string($requestId) && $kind !== null) {
            $operations = Operation::query()->where($this->context->attributes())->where('request_id', $requestId)->where('kind', $kind);
            $operations->whereIn('status', ['in_flight', 'ambiguous', 'acknowledged']);
            $operation = $operations->first();
        }
        if ($operation !== null) {
            $customer = Customer::query()->where($this->context->attributes())->findOrFail($operation->customer_id);
            $this->recover($customer, $operation, $id);

            return new ReceiptOutcome('processed', 'recorded_creation_recovered');
        }

        return new ReceiptOutcome('ignored', 'unknown_resource');
    }

    public function recordOutcome(Receipt $receipt, ReceiptOutcome $outcome): void
    {
        app(Observations::class)->record($receipt, ['remote_id' => $receipt->resource_id, 'status' => $outcome->status]);
    }

    private function recover(Customer $customer, Operation $operation, string $id): void
    {
        match (true) {
            str_starts_with($operation->kind, 'intent.') => app(Intents::class)->recover($customer, $operation, $id),
            str_starts_with($operation->kind, 'refund.') => app(Refunds::class)->recover($customer, $operation, $id),
            str_starts_with($operation->kind, 'consent.') => app(Instruments::class)->recover($operation, $id),
            default => throw new OwnershipException('Unsupported Payments webhook operation family.'),
        };
    }
}
