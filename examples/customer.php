<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;

// Authorize the local billable before invoking this read-only provider recipe.
return static function (Model $billable, string $remoteCustomerId): Customer {
    return app(CustomerManager::class)->link($billable, $remoteCustomerId);
};
