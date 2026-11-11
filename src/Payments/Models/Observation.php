<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/** Stable delivery identity; the application also deduplicates financial identity. */
class Observation extends PaymentModel
{
    protected $table = 'airwallex_payment_observations';

    protected $casts = ['payload' => LosslessJson::class];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'observation_key', 'resource_type', 'resource_id', 'payload'];
    }
}
