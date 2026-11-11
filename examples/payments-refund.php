<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Refund;

// Persist the explicit refund amount/key before calling. For full remaining refund, inspect refundableAmount once,
// authorize it, then retain that exact amount on every retry rather than recalculating a new financial intent.
return static fn (Model $billable, Intent $intent, string $actionKey, mixed $amount): Refund => Payments::for($billable)->refundIntent($intent, $actionKey, $amount);
