<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;

final class ModelRegistry
{
    private const MODELS = [
        'customer' => Models\Customer::class,
        'subscription' => Models\Subscription::class,
        'subscription_item' => Models\SubscriptionItem::class,
        'subscription_slot' => Models\SubscriptionSlot::class,
        'operation' => Models\Operation::class,
        'checkout' => Models\Checkout::class,
        'webhook_receipt' => Models\WebhookReceipt::class,
        'domain_event' => Models\DomainEvent::class,
    ];

    /** @return class-string<($key is 'customer' ? Models\Customer : ($key is 'subscription' ? Models\Subscription : ($key is 'subscription_item' ? Models\SubscriptionItem : ($key is 'subscription_slot' ? Models\SubscriptionSlot : ($key is 'operation' ? Models\Operation : ($key is 'checkout' ? Models\Checkout : ($key is 'webhook_receipt' ? Models\WebhookReceipt : ($key is 'domain_event' ? Models\DomainEvent : Model))))))))> */
    public static function class(string $key): string
    {
        $base = self::MODELS[$key] ?? throw new ConfigurationException('Unknown billing model key.');
        $class = config('airwallex.models.'.$key, $base);
        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new ConfigurationException('Configured billing model must extend its package model.');
        }

        return $class;
    }

    /** @return Builder<($key is 'customer' ? Models\Customer : ($key is 'subscription' ? Models\Subscription : ($key is 'subscription_item' ? Models\SubscriptionItem : ($key is 'subscription_slot' ? Models\SubscriptionSlot : ($key is 'operation' ? Models\Operation : ($key is 'checkout' ? Models\Checkout : ($key is 'webhook_receipt' ? Models\WebhookReceipt : ($key is 'domain_event' ? Models\DomainEvent : Model))))))))> */
    public static function query(string $key): Builder
    {
        return self::make($key)->newQuery();
    }

    /** @param array<string,mixed> $attributes
     * @return ($key is 'customer' ? Models\Customer : ($key is 'subscription' ? Models\Subscription : ($key is 'subscription_item' ? Models\SubscriptionItem : ($key is 'subscription_slot' ? Models\SubscriptionSlot : ($key is 'operation' ? Models\Operation : ($key is 'checkout' ? Models\Checkout : ($key is 'webhook_receipt' ? Models\WebhookReceipt : ($key is 'domain_event' ? Models\DomainEvent : Model))))))))
     */
    public static function make(string $key, array $attributes = []): Model
    {
        $class = self::class($key);

        return new $class($attributes);
    }
}
