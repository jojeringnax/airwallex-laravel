<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Support\BillingWebhookEnvelope;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;

class BillingWebhookProcessor
{
    public function __construct(private readonly BillingContext $context) {}

    /** Canonical reads only; worker supplies CommitGuard and owns receipt finalization. */
    public function process(WebhookReceipt $receipt): ReceiptOutcome
    {
        $this->context->assertModel($receipt);
        if (! is_string($receipt->body) || ! hash_equals($receipt->digest, hash('sha256', $receipt->body))) {
            return new ReceiptOutcome('quarantined', 'missing_or_changed_body');
        }
        try {
            $envelope = BillingWebhookEnvelope::inspect(SdkResponseShape::webhook(WebhookEvent::fromJson($receipt->body)), $this->context, config('airwallex.webhook.version', '2026-08-21'));
        } catch (ProtocolException) {
            return new ReceiptOutcome('quarantined', 'malformed_envelope');
        }
        if ($envelope['eventId'] !== $receipt->event_id || $envelope['eventName'] !== $receipt->event_name || $envelope['version'] !== $receipt->version || $envelope['resourceId'] !== $receipt->resource_id) {
            return new ReceiptOutcome('quarantined', 'receipt_identity_conflict');
        }
        if ($envelope['status'] !== 'pending') {
            return new ReceiptOutcome($envelope['status'], $envelope['reason']);
        }
        try {
            return match ($envelope['family']) {
                'subscription' => $this->subscription($envelope['resource']),
                'billing_checkout' => $this->checkout($envelope['resource']),
                'invoice' => $this->invoice($envelope['resource']),
                default => new ReceiptOutcome('ignored', 'unsupported_event'),
            };
        } catch (OwnershipException) {
            return new ReceiptOutcome('quarantined', 'resource_ownership_conflict');
        } catch (AmbiguousOperationException $exception) {
            if ($exception->causeCategory === 'ownership') {
                return new ReceiptOutcome('quarantined', 'resource_ownership_conflict');
            }
            throw $exception;
        }
    }

    /** Called only in the worker's lease-fenced receipt completion transaction. */
    public function recordOutcome(WebhookReceipt $receipt, ReceiptOutcome $outcome): void
    {
        $this->context->assertModel($receipt);
        if (DB::transactionLevel() < 1 || ! in_array($outcome->status, ['processed', 'ignored', 'quarantined'], true)) {
            throw new ConflictException('Webhook outcomes require transactional terminal receipt finalization.');
        }
        $outbox = app(Outbox::class);
        foreach ($outcome->observations as $observation) {
            $customer = ModelRegistry::query('customer')->where($this->context->attributes())->findOrFail($observation['customerId']);
            $outbox->record('InvoiceObserved', $customer, ['remote_id' => $observation['remoteId'], 'status' => $observation['status'], 'payment_status' => $observation['paymentStatus'], 'subscription_id' => $observation['subscriptionId'], 'receipt_id' => $receipt->id, 'provider_event_id' => $receipt->event_id]);
        }
        $outbox->record('WebhookProcessed', $receipt, ['receipt_id' => $receipt->id, 'provider_event_id' => $receipt->event_id, 'event_name' => $receipt->event_name, 'resource_id' => $receipt->resource_id, 'outcome' => $outcome->status, 'reason' => $outcome->reason]);
    }

