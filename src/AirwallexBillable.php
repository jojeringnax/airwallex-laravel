<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\CheckoutManager;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;
use Jojeringnax\AirwallexLaravel\Services\InvoiceManager;
use Jojeringnax\AirwallexLaravel\Services\SourceManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;

/** Provider-qualified Billing access for an already authorized Eloquent model. */
final class AirwallexBillable
{
    public function __construct(private readonly Model $billable)
    {
        if (! $billable->exists || $billable->getKey() === null) {
            throw new ConflictException('A persisted authorized billable is required.');
        }
    }

    /** @param array<string,mixed> $profile */
    public function createOrGetAirwallexCustomer(string $actionKey, array $profile = []): Customer
    {
        return app(CustomerManager::class)->createOrGet($this->billable, $actionKey, $profile);
    }

    public function linkAirwallexCustomer(string $remoteId): Customer
    {
        return app(CustomerManager::class)->link($this->billable, $remoteId);
    }

    /** @param array<string,mixed> $profile */
    public function syncAirwallexCustomer(string $actionKey, array $profile = []): Customer
    {
        return app(CustomerManager::class)->sync($this->billable, $actionKey, $profile);
    }

    public function newSubscription(string $name, string $priceId): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this->billable, $name, $priceId);
    }

    public function findInvoice(string $id): Invoice
    {
        return app(InvoiceManager::class)->retrieve(app(CustomerManager::class)->requireCustomer($this->billable), $id);
    }

    /** @return Collection<int,Invoice> */
    public function invoices(array $query = []): Collection
    {
        return app(InvoiceManager::class)->all(app(CustomerManager::class)->requireCustomer($this->billable), $query);
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'Airwallex Billing', 'model' => $this->billable::class, 'id' => $this->billable->getKey()];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Billable wrappers cannot be serialized; authorize the model again.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Billable wrappers cannot be restored from serialized data.');
    }

    public function setupPaymentMethod(string $actionKey, array $options = []): Checkout
    {
        return app(CheckoutManager::class)->setup($this->billable, $actionKey, $options);
    }

    public function paymentSources(array $query = []): Collection
    {
        $customer = app(CustomerManager::class)->requireCustomer($this->billable);

        return app(SourceManager::class)->all($customer, $query);
    }

    public function importSubscription(string $name, string $remoteId): Subscription
    {
        $customer = app(CustomerManager::class)->requireCustomer($this->billable);

        return app(SubscriptionSynchronizer::class)->import($customer, $name, $remoteId);
    }

    public function airwallexCustomer(): MorphOne
    {
        $relation = $this->billable->morphOne(ModelRegistry::class('customer'), 'billable');
        $relation->where(app(BillingContext::class)->attributes());

        return $relation;
    }

    public function customerMapping(): ?Customer
    {
        /** @var Customer|null */
        return $this->airwallexCustomer()->first();
    }

    public function subscriptions(): HasManyThrough
    {
        $context = app(BillingContext::class);
        $relation = $this->billable->hasManyThrough(ModelRegistry::class('subscription'), ModelRegistry::class('customer'), 'billable_id', 'customer_id');
        $table = ModelRegistry::make('customer')->getTable();
        $relation
            ->where($table.'.billable_type', $this->billable->getMorphClass())
            ->where($table.'.environment', $context->environment)
            ->where($table.'.organization_id', $context->organizationId);

        return $relation;
    }

    public function subscription(string $name): ?Subscription
    {
        $customer = $this->customerMapping();
        if (! $customer) {
            return null;
        }
        $slot = $customer->slots()->where('name', $name)->first();
        if ($slot?->subscription_id) {
            /** @var Subscription|null */
            $subscription = $slot->subscription;

            return $subscription instanceof Subscription && $subscription->name === $name ? $subscription : null;
        }

        /** @var Subscription|null */
        return $customer->subscriptions()->where('name', $name)->orderByDesc('id')->first();
    }

    public function subscribed(string $name): bool
    {
        $subscription = $this->customerMapping()?->slots()->where('name', $name)->first()?->subscription;

        return $subscription instanceof Subscription && $subscription->name === $name && $subscription->valid();
    }
}
