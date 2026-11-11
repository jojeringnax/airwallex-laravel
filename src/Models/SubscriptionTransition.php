<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionTransitionManager;

/**
 * @property string $subscription_id
 * @property string $action_key
 * @property string $intent_hash
 * @property array<string,mixed> $intent
 * @property string $effective_at
 * @property string $expires_at
 * @property string $status
 */
class SubscriptionTransition extends BillingModel
{
    protected $table = 'airwallex_subscription_transitions';

    protected $casts = ['intent' => LosslessJson::class.':encrypted'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'subscription_id', 'action_key', 'intent_hash', 'intent', 'effective_at', 'expires_at', 'due_index_at'];
    }

    public function cancel(): SubscriptionTransition
    {
        return app(SubscriptionTransitionManager::class)->cancel($this);
    }

    public function replace(string $actionKey, string $effectiveAt, array $items, array $options, string $expiresAt): SubscriptionTransition
    {
        return app(SubscriptionTransitionManager::class)->replace($this, $actionKey, $effectiveAt, $items, $options, $expiresAt);
    }

    public function run(): SubscriptionTransition
    {
        return app(SubscriptionTransitionManager::class)->run($this);
    }
}
