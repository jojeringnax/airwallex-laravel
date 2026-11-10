<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Concerns;

use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;

trait ManagesCustomers
{
    /** @param array<string,mixed> $profile */
    public function createOrGetAirwallexCustomer(string $actionKey, array $profile = []): Customer
    {
        return app(CustomerManager::class)->createOrGet($this, $actionKey, $profile);
    }

    public function linkAirwallexCustomer(string $remoteId): Customer
    {
        return app(CustomerManager::class)->link($this, $remoteId);
    }

    /** @param array<string,mixed> $profile */
    public function syncAirwallexCustomer(string $actionKey, array $profile = []): Customer
    {
        return app(CustomerManager::class)->sync($this, $actionKey, $profile);
    }
}
