<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// Authorize the original subscription and its original package scheduling action.
// Match the saved state/source and supported finite/indefinite combination in docs/contracts.md.
return static fn (Subscription $subscription, string $actionKey, string $scheduleActionKey): Subscription => $subscription->restoreRenewal($actionKey, $scheduleActionKey);
