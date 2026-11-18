<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/**
 * @property string $global_account_id
 * @property string $remote_id
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property string $provider_created_at
 * @property ?string $settled_at
 * @property array{reference:?string,sender_name:?string,sender_country:?string} $snapshot
 */
class Deposit extends PaymentModel
{
    protected $table = 'airwallex_global_account_deposits';

    protected $casts = ['snapshot' => LosslessJson::class.':encrypted'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'global_account_id', 'remote_id', 'amount', 'currency', 'provider_created_at'];
    }

    public function settled(): bool
    {
        return $this->status === 'SETTLED';
    }
}
