<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/**
 * @property string $id
 * @property string $account_id
 * @property ?string $remote_id
 * @property ?string $merchant_customer_id
 * @property ?string $disabled_at
 * @property array $snapshot
 */
class Customer extends PaymentModel
{
    protected $table = 'airwallex_payment_customers';

    protected $casts = ['snapshot' => LosslessJson::class.':encrypted'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'billable_type', 'billable_id', 'merchant_customer_id', 'remote_id'];
    }
}
