<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $intent_id
 * @property string $operation_id
 * @property ?string $remote_id
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property int $revision
 * @property array $snapshot
 */
class Refund extends PaymentModel
{
    protected $table = 'airwallex_payment_refunds';

    protected $casts = ['snapshot' => LosslessJson::class.':encrypted', 'revision' => 'integer'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'intent_id', 'operation_id', 'remote_id', 'amount', 'currency'];
    }

    public function completed(): bool
    {
        return in_array($this->status, ['ACCEPTED', 'SETTLED'], true);
    }
}
