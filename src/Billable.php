<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Jojeringnax\AirwallexLaravel\Concerns\ManagesCustomers;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;

trait Billable
{
    use ManagesCustomers;

    public function importSubscription(string $name, string $remoteId): Subscription
    {
        $customer = app(CustomerManager::class)->requireCustomer($this);

        return app(SubscriptionSynchronizer::class)->import($customer, $name, $remoteId);
    }

    public function airwallexCustomer(): MorphOne
    {
        $relation = $this->morphOne(ModelRegistry::class('customer'), 'billable');
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
        $relation = $this->hasManyThrough(ModelRegistry::class('subscription'), ModelRegistry::class('customer'), 'billable_id', 'customer_id');
        $table = ModelRegistry::make('customer')->getTable();
        $relation
            ->where($table.'.billable_type', $this->getMorphClass())
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

    /** @return array<string,mixed> */
    public function airwallexProfile(): array
    {
        return [];
    }
}
