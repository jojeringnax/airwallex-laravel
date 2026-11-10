<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jojeringnax\AirwallexLaravel\ModelRegistry;

/**
 * @property string $customer_id
 * @property string $name
 * @property ?string $subscription_id
 * @property ?string $operation_id
 * @property ?Subscription $subscription
 */
class SubscriptionSlot extends BillingModel
{
    protected $table = 'airwallex_subscription_slots';

    protected function immutable(): array
    {
        return ['customer_id', 'name'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('subscription'), 'subscription_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('operation'), 'operation_id');
    }
}
