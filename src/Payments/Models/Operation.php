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

    protected $hidden = ['retry_release', 'intent', 'snapshot', 'body', 'payload'];

    protected $casts = ['retry_release' => LosslessJson::class.':encrypted', 'intent' => LosslessJson::class.':encrypted'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'kind', 'action_key', 'intent_hash', 'intent', 'request_id', 'retry_release', 'target_id', 'remote_id'];
    }
}
