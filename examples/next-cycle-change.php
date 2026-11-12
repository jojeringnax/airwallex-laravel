<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// Persist the application's target entitlement and this confirmed boundary before this call.
// Submit before renewal/trial end; immediate items do not change the application's dated entitlement.
return static fn (Subscription $subscription, string $actionKey, array $items, string $boundary): Subscription => $subscription->updateItemsForNextCycle($actionKey, $items, $boundary);
