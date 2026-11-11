<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\BrowserIntent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;

// Call expose() deliberately after authorization. Airwallex.js/eligible native clients own wallets and 3DS.
// A return handler calls Payments::for($billable)->continueIntent($intent), a canonical GET only.
return static fn (Model $billable, Intent $intent): BrowserIntent => Payments::for($billable)->intentBootstrap($intent);
