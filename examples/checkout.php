<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\SubscriptionBuilder;

// Authorize the billable and persist this command key before requesting a session.
// Browser success is a navigation result; reconcile the owned checkout server-side.
return static function (Model $billable, string $actionKey, string $priceId, array $options): Checkout {
    return (new SubscriptionBuilder($billable, 'default', $priceId))
        ->quantity(3)
        ->checkout($actionKey, $options);
};
