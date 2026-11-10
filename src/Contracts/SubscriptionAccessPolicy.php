<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Contracts;

use Jojeringnax\AirwallexLaravel\Models\Subscription;

interface SubscriptionAccessPolicy
{
    public function allows(Subscription $subscription): bool;
}
