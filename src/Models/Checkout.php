<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\RedirectResponse;
use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Services\CheckoutManager;

/**
 * @property string $customer_id
 * @property string $operation_id
 * @property ?string $name
 * @property string $mode
 * @property string $remote_id
 * @property string $status
 * @property ?string $expires_at
 * @property ?string $url
 * @property ?string $remote_subscription_id
 * @property ?string $remote_payment_source_id
 * @property ?string $payment_source_id
 * @property ?string $subscription_id
 * @property ?string $provider_created_at
 * @property ?string $provider_updated_at
 * @property int $revision
 * @property ?array $snapshot
 */
class Checkout extends BillingModel
{
    protected $table = 'airwallex_checkouts';

    protected $casts = ['url' => 'encrypted', 'snapshot' => LosslessJson::class.':encrypted', 'revision' => 'integer'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'operation_id', 'name', 'mode', 'remote_id', 'subscription_id', 'payment_source_id'];
    }

    public function reconcile(): Checkout
    {
        return app(CheckoutManager::class)->reconcile($this);
    }

    public function redirect(): RedirectResponse
    {
        return app(CheckoutManager::class)->redirect($this);
    }

    public function updateMetadata(string $actionKey, array|\stdClass $metadata): Checkout
    {
        return app(CheckoutManager::class)->updateMetadata($this, $actionKey, $metadata);
    }

    public function cancel(string $actionKey): Checkout
    {
        return app(CheckoutManager::class)->cancel($this, $actionKey);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('customer'), 'customer_id');
    }
}
