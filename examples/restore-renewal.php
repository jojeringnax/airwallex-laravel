<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// Authorize the original subscription and its original package scheduling action.
// Only the verified finite ACTIVE OUT_OF_BAND combination is supported.
return static fn (Subscription $subscription, string $actionKey, string $scheduleActionKey): Subscription => $subscription->restoreRenewal($actionKey, $scheduleActionKey);
