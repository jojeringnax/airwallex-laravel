<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Instruments;

// Browser completion supplies a locator; canonical ownership/consent/method proof governs eligibility.
return static function (Model $billable, string $consentId): array {
    $customer = Payments::for($billable)->customer() ?? throw new ConflictException('A Payments customer is required.');
    $instruments = app(Instruments::class);
    $instrument = $instruments->complete($customer, $consentId);
    $instruments->setDefault($customer, $instrument); // Explicit local selection, no remote default assumption.

    return ['instrument' => $instrument, 'all' => $instruments->all($customer), 'default' => $instruments->default($customer)];
};
