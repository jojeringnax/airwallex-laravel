<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// Authorize immediate cancellation and explicitly choose provider proration behavior.
return static function (Subscription $subscription, string $actionKey, string $prorationBehavior): Subscription {
    return $subscription->cancelNow($actionKey, $prorationBehavior);
};
