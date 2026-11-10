<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// Authorize the subscription. Supply explicit provider proration/billing controls.
// Existing IDs can change quantity or be deleted; a price swap deletes then adds.
return static function (Subscription $subscription, string $actionKey, array $items, array $controls): Subscription {
    return $subscription->updateItems($actionKey, $items, $controls);
};
