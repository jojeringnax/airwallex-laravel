<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $remote_id Consent identity, not a Billing Source.
 * @property ?string $method_id
 * @property string $status
 * @property ?string $method_status
 * @property ?string $method_type
 * @property ?string $next_triggered_by
 * @property ?string $merchant_trigger_reason
 * @property ?string $revoked_at
 * @property int $revision
 */
class Instrument extends PaymentModel
{
    protected $table = 'airwallex_payment_instruments';

    protected $casts = ['snapshot' => LosslessJson::class.':encrypted', 'revision' => 'integer'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'remote_id', 'create_operation_id'];
    }
}
