<?php

declare(strict_types=1);

// New plans retain billing anchor, cadence and tax controls; replan before any write if these change.

use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionTransition;

// Authorize this source generation and choose the commercial boundary, lateness policy and target catalogue first.
// Future execution has explicit worker latency; this is no atomic provider schedule or ACTIVE billing holiday.
return static fn (Subscription $subscription, string $actionKey, string $effectiveAt, string $expiresAt, array $items): SubscriptionTransition => $subscription->scheduleTransition($actionKey, $effectiveAt, $items, [
    'default_proration_mode' => 'NONE',
    'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE',
], $expiresAt);
