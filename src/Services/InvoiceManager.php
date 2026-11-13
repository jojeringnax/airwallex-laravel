<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Resource\Billing;
use Jojeringnax\Airwallex\Resource\Billing\Transactions;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\InvoiceTransaction;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Support\Pages;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;

class InvoiceManager
{
    public function __construct(private readonly Client $client, private readonly BillingContext $context, private readonly Outbox $outbox, private readonly CommitGuard $commitGuard) {}

    public function retrieve(Customer|Subscription $owner, string $id): Invoice
    {
        [$customer, $subscription] = $this->owner($owner);
        $remote = SdkResponseShape::normalize($this->client->billing()->invoices()->retrieve($id));
        $this->verify($customer, $subscription, $remote, $id);

        return $this->observed($owner, [$remote])->first();
    }

    /** @internal Canonical verification without events; receipt finalization owns its fenced outbox write. */
    public function observeForWebhook(Customer $customer, string $id): Invoice
    {
        [$current] = $this->owner($customer);
        $remote = SdkResponseShape::normalize($this->client->billing()->invoices()->retrieve($id));
        $this->verify($current, null, $remote, $id);
        [$current] = $this->owner($customer);
        $this->verify($current, null, $remote, $id);

        return $this->invoice($current, null, $remote);
    }

    /** @return Collection<int,Invoice> */
    public function all(Customer|Subscription $owner, array $query = []): Collection
    {
        [$customer, $subscription] = $this->owner($owner);
        $scope = ['billing_customer_id' => $customer->remote_id];
        if ($subscription !== null) {
            $scope['subscription_id'] = $subscription->remote_id;
        }
        foreach ($scope as $key => $value) {
            if (array_key_exists($key, $query) && $query[$key] !== $value) {
                throw new OwnershipException('Invoice filters cannot change the mapped customer or subscription.');
            }
        }
        $query = [...$query, ...$scope];
        $remotes = Pages::all(fn (array $page) => $this->client->billing()->invoices()->list($page), $query);
        foreach ($remotes as $remote) {
            $this->verify($customer, $subscription, $remote);
            if (array_key_exists('subscription_id', $query) && ($remote['subscription_id'] ?? null) !== $query['subscription_id']) {
                throw new OwnershipException('Invoice does not match the requested subscription filter.');
            }
        }

        return $this->observed($owner, $remotes);
    }

    /** @return Collection<int,array<array-key,mixed>> */
    public function lineItems(Invoice $invoice, array $query = []): Collection
    {
        [$owner, $remote] = $this->parent($invoice);
        $items = Pages::all(fn (array $page) => $this->client->billing()->invoices()->listLineItems($invoice->id, $page), $query);
        foreach ($items as $item) {
            $this->verifyLine($invoice, $item);
        }
        $this->observed($owner, [$remote]);

        return collect(array_map(SubscriptionSynchronizer::sanitize(...), $items));
    }

    /** @return array<array-key,mixed> */
    public function lineItem(Invoice $invoice, string $id): array
    {
        [$owner, $remote] = $this->parent($invoice);
        $item = SdkResponseShape::normalize($this->client->billing()->invoices()->retrieveLineItem($invoice->id, $id));
        $this->verifyLine($invoice, $item, $id);
        $this->observed($owner, [$remote]);

        return SubscriptionSynchronizer::sanitize($item);
    }

    /** @return Collection<int,InvoiceTransaction> */
    public function transactions(Invoice $invoice, array $query = []): Collection
    {
        $resource = $this->transactionsResource($this->client->billing());
        if ((array_key_exists('invoice_id', $query) && $query['invoice_id'] !== $invoice->id) || array_key_exists('credit_note_id', $query)) {
            throw new OwnershipException('Transaction filters cannot replace the owned invoice.');
        }
        [$owner, $remote] = $this->parent($invoice);
        Routing::assertSubscription($remote);
        $items = Pages::all(fn (array $page) => $resource->list($page), [...$query, 'invoice_id' => $invoice->id]);
        $transactions = [];
        foreach ($items as $item) {
            $transactions[] = $this->ownedTransaction($invoice, $item);
        }
        $this->observed($owner, [$remote]);

        return collect($transactions);
    }

