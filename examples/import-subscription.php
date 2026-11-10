<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;

// Authorize the local billable and explicitly link its existing Billing Customer
// first. Import reads only; the caller supplies the local subscription name.
return static function (Model $billable, string $name, string $remoteSubscriptionId): Subscription {
    $customer = app(CustomerManager::class)->requireCustomer($billable);

    return app(SubscriptionSynchronizer::class)->import($customer, $name, $remoteSubscriptionId);
};
