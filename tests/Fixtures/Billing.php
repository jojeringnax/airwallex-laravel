<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests\Fixtures;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/** Synthetic fixtures for the SDK's selected API2026-08-21, not Sandbox observations. */
final class Billing
{
    public static function subscription(array $overrides = []): array
    {
        return array_replace(['id' => 'sub-test', 'billing_customer_id' => 'customer-test', 'legal_entity_id' => 'entity-test', 'linked_payment_account_id' => 'collector-test', 'status' => 'ACTIVE', 'starts_at' => '2026-11-01T00:00:00+0000', 'created_at' => '2026-10-31T00:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'cancel_at_period_end' => false, 'collection_method' => 'OUT_OF_BAND', 'currency' => 'USD', 'days_until_due' => 7, 'applied_discounts' => [], 'automatic_tax_status' => 'DISABLED', 'enable_automatic_tax' => false, 'recurring' => ['period' => 1, 'period_unit' => 'MONTH']], $overrides);
    }

    public static function item(array $overrides = []): array
    {
        return array_replace(['id' => 'item-test', 'subscription_id' => 'sub-test', 'quantity' => '1.000000000000000000001', 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'applied_discounts' => [], 'price' => ['id' => 'price-test', 'pricing_model' => 'PER_UNIT', 'metered' => false]], $overrides);
    }

    public static function checkout(array $overrides = []): array
    {
        return array_replace(['id' => 'checkout-test', 'billing_customer_id' => 'customer-test', 'legal_entity_id' => 'entity-test', 'linked_payment_account_id' => 'collector-test', 'mode' => 'SUBSCRIPTION', 'ui_mode' => 'HOSTED', 'status' => 'ACTIVE', 'url' => 'https://checkout.airwallex.com/synthetic-session', 'client_secret' => 'SYNTHETIC-CLIENT-SECRET', 'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'expires_at' => '2026-11-10T13:00:00Z', 'automatic_tax_status' => 'DISABLED', 'enable_automatic_tax' => false, 'discounts' => [], 'line_items' => [], 'metadata' => new \stdClass], $overrides);
    }

    public static function source(array $overrides = []): array
    {
        return array_replace(['id' => 'source-test', 'billing_customer_id' => 'customer-test', 'linked_payment_account_id' => 'collector-test', 'external_id' => 'method-test', 'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z'], $overrides);
    }

    public static function page(array $items, ?string $after = null): ResponseInterface
    {
        $json = json_encode(['items' => $items, 'page_after' => $after], JSON_THROW_ON_ERROR);
        // Quantity response values are native JSON numbers; never route through float.
        $json = preg_replace('/("quantity":)"([0-9]+(?:\.[0-9]+)?)"/', '$1$2', $json);

        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }
}
