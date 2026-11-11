<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $kind
 * @property string $action_key
 * @property string $intent_hash
 * @property string $request_id
 * @property ?string $remote_id
 * @property ?string $target_id
 * @property string $status
 * @property array $intent
 */
class Operation extends PaymentModel
{
    protected $table = 'airwallex_payment_operations';

    protected $casts = ['intent' => LosslessJson::class.':encrypted'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'kind', 'action_key', 'intent_hash', 'intent', 'request_id', 'target_id', 'remote_id'];
    }
}
