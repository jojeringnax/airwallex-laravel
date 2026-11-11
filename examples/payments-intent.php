<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;

// Application owns the payable/currency and permanent merchant-order namespace. Zero is an explicit no-charge business flow.
return static fn (Model $billable, string $actionKey, string $merchantOrderId, mixed $minorUnits, string $currency): Intent => Payments::for($billable)->createIntentMinor($actionKey, $merchantOrderId, $minorUnits, $currency);
