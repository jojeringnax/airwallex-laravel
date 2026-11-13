<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\SubscriptionBuilder;

// Authorize the billable and persist the application command key before calling.
return static function (Model $billable, string $actionKey, string $priceId, string $sourceId, ?string $trialEndsAt = null, ?string $couponId = null): Subscription {
    $builder = (new SubscriptionBuilder($billable, 'default', $priceId))
        ->quantity(2);
    if ($trialEndsAt !== null) {
        $builder->trialUntil($trialEndsAt);
    }
    if ($couponId !== null) {
        $builder->withCoupon($couponId);
    }

    return $builder->create($actionKey, ['payment_source_id' => $sourceId]);
};
