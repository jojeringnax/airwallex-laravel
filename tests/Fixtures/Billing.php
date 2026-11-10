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
        return array_replace(['id' => 'sub-test', 'billing_customer_id' => 'customer-test', 'status' => 'ACTIVE', 'starts_at' => '2026-11-01T00:00:00+0000', 'created_at' => '2026-10-31T00:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'cancel_at_period_end' => false, 'collection_method' => 'OUT_OF_BAND', 'currency' => 'USD', 'days_until_due' => 7, 'applied_discounts' => [], 'automatic_tax_status' => 'DISABLED', 'enable_automatic_tax' => false, 'recurring' => ['period' => 1, 'period_unit' => 'MONTH']], $overrides);
    }

    public static function item(array $overrides = []): array
    {
        return array_replace(['id' => 'item-test', 'subscription_id' => 'sub-test', 'quantity' => '1.000000000000000000001', 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'applied_discounts' => [], 'price' => ['id' => 'price-test', 'pricing_model' => 'PER_UNIT', 'metered' => false]], $overrides);
    }

    public static function page(array $items, ?string $after = null): ResponseInterface
    {
        $json = json_encode(['items' => $items, 'page_after' => $after], JSON_THROW_ON_ERROR);
        // Quantity response values are native JSON numbers; never route through float.
        $json = preg_replace('/("quantity":)"([0-9]+(?:\.[0-9]+)?)"/', '$1$2', $json);

        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }
}
