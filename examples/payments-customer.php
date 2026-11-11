<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;

// Authorize the original model and persist its command key first; no email-based adoption.
return static fn (Model $billable, string $actionKey, array $profile = []): Customer => Payments::for($billable)->createCustomer($actionKey, $profile);
