<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Services\CheckoutManager;

// Setup collects a source. A later explicit source replacement is a separate action.
return static function (Model $billable, string $actionKey, array $options): Checkout {
    return app(CheckoutManager::class)->setup($billable, $actionKey, $options);
};
