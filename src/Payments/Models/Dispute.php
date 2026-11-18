<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/**
 * @property string $customer_id
 * @property string $intent_id
 * @property string $remote_id
 * @property ?string $payment_attempt_id
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property ?string $stage
 * @property ?array{description:?string,original_code:?string,type:?string} $reason
 * @property ?string $due_at
 * @property string $provider_updated_at
 */
class Dispute extends PaymentModel
{
    protected $table = 'airwallex_payment_disputes';

    protected $casts = ['reason' => LosslessJson::class.':encrypted'];

    protected $hidden = ['reason'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'intent_id', 'remote_id', 'payment_attempt_id'];
    }
}
