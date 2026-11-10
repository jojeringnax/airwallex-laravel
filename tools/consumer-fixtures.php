<?php

declare(strict_types=1);

namespace AirwallexCandidateQualification;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Billable;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Synthetic API 2026-08-21 fixtures; these establish no provider/account observations. */
final class Fixtures
{
    public static function subscription(string $id, string $customer, array $overrides = []): array
    {
        return array_replace([
            'id' => $id, 'billing_customer_id' => $customer, 'legal_entity_id' => 'entity-synthetic', 'linked_payment_account_id' => 'collector-synthetic',
            'status' => 'ACTIVE', 'starts_at' => '2026-11-01T00:00:00+0000', 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z',
            'current_period_starts_at' => '2026-11-01T00:00:00Z', 'current_period_ends_at' => '2026-12-01T00:00:00Z', 'cancel_at_period_end' => false,
            'collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-synthetic', 'currency' => 'USD', 'days_until_due' => 7,
            'applied_discounts' => [], 'automatic_tax_status' => 'DISABLED', 'enable_automatic_tax' => false, 'recurring' => ['period' => 1, 'period_unit' => 'MONTH'],
        ], $overrides);
    }

    public static function item(string $id, string $subscription, string $quantity = '2.000', string $updated = '2026-11-10T12:00:00Z'): array
    {
        return ['id' => $id, 'subscription_id' => $subscription, 'quantity' => $quantity, 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => $updated, 'applied_discounts' => [], 'price' => ['id' => 'price-synthetic', 'pricing_model' => 'PER_UNIT', 'metered' => false]];
    }

    public static function checkout(string $id, string $customer, string $mode): array
    {
        return ['id' => $id, 'billing_customer_id' => $customer, 'legal_entity_id' => 'entity-synthetic', 'linked_payment_account_id' => 'collector-synthetic', 'mode' => $mode, 'ui_mode' => 'HOSTED', 'status' => 'ACTIVE', 'url' => 'https://checkout.airwallex.com/synthetic-qualification-session', 'client_secret' => 'SYNTHETIC-DO-NOT-SERIALIZE', 'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'expires_at' => '2026-11-10T13:00:00Z', 'automatic_tax_status' => 'DISABLED', 'enable_automatic_tax' => false, 'discounts' => [], 'line_items' => [], 'metadata' => new \stdClass];
    }

    public static function source(string $id, string $customer): array
    {
        return ['id' => $id, 'billing_customer_id' => $customer, 'linked_payment_account_id' => 'collector-synthetic', 'external_id' => 'method-synthetic', 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z'];
    }

    public static function invoice(string $customer, string $subscription): array
    {
        return ['id' => 'invoice-synthetic', 'billing_customer_id' => $customer, 'subscription_id' => $subscription, 'status' => 'FINALIZED', 'payment_status' => 'UNPAID', 'currency' => 'USD', 'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'amount_due' => '0.000000000000000001', 'total_amount' => '9007199254740993.000000000000000001', 'total_tax_amount' => '0.000', 'applied_discounts' => [], 'total_discount_amounts' => [], 'automatic_tax_status' => 'DISABLED', 'enable_automatic_tax' => false, 'paid_out_of_band' => false, 'pdf_url' => 'https://example.test/synthetic-private-pdf', 'hosted_url' => 'https://example.test/synthetic-private-invoice', 'billing_customer' => ['name' => 'Synthetic profile', 'email' => 'synthetic-private@example.test']];
    }

    public static function response(array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        // Feed native JSON number tokens to the real SDK, without a PHP float round trip.
        $json = preg_replace('/("(?:quantity|amount_due|total_amount|total_tax_amount|amount)":)"(-?[0-9]+(?:\.[0-9]+)?)"/', '$1$2', $json);

        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }

    public static function request(string $method, string $path, array $response, ?\Closure $check = null): array
    {
        return [$method, '/api/v1/'.$path, self::response($response), $check];
    }

    public static function observation(array $subscription, array $items): array
    {
        return [
            self::request('GET', 'billing/subscriptions/'.$subscription['id'], $subscription),
            self::request('GET', 'billing/subscriptions/'.$subscription['id'].'/items', ['items' => $items]),
            self::request('GET', 'billing/subscriptions/'.$subscription['id'], $subscription),
        ];
    }
}

/** No fallback transport exists. Every call must match a synthetic planned response. */
final class DenyNetworkClient implements ClientInterface
{
    public int $requests = 0;

    public function __construct(private array $plan)
    {
        array_unshift($this->plan, Fixtures::request('POST', 'authentication/login', ['token' => 'token-synthetic', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests++;
        $next = array_shift($this->plan);
        if ($next === null || $request->getUri()->getHost() !== 'api.sandbox.airwallex.com' || $request->getMethod() !== $next[0] || $request->getUri()->getPath() !== $next[1]) {
            throw new RuntimeException('Unexpected SDK request rejected by the qualification transport.');
        }
        if ($next[3] !== null) {
            ($next[3])($request);
        }

        return $next[2];
    }

    public function assertConsumed(): void
    {
        if ($this->plan !== []) {
            throw new RuntimeException('A shipped recipe did not execute its complete expected SDK workflow.');
        }
    }
}

class Team extends Model
{
    use Billable;

    protected $table = 'candidate_teams';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}

class CandidateCustomer extends Customer {}

class CandidateSubscription extends Subscription {}
