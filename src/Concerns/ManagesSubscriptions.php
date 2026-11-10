<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Concerns;

use Jojeringnax\AirwallexLaravel\SubscriptionBuilder;

trait ManagesSubscriptions
{
    public function newSubscription(string $name, string $priceId): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $name, $priceId);
    }
}
