<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests\Consumers\Coexistence;

use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Subscription;
use Stripe\HttpClient\ClientInterface;

class CashierTeam extends Model
{
    use Billable;

    protected $table = 'coexistence_teams';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}

class StripeSubscription extends Subscription
{
    protected $table = 'coexistence_stripe_subscriptions';
}

/** All Stripe network access is denied; only these legacy fixture reads/actions are possible. */
class StripeTransport implements ClientInterface
{
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [$method, $absUrl];
        $response = match ([$method, $absUrl]) {
            ['get', 'https://api.stripe.com/v1/invoices'] => ['object' => 'list', 'data' => [], 'has_more' => false],
            ['post', 'https://api.stripe.com/v1/refunds'] => $params['payment_intent'] === 'pi_legacy' ? ['id' => 're_legacy', 'object' => 'refund', 'payment_intent' => 'pi_legacy', 'status' => 'succeeded'] : throw new \RuntimeException('Legacy refund changed provider identity.'),
            ['delete', 'https://api.stripe.com/v1/subscriptions/sub_legacy'] => ['id' => 'sub_legacy', 'object' => 'subscription', 'status' => 'canceled'],
            default => throw new \RuntimeException('Unexpected Stripe request rejected by qualification transport.'),
        };

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
    }
}
