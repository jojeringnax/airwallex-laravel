<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;

// Authorize the billable and persist a stable command key before the first call.
return static function (Model $billable, string $actionKey, array $profile = []): Customer {
    return app(CustomerManager::class)->createOrGet($billable, $actionKey, $profile);
};