    public function transaction(Invoice $invoice, string $id): InvoiceTransaction
    {
        $resource = $this->transactionsResource($this->client->billing());
        [$owner, $remote] = $this->parent($invoice);
        Routing::assertSubscription($remote);
        $transaction = $this->ownedTransaction($invoice, SdkResponseShape::normalize($resource->retrieve($id)), $id);
        $this->observed($owner, [$remote]);

        return $transaction;
    }

    private function transactionsResource(Billing $billing): Transactions
    {
        return $billing->transactions();
    }

    private function ownedTransaction(Invoice $invoice, array $remote, ?string $expectedId = null): InvoiceTransaction
    {
        if (! is_string($remote['id'] ?? null) || $remote['id'] === '' || ($expectedId !== null && $remote['id'] !== $expectedId)
            || ($remote['invoice_id'] ?? null) !== $invoice->id || ($remote['billing_customer_id'] ?? null) !== $invoice->billingCustomerId) {
            throw new OwnershipException('Transaction identity or invoice/customer linkage is not proven.');
        }
        $this->remoteContext($remote);
        Routing::assertSubscription($remote);
        foreach (['amount', 'currency', 'status', 'created_at', 'updated_at'] as $key) {
            if (! is_string($remote[$key] ?? null) || $remote[$key] === '') {
                throw new EvidenceException('Transaction amount, currency, status or timing is malformed.');
            }
        }
        foreach (['created_at', 'updated_at', 'succeeded_at', 'cancelled_at'] as $key) {
            if (array_key_exists($key, $remote) && $remote[$key] !== null) {
                if (! is_string($remote[$key])) {
                    throw new EvidenceException('Transaction timing is malformed.');
                }
                Timestamp::compare($remote[$key], $remote[$key]);
            }
        }
        foreach (['type', 'external_id', 'payment_source_id', 'credit_note_id'] as $key) {
            if (array_key_exists($key, $remote) && (! is_string($remote[$key]) || $remote[$key] === '')) {
                throw new EvidenceException('Transaction optional linkage or classification is malformed.');
            }
        }
        if (array_key_exists('out_of_band', $remote) && ! is_bool($remote['out_of_band'])) {
            throw new EvidenceException('Transaction out-of-band classification is malformed.');
        }

        return new InvoiceTransaction($remote['id'], $invoice->id, $invoice->billingCustomerId, $remote['amount'], $remote['currency'], $remote['status'], $remote['type'] ?? null, $remote['external_id'] ?? null, $remote['linked_payment_account_id'] ?? null, $remote['out_of_band'] ?? null, $this->context, SubscriptionSynchronizer::sanitize($remote));
    }

    /** @return array{Customer,?Subscription} */
    private function owner(Customer|Subscription $owner): array
    {
        $this->context->assertModel($owner);
        if (! $owner->exists) {
            throw new ConflictException('An existing owned billing mapping is required.');
        }
        $subscription = null;
        $customerId = $owner->getKey();
        if ($owner instanceof Subscription) {
            $subscription = ModelRegistry::query('subscription')->find($owner->getKey());
            if (! $subscription) {
                throw new OwnershipException('Subscription mapping is no longer available.');
            }
            $this->context->assertModel($subscription);
            $customerId = $subscription->customer_id;
        }
        $customer = ModelRegistry::query('customer')->find($customerId);
        if (! $customer) {
            throw new OwnershipException('Customer mapping is no longer available.');
        }
        $this->context->assertModel($customer);
        if (! is_string($customer->remote_id) || $customer->remote_id === '') {
            throw new ConflictException('A confirmed customer mapping is required.');
        }

        return [$customer, $subscription];
    }

    /** @return array{Customer|Subscription,array<array-key,mixed>} */
    private function parent(Invoice $invoice): array
    {
        [$context, $customerId, $subscriptionId] = $invoice->scope();
        if ($context->attributes() !== $this->context->attributes()) {
            throw new OwnershipException('Invoice observation belongs to a different context.');
        }
        $owner = $subscriptionId === null
            ? ModelRegistry::query('customer')->find($customerId)
            : ModelRegistry::query('subscription')->find($subscriptionId);
        if (! $owner) {
            throw new OwnershipException('Invoice owning mapping is no longer available.');
        }
        [$customer, $subscription] = $this->owner($owner);
        if ($customer->id !== $customerId || $customer->remote_id !== $invoice->billingCustomerId) {
            throw new OwnershipException('Invoice owning mapping no longer matches.');
        }
        $remote = SdkResponseShape::normalize($this->client->billing()->invoices()->retrieve($invoice->id));
        $this->verify($customer, $subscription, $remote, $invoice->id);
        if (($remote['subscription_id'] ?? null) !== $invoice->subscriptionId) {
            throw new OwnershipException('Invoice subscription identity changed.');
        }

        return [$owner, $remote];
    }

