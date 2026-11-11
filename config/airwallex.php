<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Models;

return [
    'environment' => env('AIRWALLEX_ENVIRONMENT', 'sandbox'),
    'organization_id' => env('AIRWALLEX_ORGANIZATION_ID'),
    'client_id' => env('AIRWALLEX_CLIENT_ID'),
    'api_key' => env('AIRWALLEX_API_KEY'),
    'legal_entity_id' => env('AIRWALLEX_LEGAL_ENTITY_ID'),
    'linked_payment_account_id' => env('AIRWALLEX_LINKED_PAYMENT_ACCOUNT_ID'),
    'currency' => env('AIRWALLEX_CURRENCY'),
    'collection_method' => env('AIRWALLEX_COLLECTION_METHOD', 'AUTO_CHARGE'),
    'cache_store' => env('AIRWALLEX_CACHE_STORE'),
    'read_retry_attempts' => 1,
    'http_timeout' => 30,
    'http_connect_timeout' => 10,
    'payments' => [
        'environment' => env('AIRWALLEX_PAYMENTS_ENVIRONMENT', 'sandbox'),
        'account_id' => env('AIRWALLEX_PAYMENTS_ACCOUNT_ID'),
        'client_id' => env('AIRWALLEX_PAYMENTS_CLIENT_ID'),
        'api_key' => env('AIRWALLEX_PAYMENTS_API_KEY'),
        'cache_store' => env('AIRWALLEX_PAYMENTS_CACHE_STORE'),
    ],
    'models' => [
        'customer' => Models\Customer::class,
        'subscription' => Models\Subscription::class,
        'subscription_item' => Models\SubscriptionItem::class,
        'subscription_slot' => Models\SubscriptionSlot::class,
        'operation' => Models\Operation::class,
        'checkout' => Models\Checkout::class,
        'webhook_receipt' => Models\WebhookReceipt::class,
        'domain_event' => Models\DomainEvent::class,
    ],
    'webhook' => [
        'enabled' => true,
        'path' => 'airwallex/webhook',
        'middleware' => [],
        'secret' => env('AIRWALLEX_WEBHOOK_SECRET'),
        'version' => '2026-08-21',
        'tolerance_milliseconds' => 300000,
        'retention_days' => 7,
        'lease_seconds' => 300,
        'queue' => env('AIRWALLEX_WEBHOOK_QUEUE'),
    ],
    'events' => [
        'lease_seconds' => 300,
        'queue' => env('AIRWALLEX_EVENTS_QUEUE'),
    ],
];
