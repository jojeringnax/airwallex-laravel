<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;

/**
 * @property string $customer_id
 * @property ?string $subscription_id
 * @property string $kind
 * @property string $action_key
 * @property string $intent_hash
 * @property string $request_id
 * @property ?string $remote_id
 * @property string $status
 * @property ?string $lock_key
 * @property ?string $error_code
 * @property array<string,mixed> $intent
 */
class Operation extends BillingModel
{
    protected $table = 'airwallex_operations';

    protected $casts = ['intent' => LosslessJson::class.':encrypted'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'subscription_id', 'kind', 'action_key', 'intent_hash', 'intent', 'request_id', 'remote_id'];
    }
}
