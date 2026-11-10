<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Services\InvoiceManager;

/**
 * @property string $billable_type
 * @property string $billable_id
 * @property ?string $remote_id
 */
class Customer extends BillingModel
{
    protected $table = 'airwallex_customers';

    protected $casts = ['synced_at' => 'immutable_datetime'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'billable_type', 'billable_id', 'remote_id'];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(ModelRegistry::class('subscription'), 'customer_id');
    }

    /** @return HasMany<SubscriptionSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(ModelRegistry::class('subscription_slot'), 'customer_id');
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
}