    private function subscription(array $resource): ReceiptOutcome
    {
        $subscription = ModelRegistry::query('subscription')->where($this->context->attributes())->where('remote_id', $resource['id'])->first();
        if ($subscription) {
            $customer = ModelRegistry::query('customer')->findOrFail($subscription->customer_id);
            $this->assertEventCustomer($resource, $customer->remote_id);
            app(SubscriptionSynchronizer::class)->reconcile($subscription);

            return new ReceiptOutcome;
        }
        $operation = $this->correlatedOperation($resource);
        if (! $operation) {
            return new ReceiptOutcome('ignored', 'unknown_resource');
        }
        if (app(CreationRetries::class)->quarantined($operation, $resource['id'])) {
            return new ReceiptOutcome('quarantined', 'superseded_creation_locator');
        }
        if ($operation->kind === 'subscription.create') {
            app(SubscriptionManager::class)->recoverCreation($operation, $resource['id']);

            return new ReceiptOutcome;
        }
        if ($operation->kind === 'checkout.subscription') {
            if ($operation->remote_id === null) {
                return new ReceiptOutcome('ignored', 'checkout_identity_needed');
            }
            $checkout = app(CheckoutManager::class)->recover($operation);
            if ($checkout->remote_subscription_id !== $resource['id']) {
                throw new OwnershipException('Subscription notification conflicts with its checkout result.');
            }

            return new ReceiptOutcome;
        }

        return new ReceiptOutcome('ignored', 'unrelated_operation');
    }

    private function checkout(array $resource): ReceiptOutcome
    {
        $checkout = ModelRegistry::query('checkout')->where($this->context->attributes())->where('remote_id', $resource['id'])->first();
        $operation = $checkout ? ModelRegistry::query('operation')->findOrFail($checkout->operation_id) : $this->correlatedOperation($resource);
        if (! $operation || ! in_array($operation->kind, ['checkout.subscription', 'checkout.setup'], true)) {
            return new ReceiptOutcome('ignored', 'unknown_resource');
        }
        if (app(CreationRetries::class)->quarantined($operation, $resource['id'])) {
            return new ReceiptOutcome('quarantined', 'superseded_creation_locator');
        }
        if (! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            return new ReceiptOutcome('quarantined', 'unattempted_operation');
        }
        $customer = ModelRegistry::query('customer')->findOrFail($operation->customer_id);
        $this->assertEventCustomer($resource, $customer->remote_id);
        app(CheckoutManager::class)->recover($operation, $resource['id']);

        return new ReceiptOutcome;
    }

    private function invoice(array $resource): ReceiptOutcome
    {
        $customerId = $resource['billing_customer_id'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            return new ReceiptOutcome('quarantined', 'malformed_resource');
        }
        $customer = ModelRegistry::query('customer')->where($this->context->attributes())->where('remote_id', $customerId)->first();
        if (! $customer) {
            return new ReceiptOutcome('ignored', 'unknown_customer');
        }
        $invoice = app(InvoiceManager::class)->observeForWebhook($customer, $resource['id']);
        $remote = $invoice->toArray();
        $subscriptionId = $invoice->subscriptionId;
        if ($subscriptionId !== null) {
            $subscription = ModelRegistry::query('subscription')->where($this->context->attributes())->where('remote_id', $subscriptionId)->first();
            if ($subscription) {
                if ($subscription->customer_id !== $customer->id) {
                    throw new OwnershipException('Invoice subscription belongs to another customer.');
                }
                app(SubscriptionSynchronizer::class)->reconcile($subscription);
            }
        }

        return new ReceiptOutcome(observations: [['customerId' => $customer->id, 'remoteId' => $remote['id'], 'status' => $remote['status'], 'paymentStatus' => $remote['payment_status'] ?? null, 'subscriptionId' => $subscriptionId]]);
    }

    private function correlatedOperation(array $resource): ?Operation
    {
        $metadata = $resource['metadata'] ?? null;
        $id = is_array($metadata) ? ($metadata['_laravel_operation_id'] ?? null) : null;
        if (! is_string($id) || $id === '') {
            return null;
        }
        $operation = ModelRegistry::query('operation')->where($this->context->attributes())->find($id);
        if (! $operation || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded', 'superseded'], true)) {
            return null;
        }
        $customer = ModelRegistry::query('customer')->findOrFail($operation->customer_id);
        $this->assertEventCustomer($resource, $customer->remote_id);

        return $operation;
    }

    private function assertEventCustomer(array $resource, ?string $expected): void
    {
        if (array_key_exists('billing_customer_id', $resource) && $resource['billing_customer_id'] !== $expected) {
            throw new OwnershipException('Notification customer conflicts with its owned mapping.');
        }
    }
}
