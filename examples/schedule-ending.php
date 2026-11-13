<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// Access is bounded by the provider-confirmed effective end, never the local clock alone.
return static function (Subscription $subscription, string $actionKey, ?array $renewalBounds = null, ?string $endsAt = null, array $options = []): Subscription {
    return $endsAt === null
        ? $subscription->cancelAtPeriodEnd($actionKey, $renewalBounds)
        : $subscription->cancelAt($actionKey, $endsAt, $options, $renewalBounds);
};
