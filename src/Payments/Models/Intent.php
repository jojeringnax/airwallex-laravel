<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\Payments\Amount;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $operation_id
 * @property ?string $remote_id
 * @property ?string $order_reservation
 * @property string $merchant_order_id
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property ?string $captured_amount
 * @property int $revision
 * @property array $snapshot
 */
class Intent extends PaymentModel
{
    protected $table = 'airwallex_payment_intents';

    protected $casts = ['snapshot' => LosslessJson::class.':encrypted', 'revision' => 'integer'];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $intent): void {
            if (! array_key_exists('order_reservation', $intent->getAttributes())) {
                $intent->setAttribute('order_reservation', $intent->merchant_order_id);
            }
        });
    }

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'operation_id', 'remote_id', 'merchant_order_id', 'amount', 'currency'];
    }

    /** A captured observation, not an application order-paid or settlement decision. */
    public function paid(): bool
    {
        return $this->status === 'SUCCEEDED' && $this->captured_amount !== null && Amount::units($this->captured_amount, $this->currency) !== '0';
    }
}
