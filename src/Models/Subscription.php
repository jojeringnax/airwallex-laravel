<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\Contracts\SubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\DefaultSubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\ModelRegistry;

/**
 * @property ?string $starts_at
 * @property ?string $ends_at
 * @property ?string $trial_starts_at
 * @property ?string $trial_ends_at
 * @property ?string $current_period_starts_at
 * @property ?string $current_period_ends_at
 * @property ?string $cancel_requested_at
 * @property ?string $provider_created_at
 * @property ?string $provider_updated_at
 * @property string $customer_id
 * @property string $remote_id
 * @property string $name
 * @property string $status
 * @property ?bool $cancel_at_period_end
 * @property array<string,mixed> $snapshot
 */
class Subscription extends BillingModel
{
    protected $table = 'airwallex_subscriptions';

    protected $casts = ['snapshot' => LosslessJson::class, 'cancel_at_period_end' => 'boolean', 'synced_at' => 'immutable_datetime', 'items_synced_at' => 'immutable_datetime'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'remote_id', 'name'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('customer'), 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ModelRegistry::class('subscription_item'), 'subscription_id');
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
