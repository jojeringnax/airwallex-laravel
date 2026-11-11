<?php

declare(strict_types=1);

namespace AirwallexPaymentsQualification;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Laravel\Cashier\Billable;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Synthetic API fixtures only. No fallback transport, credentials or account observations. */
final class PaymentsTransport implements ClientInterface
{
    public array $customers = [];

    public array $consents = [];

    public array $methods = [];

    public array $intents = [];

    public array $refunds = [];

    public array $requests = [];

    public bool $loseNextIntentResponse = false;

    private int $version = 0;

    public function time(): string
    {
        return gmdate('Y-m-d\TH:i:s', 1794398400 + ++$this->version).'.123456789Z';
    }

    public function advanceIntent(string $id, array $changes): void
    {
        $this->intents[$id] = [...$this->intents[$id], ...$changes, 'updated_at' => $this->time()];
    }

    public function advanceRefund(string $id, string $status): void
    {
        $this->refunds[$id] = [...$this->refunds[$id], 'status' => $status, 'updated_at' => $this->time()];
    }

    public function writes(): int
    {
        return count(array_filter($this->requests, static fn ($request) => $request[0] === 'POST' && ! str_ends_with($request[1], '/authentication/login')));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $this->requests[] = [$method, $path]; // Never retain bodies, authorization or browser secrets.
        if ($request->getUri()->getHost() !== 'api.sandbox.airwallex.com') {
            throw new RuntimeException('Qualification transport rejected a non-synthetic endpoint.');
        }
        if ($path === '/api/v1/authentication/login' && $method === 'POST') {
            if ($request->getHeaderLine('x-login-as') !== 'account-synthetic-payments') {
                throw new RuntimeException('Payments authentication used the wrong account context.');
            }

            return $this->response(['token' => 'synthetic-token', 'expires_at' => '2130-01-01T00:00:00Z']);
        }
        if ($method === 'POST') {
            $operation = Operation::where('status', 'in_flight')->sole();
            $data = [...$operation->intent, 'request_id' => $operation->request_id, 'updated_at' => $this->time()];
            $result = match (true) {
                $path === '/api/v1/pa/customers/create' && $operation->kind === 'customer.create' => $this->customers['cus-payments'] = ['id' => 'cus-payments', ...$data],
                $path === '/api/v1/pa/payment_consents/create' && $operation->kind === 'consent.create' => $this->consents['cst-payments'] = ['id' => 'cst-payments', ...$data, 'status' => 'REQUIRES_PAYMENT_METHOD'],
                $path === '/api/v1/pa/payment_intents/create' && $operation->kind === 'intent.create' => $this->intents['int-'.(count($this->intents) + 1)] = ['id' => 'int-'.(count($this->intents) + 1), ...$data, 'status' => 'REQUIRES_PAYMENT_METHOD', 'captured_amount' => '0.00'],
                $path === '/api/v1/pa/refunds/create' && $operation->kind === 'refund.create' => $this->refunds['rfd-'.(count($this->refunds) + 1)] = ['id' => 'rfd-'.(count($this->refunds) + 1), ...$data, 'currency' => 'USD', 'status' => 'RECEIVED'],
                str_ends_with($path, '/confirm') && $operation->kind === 'intent.confirm' => $this->intents[$operation->target_id] = [...$this->intents[$operation->target_id], 'request_id' => $operation->request_id, 'updated_at' => $data['updated_at'], 'status' => 'REQUIRES_CUSTOMER_ACTION'],
                str_ends_with($path, '/capture') && $operation->kind === 'intent.capture' => $this->intents[$operation->target_id] = [...$this->intents[$operation->target_id], 'request_id' => $operation->request_id, 'updated_at' => $data['updated_at'], 'status' => 'PENDING'],
                default => throw new RuntimeException('Unexpected synthetic Payments mutation.'),
            };
            if ($operation->kind === 'intent.create' && $this->loseNextIntentResponse) {
                $this->loseNextIntentResponse = false;
                throw new RuntimeException('Synthetic response lost after provider acceptance.');
            }

            return $this->response([...$result, 'client_secret' => 'SYNTHETIC-PRIVATE-HANDOFF']);
        }
        if ($method !== 'GET') {
            throw new RuntimeException('Unexpected synthetic HTTP method.');
        }
        if (in_array($path, ['/api/v1/pa/payment_consents', '/api/v1/pa/payment_methods'], true)) {
            return $this->response(['items' => array_values(str_ends_with($path, 'payment_consents') ? $this->consents : $this->methods), 'has_more' => false]);
        }
        if ($path === '/api/v1/pa/refunds') {
            parse_str($request->getUri()->getQuery(), $query);
            $items = array_values(array_filter($this->refunds, static fn ($refund) => $refund['payment_intent_id'] === ($query['payment_intent_id'] ?? null)));

            return $this->response(['items' => $items, 'has_more' => false]);
        }
        foreach (['customers' => $this->customers, 'payment_consents' => $this->consents, 'payment_methods' => $this->methods, 'payment_intents' => $this->intents, 'refunds' => $this->refunds] as $family => $resources) {
            $id = basename($path);
            if ($path === '/api/v1/pa/'.$family.'/'.$id && isset($resources[$id])) {
                return $this->response([...$resources[$id], 'client_secret' => 'SYNTHETIC-PRIVATE-HANDOFF']);
            }
        }
        throw new RuntimeException('Unexpected synthetic Payments canonical read.');
    }

    private function response(array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $json = preg_replace('/("(?:amount|captured_amount)":)"([0-9]+(?:\.[0-9]+)?)"/', '$1$2', $json);

        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }
}

class PaymentsOwner extends Model
{
    use Billable;

    protected $table = 'candidate_payments_owners';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}

final class LegacyStripeTransport implements \Stripe\HttpClient\ClientInterface
{
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        if ($method !== 'post' || $absUrl !== 'https://api.stripe.com/v1/refunds' || ($params['payment_intent'] ?? null) !== 'pi_legacy') {
            throw new RuntimeException('Unexpected legacy-provider request.');
        }
        $this->requests[] = [$method, $absUrl];

        return [json_encode(['id' => 're_legacy', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd']), 200, []];
    }
}
