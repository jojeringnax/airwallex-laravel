<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models\Subscription;

// Authorize this separate financial action: it may immediately retry the last failed invoice.
return static function (Subscription $subscription, string $actionKey, string $sourceId): Subscription {
    return $subscription->updatePaymentSource($actionKey, $sourceId);
};
