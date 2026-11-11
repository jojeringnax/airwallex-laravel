<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Models\BillingModel;

/** Shares UTC/ULID mechanics, never Billing identity or tables. */
abstract class PaymentModel extends BillingModel
{
    protected function immutable(): array
    {
        return ['id', 'environment', 'account_id'];
    }
}
