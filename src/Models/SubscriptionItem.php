<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\ModelRegistry;

class SubscriptionItem extends BillingModel
{
    protected $table = 'airwallex_subscription_items';

    protected $casts = ['snapshot' => LosslessJson::class];

    protected function immutable(): array
    {
        return ['subscription_id', 'remote_id'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('subscription'), 'subscription_id');
    }
}
