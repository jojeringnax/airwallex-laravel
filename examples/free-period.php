<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// The application authorizes eligibility and selects an owned active 100% CUSTOM Coupon.
// Explicitly install/qualify an SDK with Coupon retrieval. Invoice effects need separate proof.
// This discount recipe does not calculate a partial free interval's paid remainder.
return static fn (): array => [
    'apply' => static fn (Subscription $subscription, string $actionKey, string $couponId): Subscription => $subscription->applyFreePeriod($actionKey, $couponId),
    'remove' => static fn (Subscription $subscription, string $actionKey, string $originalActionKey): Subscription => $subscription->removeFreePeriod($actionKey, $originalActionKey),
];
