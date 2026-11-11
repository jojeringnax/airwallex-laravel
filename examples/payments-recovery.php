<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Payments;

// The operator-known ID is a locator, never proof. The original command is read-only recovered without replay.
return static function (Model $billable, string $family, string $operationId, ?string $operatorKnownId = null): Model {
    $payments = Payments::for($billable);

    return match ($family) {
        'customer' => $payments->recoverCustomer($operationId, $operatorKnownId),
        'intent' => $payments->recoverIntent($operationId, $operatorKnownId),
        'refund' => $payments->recoverRefund($operationId, $operatorKnownId),
        default => throw new InvalidArgumentException('Choose a supported original operation family.'),
    };
};
