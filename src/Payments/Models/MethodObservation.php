<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

/**
 * @internal Independent canonical Method proof shared by every Consent referencing it.
 *
 * @property string $customer_id
 * @property string $remote_id
 * @property string $status
 * @property string $method_type
 * @property string $provider_updated_at
 */
class MethodObservation extends PaymentModel
{
    protected $table = 'airwallex_payment_method_observations';

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'remote_id'];
    }
}
