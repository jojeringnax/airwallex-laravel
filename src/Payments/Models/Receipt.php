<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $event_id
 * @property string $digest
 * @property ?string $event_name
 * @property ?string $version
 * @property ?string $resource_id
 * @property ?string $body
 * @property string $status
 * @property int $attempts
 * @property ?string $last_error
 * @property ?string $lease_token
 * @property ?CarbonImmutable $lease_until
 */
class Receipt extends PaymentModel
{
    protected $table = 'airwallex_payment_receipts';

    protected $casts = ['body' => 'encrypted', 'attempts' => 'integer', 'lease_until' => 'immutable_datetime'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'event_id', 'digest', 'event_name', 'version', 'resource_id'];
    }
}