    /** @param list<array<array-key,mixed>> $remotes @return Collection<int,Invoice> */
    private function observed(Customer|Subscription $owner, array $remotes): Collection
    {
        return DB::transaction(function () use ($owner, $remotes): Collection {
            $this->commitGuard->assertMayCommit();
            // Recheck the current configured database owner after all remote I/O.
            [$customer, $subscription] = $this->owner($owner);
            $invoices = [];
            foreach ($remotes as $remote) {
                $this->verify($customer, $subscription, $remote);
                $invoice = $this->invoice($customer, $subscription, $remote);
                $invoices[] = $invoice;
                $this->outbox->record('InvoiceObserved', $subscription ?? $customer, [
                    'remote_id' => $invoice->id,
                    'subscription_id' => $invoice->subscriptionId,
                    'status' => $remote['status'],
                    'payment_status' => $remote['payment_status'],
                ]);
            }

            return collect($invoices);
        });
    }

    private function invoice(Customer $customer, ?Subscription $subscription, array $remote): Invoice
    {
        return new Invoice($remote['id'], $remote['billing_customer_id'], $remote['subscription_id'] ?? null, $this->context, $customer->id, $subscription?->id, SubscriptionSynchronizer::sanitize($remote));
    }

    private function verify(Customer $customer, ?Subscription $subscription, array $remote, ?string $expectedId = null): void
    {
        if (! is_string($remote['id'] ?? null) || $remote['id'] === '' || ($expectedId !== null && $remote['id'] !== $expectedId)
            || ($remote['billing_customer_id'] ?? null) !== $customer->remote_id
            || ($subscription !== null && ($remote['subscription_id'] ?? null) !== $subscription->remote_id)) {
            throw new OwnershipException('Invoice identity or customer/subscription ownership does not match.');
        }
        $this->remoteContext($remote);
        foreach (['status', 'payment_status', 'currency'] as $key) {
            if (! is_string($remote[$key] ?? null) || $remote[$key] === '') {
                throw new EvidenceException('Invoice status or currency is malformed.');
            }
        }
        if (isset($remote['subscription_id']) && (! is_string($remote['subscription_id']) || $remote['subscription_id'] === '')) {
            throw new EvidenceException('Invoice subscription identity is malformed.');
        }
        if (isset($remote['subscription_id'])) {
            $known = ModelRegistry::query('subscription')->where([
                ...$this->context->attributes(), 'remote_id' => $remote['subscription_id'],
            ])->first();
            if ($known !== null && $known->customer_id !== $customer->id) {
                throw new OwnershipException('Invoice subscription conflicts with a known customer mapping.');
            }
        }
        foreach (['pdf_url', 'hosted_url'] as $key) {
            if (isset($remote[$key]) && ! is_string($remote[$key])) {
                throw new EvidenceException('Invoice document link is malformed.');
            }
        }
    }

    private function verifyLine(Invoice $invoice, array $remote, ?string $expectedId = null): void
    {
        if (! is_string($remote['id'] ?? null) || $remote['id'] === '' || ($expectedId !== null && $remote['id'] !== $expectedId)
            || ($remote['invoice_id'] ?? null) !== $invoice->id
            || (array_key_exists('billing_customer_id', $remote) && $remote['billing_customer_id'] !== $invoice->billingCustomerId)
            || (array_key_exists('subscription_id', $remote) && $remote['subscription_id'] !== $invoice->subscriptionId)) {
            throw new OwnershipException('Invoice line identity or parent ownership does not match.');
        }
        $this->remoteContext($remote);
    }

    private function remoteContext(array $remote): void
    {
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $remote) && $remote[$key] !== $expected) {
                throw new OwnershipException('Invoice resource belongs to a foreign context.');
            }
        }
    }
}
