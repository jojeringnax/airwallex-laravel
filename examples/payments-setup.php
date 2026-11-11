<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\BrowserSetup;
use Jojeringnax\AirwallexLaravel\Payments\Instruments;

// Authorize setup first. Explicit expose() is only for that authorized browser endpoint.
return static function (Model $billable, string $actionKey, array $options = []): BrowserSetup {
    $customer = Payments::for($billable)->customer() ?? throw new ConflictException('A Payments customer is required.');

    return app(Instruments::class)->createSetup($customer, $actionKey, $options);
};
