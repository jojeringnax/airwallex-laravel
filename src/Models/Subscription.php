<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\Contracts\SubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\DefaultSubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Services\InvoiceManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionTransitionManager;

/**
 * @property ?string $starts_at
 * @property ?string $ends_at
 * @property ?string $trial_starts_at
 * @property ?string $trial_ends_at
 * @property ?string $current_period_starts_at
 * @property ?string $current_period_ends_at
 * @property ?string $scheduled_ends_at
 * @property ?string $cancel_requested_at
 * @property ?string $provider_created_at
 * @property ?string $provider_updated_at
 * @property string $customer_id
 * @property string $remote_id
 * @property string $name
 * @property int $revision
 * @property string $status
 * @property ?bool $cancel_at_period_end
 * @property ?array<string,string> $observed_routing
 * @property array<string,mixed> $snapshot
 */
class Subscription extends BillingModel
{
    protected $table = 'airwallex_subscriptions';

    protected $casts = ['observed_routing' => LosslessJson::class, 'snapshot' => LosslessJson::class, 'revision' => 'integer', 'cancel_at_period_end' => 'boolean', 'synced_at' => 'immutable_datetime', 'items_synced_at' => 'immutable_datetime'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'remote_id', 'name'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('customer'), 'customer_id');
    }

    /** @return HasMany<SubscriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ModelRegistry::class('subscription_item'), 'subscription_id');
    }

    public function reconcile(): Subscription
    {
        return app(SubscriptionSynchronizer::class)->reconcile($this);
    }

    public function findInvoice(string $id): Invoice
    {
        return app(InvoiceManager::class)->retrieve($this, $id);
    }

    /** @return Collection<int,Invoice> */
    public function invoices(array $query = []): Collection
    {
        return app(InvoiceManager::class)->all($this, $query);
    }

    public function updateQuantity(string $actionKey, mixed $quantity, array $options, ?string $itemId = null): Subscription
    {
        return app(SubscriptionManager::class)->updateQuantity($this, $actionKey, $quantity, $options, $itemId);
    }

    public function swapPrice(string $actionKey, string $priceId, array $options, ?string $itemId = null, mixed $quantity = null): Subscription
    {
        return app(SubscriptionManager::class)->swapPrice($this, $actionKey, $priceId, $options, $itemId, $quantity);
    }

    public function addItem(string $actionKey, string $priceId, mixed $quantity, array $options): Subscription
    {
        return app(SubscriptionManager::class)->addItem($this, $actionKey, $priceId, $quantity, $options);
    }

    public function removeItem(string $actionKey, string $itemId, array $options): Subscription
    {
        return app(SubscriptionManager::class)->removeItem($this, $actionKey, $itemId, $options);
    }

    public function updateItems(string $actionKey, array $items, array $options = []): Subscription
    {
        return app(SubscriptionManager::class)->updateItems($this, $actionKey, $items, $options);
    }

    public function cancelAtPeriodEnd(string $actionKey, ?array $renewalBounds = null): Subscription
    {
        return app(SubscriptionManager::class)->cancelAtPeriodEnd($this, $actionKey, $renewalBounds);
    }

    public function restoreRenewal(string $actionKey, string $scheduleActionKey): Subscription
    {
        return app(SubscriptionManager::class)->restoreRenewal($this, $actionKey, $scheduleActionKey);
    }

    public function scheduleTransition(string $actionKey, string $effectiveAt, array $items, array $options, string $expiresAt): SubscriptionTransition
    {
        return app(SubscriptionTransitionManager::class)->schedule($this, $actionKey, $effectiveAt, $items, $options, $expiresAt);
    }

    public function cancelNow(string $actionKey, string $prorationBehavior): Subscription
    {
        return app(SubscriptionManager::class)->cancelNow($this, $actionKey, $prorationBehavior);
    }

    public function updatePaymentSource(string $actionKey, string $sourceId): Subscription
    {
        return app(SubscriptionManager::class)->updatePaymentSource($this, $actionKey, $sourceId);
    }

    public function active(): bool
    {
        return $this->status === 'ACTIVE' && (new DefaultSubscriptionAccessPolicy)->allows($this);
    }

    public function onTrial(): bool
    {
        return $this->status === 'IN_TRIAL' && (new DefaultSubscriptionAccessPolicy)->allows($this);
    }

    public function valid(): bool
    {
        return app(SubscriptionAccessPolicy::class)->allows($this);
    }

    public function onGracePeriod(): bool
    {
        return $this->cancel_at_period_end === true && ($this->active() || $this->onTrial());
    }
}
