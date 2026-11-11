<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;

// Choose the authorized trigger explicitly. A saved Consent is not a customer-present Method.
return static function (Model $billable, Intent $intent, string $actionKey, string $instrumentId, string $trigger): Intent {
    $payments = Payments::for($billable);

    return match ($trigger) {
        'merchant' => $payments->confirmIntent($intent, $actionKey, $instrumentId),
        'customer' => $payments->confirmIntentWithMethod($intent, $actionKey, $instrumentId),
        default => throw new InvalidArgumentException('Choose merchant or customer initiation explicitly.'),
    };
};
