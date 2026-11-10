<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\SubscriptionBuilder;

// Authorize the billable and persist the application command key before calling.
return static function (Model $billable, string $actionKey, string $priceId, string $sourceId): Subscription {
    return (new SubscriptionBuilder($billable, 'default', $priceId))
        ->quantity(2)
        ->create($actionKey, ['payment_source_id' => $sourceId]);
};
