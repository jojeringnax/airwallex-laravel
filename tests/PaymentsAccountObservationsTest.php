<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Deposits;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Deposit;
use Jojeringnax\AirwallexLaravel\Payments\Models\Dispute;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Receipt;
use Jojeringnax\AirwallexLaravel\Payments\Receipts;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;

class PaymentsAccountObservationsTest extends TestCase
{
    private const START = '2026-11-18T09:00:00Z';

    private const END = '2026-11-18T09:10:00Z';

    private Payments $payments;

    private Intent $intent;

    private FakeHttpClient $http;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('airwallex.payments.webhook.enabled', true);
        $app['config']->set('airwallex.payments.webhook.secret', 'synthetic-observation-secret');
        $app['config']->set('airwallex.payments.account_id', 'acct-owner');
        $app['config']->set('airwallex.payments.client_id', 'payments-client');
        $app['config']->set('airwallex.payments.api_key', 'payments-key');
        $app['config']->set('airwallex.payments.deposit_global_account_id', 'ga-owned');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('observation_owners', fn (Blueprint $table) => $table->string('id')->primary());
        $owner = ObservationOwner::create(['id' => 'one']);
        $this->payments = Payments::for($owner);
        $context = app(Context::class)->attributes();
        $customer = Customer::create([...$context, 'billable_type' => $owner->getMorphClass(), 'billable_id' => $owner->id,
            'merchant_customer_id' => 'merchant-one', 'remote_id' => 'cus-owned']);
        $operation = Operation::create([...$context, 'customer_id' => $customer->id, 'kind' => 'intent.create', 'action_key' => 'seed', 'remote_id' => 'int-owned',
            'request_id' => 'cf15d1ce-cdab-4ac7-a5cb-3948385103cc', 'intent_hash' => hash('sha256', 'synthetic'), 'intent' => [], 'status' => 'succeeded']);
        $this->intent = Intent::create([...$context, 'customer_id' => $customer->id, 'operation_id' => $operation->id, 'remote_id' => 'int-owned',
            'merchant_order_id' => 'order-owned', 'amount' => '10.00', 'currency' => 'USD', 'status' => 'SUCCEEDED', 'captured_amount' => '10.00']);
        $this->http = new FakeHttpClient([FakeHttpClient::login()]);
        $this->app->instance(ClientInterface::class, $this->http);
    }

    public function test_signed_disputes_require_owned_payment_and_keep_canonical_won_or_lost(): void
    {
        $opened = $this->dispute('dst-won');
        $this->http->responses[] = FakeHttpClient::json($opened);
        $body = $this->signal('evt-open', 'payment_dispute.requires_response', ['id' => 'dst-won', 'status' => 'LOST', 'updated_at' => self::START]);
        self::assertSame('processed', $this->deliver($body)->status);
        self::assertSame('processed', $this->deliver($body)->status);
        self::assertSame('REQUIRES_RESPONSE', Dispute::firstOrFail()->status);

        $won = [...$opened, 'status' => 'WON', 'updated_at' => self::END];
        $this->http->responses[] = FakeHttpClient::json($opened);
        $wonSignal = $this->deliver($this->signal('evt-won', 'payment_dispute.won', ['id' => 'dst-won', 'updated_at' => self::END]));
        self::assertSame('dispute_update_unconfirmed', $wonSignal->last_error);
        self::assertSame('REQUIRES_RESPONSE', Dispute::firstOrFail()->status);
        $this->http->responses[] = FakeHttpClient::json($won);
        self::assertSame('processed', app(Receipts::class)->process($wonSignal->id)->status);
        $this->http->responses[] = FakeHttpClient::json($opened);
        self::assertSame('processed', $this->deliver($this->signal('evt-late', 'payment_dispute.requires_response', ['id' => 'dst-won', 'updated_at' => self::START]))->status);
        self::assertSame('WON', Dispute::firstOrFail()->status);

        $this->http->responses[] = FakeHttpClient::json($this->dispute('dst-lost'));
        $this->http->responses[] = FakeHttpClient::json([...$this->dispute('dst-lost'), 'status' => 'LOST', 'updated_at' => self::END]);
        self::assertSame('processed', $this->deliver($this->signal('evt-open-lost', 'payment_dispute.requires_response', ['id' => 'dst-lost']))->status);
        self::assertSame('processed', $this->deliver($this->signal('evt-lost', 'payment_dispute.lost', ['id' => 'dst-lost']))->status);
        self::assertSame('LOST', Dispute::where('remote_id', 'dst-lost')->firstOrFail()->status);

        $this->http->responses[] = FakeHttpClient::json([...$this->dispute('dst-unknown'), 'payment_intent_id' => 'int-outside']);
        self::assertSame('unknown_dispute_payment', $this->deliver($this->signal('evt-unknown', 'payment_dispute.won', ['id' => 'dst-unknown']))->last_error);
        $this->http->responses[] = FakeHttpClient::json([...$this->dispute('dst-foreign'), 'connected_account_id' => 'acct-foreign']);
        self::assertSame('quarantined', $this->deliver($this->signal('evt-foreign', 'payment_dispute.won', ['id' => 'dst-foreign']))->status);
        self::assertSame('quarantined', $this->deliver($this->signal('evt-foreign-envelope', 'payment_dispute.won', ['id' => 'dst-won'], 'acct-foreign'))->status);

        self::assertSame(2, Dispute::count());
        self::assertSame(['REQUIRES_RESPONSE', 'WON', 'REQUIRES_RESPONSE', 'LOST'], $this->statuses('airwallex_payment_disputes'));
        self::assertCount(9, $this->http->requests);
        self::assertSame('acct-owner', $this->http->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('/api/v1/pa/payment_disputes/dst-won', $this->http->requests[1]->getUri()->getPath());
    }

    public function test_signed_dispute_quarantines_contradictory_canonical_attempt_before_observation(): void
    {
        $this->http->responses[] = FakeHttpClient::json([...$this->dispute('dst-wrong-attempt'), 'payment_attempt_id' => 'att-outside']);
        $this->http->responses[] = FakeHttpClient::json(['id' => 'att-outside', 'payment_intent_id' => 'int-outside']);
        $receipt = $this->deliver($this->signal('evt-wrong-attempt', 'payment_dispute.won', ['id' => 'dst-wrong-attempt']));
        self::assertSame('quarantined', $receipt->status);
        self::assertSame('foreign_dispute', $receipt->last_error);
        self::assertSame(0, Dispute::count());
        self::assertSame([], $this->statuses('airwallex_payment_disputes'));
    }

    public function test_dispute_read_and_cursor_recover_owned_attempt_and_reject_mismatched_intent(): void
    {
        $attemptOnly = [...$this->dispute('dst-attempt'), 'payment_intent_id' => null, 'payment_attempt_id' => 'att-owned'];
        $this->http->responses[] = FakeHttpClient::json(['items' => [$attemptOnly], 'page_after' => 'Y3Vyc29y']);
        $this->http->responses[] = FakeHttpClient::json($attemptOnly);
        $this->http->responses[] = FakeHttpClient::json(['id' => 'att-owned', 'payment_intent_id' => 'int-owned']);
        $first = $this->payments->disputes($this->intent, self::START, self::END);
        self::assertSame('Y3Vyc29y', $first['nextCursor']);
        self::assertCount(1, $first['items']);
        $item = $first['items'][0];
        self::assertSame($this->intent->id, $item->intent_id);
        self::assertSame('att-owned', $item->payment_attempt_id);
        self::assertSame('2026-11-19T09:00:00Z', $item->due_at);
        self::assertSame('4837', $item->reason['original_code']);
        self::assertStringNotContainsString('Synthetic description', $item->toJson());
        $this->http->responses[] = FakeHttpClient::json(['items' => []]);
        $second = $this->payments->disputes($this->intent, self::START, self::END, $first['nextCursor']);
        self::assertSame(['items' => [], 'nextCursor' => null], $second);
        parse_str($this->http->requests[1]->getUri()->getQuery(), $request);
        self::assertSame(['payment_intent_id' => 'int-owned', 'from_updated_at' => self::START, 'to_updated_at' => self::END, 'page' => '0', 'size' => '100'], $request);
        parse_str($this->http->requests[4]->getUri()->getQuery(), $request);
        self::assertSame($first['nextCursor'], $request['page']);

        $this->http->responses[] = FakeHttpClient::json([...$this->dispute('dst-outside'), 'payment_intent_id' => 'int-outside']);
        $this->expectException(OwnershipException::class);
        $this->payments->reconcileDispute($this->intent, 'dst-outside');
    }

    public function test_deposit_webhooks_keep_one_scoped_receipt_and_retry_unconfirmed_reversal(): void
    {
        $pending = $this->deposit('dep-one');
        $this->http->responses[] = FakeHttpClient::json($pending);
        self::assertSame('processed', $this->deliver($this->signal('evt-pending', 'deposit.pending', ['id' => 'dep-one']))->status);
        self::assertFalse(Deposit::firstOrFail()->settled());
        $settled = [...$pending, 'status' => 'SETTLED', 'settled_at' => self::END, 'payer' => ['name' => 'Synthetic Payer', 'country_code' => 'AU', 'bank_account' => ['account_number' => 'private-account-number']], 'reference' => 'private-remittance'];
        $wire = str_replace('"10.01"', '9007199254740993.01', json_encode($settled, JSON_THROW_ON_ERROR));
        $this->http->responses[] = new Response(200, ['Content-Type' => 'application/json'], $wire);
        $settled['amount'] = '9007199254740993.01';
        // The exact amount is confirmed even before settlement.
        $malformed = $this->deliver($this->signal('evt-drift', 'deposit.settled', ['id' => 'dep-one']));
        self::assertSame('failed', $malformed->status);
        self::assertSame('PENDING', Deposit::firstOrFail()->status);

        $this->http->responses[] = FakeHttpClient::json([...$settled, 'amount' => '10.0100']);
        $body = $this->signal('evt-settled', 'deposit.settled', ['id' => 'dep-one', 'status' => 'PENDING'], direct: true);
        self::assertSame('processed', $this->deliver($body)->status);
        self::assertSame('processed', $this->deliver($body)->status);
        $deposit = Deposit::firstOrFail();
        self::assertTrue($deposit->settled());
        self::assertSame('10.01', $deposit->amount);
        self::assertSame('ga-owned', $deposit->global_account_id);
        self::assertSame(['reference' => 'private-remittance', 'sender_name' => 'Synthetic Payer', 'sender_country' => 'AU'], $deposit->snapshot);
        self::assertStringNotContainsString('private', $deposit->toJson());
        self::assertStringNotContainsString('private', DB::table($deposit->getTable())->value('snapshot'));
        $this->http->responses[] = FakeHttpClient::json([...$pending, 'status' => 'SETTLED', 'settled_at' => self::START]);
        self::assertSame(self::END, app(Deposits::class)->reconcile('dep-one')->settled_at);

        $this->http->responses[] = FakeHttpClient::json($pending);
        $reversal = $this->deliver($this->signal('evt-reversed', 'deposit.reversed', ['id' => 'dep-one']));
        self::assertSame('deposit_reversal_unconfirmed', $reversal->last_error);
        self::assertSame('SETTLED', $deposit->fresh()->status);
        $this->http->responses[] = FakeHttpClient::json([...$pending, 'status' => 'REVERSED']);
        self::assertSame('processed', app(Receipts::class)->process($reversal->id)->status);
        self::assertSame('REVERSED', $deposit->fresh()->status);
        self::assertSame(self::END, $deposit->fresh()->settled_at);

        $this->http->responses[] = FakeHttpClient::json([...$pending, 'status' => 'SETTLED']);
        self::assertSame('processed', $this->deliver($this->signal('evt-late-settlement', 'deposit.settled', ['id' => 'dep-one']))->status);
        self::assertFalse($deposit->fresh()->settled());
        self::assertSame(['PENDING', 'SETTLED', 'REVERSED'], $this->statuses('airwallex_global_account_deposits'));
        self::assertSame(1, Deposit::count());
    }

    public function test_direct_deposit_delivery_cannot_bypass_wrapper_or_merchant_proof(): void
    {
        $wrapped = $this->deliver($this->signal('evt-malformed-wrapper', 'deposit.settled', [
            'id' => 'dep-one', 'object' => null,
        ], direct: true));
        self::assertSame('quarantined', $wrapped->status);
        self::assertSame('malformed_resource', $wrapped->last_error);

        $foreign = $this->deliver($this->signal('evt-foreign-direct', 'deposit.settled', [
            'id' => 'dep-one',
        ], account: 'acct-foreign', direct: true));
        self::assertSame('quarantined', $foreign->status);
        self::assertSame('foreign_context', $foreign->last_error);
        self::assertSame([], $this->http->requests);
        self::assertSame(0, Deposit::count());
    }

    public function test_deposit_recovery_reuses_pages_filters_global_account_and_checks_merchant_context(): void
    {
        $outside = [...$this->deposit('dep-outside'), 'global_account_id' => 'ga-foreign'];
        $unidentified = [...$this->deposit('dep-no-ga'), 'global_account_id' => null];
        $direct = [...$this->deposit('dep-direct'), 'type' => 'DIRECT_DEBIT'];
        $deposit = [...$this->deposit('dep-rejected'), 'status' => 'REJECTED'];
        $this->http->responses[] = FakeHttpClient::json(['items' => [$outside, $unidentified, $direct], 'has_more' => true]);
        $this->http->responses[] = FakeHttpClient::json(['items' => [$deposit], 'has_more' => false]);
        $this->http->responses[] = FakeHttpClient::json($deposit);
        $service = app(Deposits::class);
        self::assertSame(['items' => [], 'nextPage' => 1], $service->page(self::START, self::END));
        $second = $service->page(self::START, self::END, 1);
        self::assertNull($second['nextPage']);
        self::assertSame('REJECTED', $second['items'][0]->status);
        self::assertFalse($second['items'][0]->settled());
        self::assertSame(['reference' => null, 'sender_name' => null, 'sender_country' => null], $second['items'][0]->snapshot);
        parse_str($this->http->requests[2]->getUri()->getQuery(), $request);
        self::assertSame(['from_created_at' => self::START, 'to_created_at' => self::END, 'page_num' => '1', 'page_size' => '100'], $request);

        $large = str_replace('"10.01"', '9007199254740993.123456', json_encode([...$this->deposit('dep-large'), 'status' => 'SETTLED'], JSON_THROW_ON_ERROR));
        $this->http->responses[] = new Response(200, ['Content-Type' => 'application/json'], $large);
        self::assertSame('9007199254740993.123456', $service->reconcile('dep-large')->amount);
        $this->http->responses[] = FakeHttpClient::json($deposit);
        $this->artisan('airwallex:payments-recover', ['--family' => 'deposit', '--id' => $second['items'][0]->id])->assertSuccessful();
        self::assertSame(['REJECTED', 'SETTLED'], $this->statuses('airwallex_global_account_deposits'));

        $this->http->responses[] = FakeHttpClient::json([...$this->deposit('dep-foreign'), 'account_id' => 'acct-foreign']);
        self::assertSame('quarantined', $this->deliver($this->signal('evt-foreign-ga', 'deposit.settled', ['id' => 'dep-foreign']))->status);
        self::assertSame(2, Deposit::count());
        $this->http->responses[] = FakeHttpClient::json($outside);
        $this->expectException(OwnershipException::class);
        $service->reconcile('dep-outside');
    }

    private function dispute(string $id): array
    {
        return ['id' => $id, 'customer_id' => 'cus-owned', 'payment_intent_id' => 'int-owned',
            'status' => 'REQUIRES_RESPONSE', 'stage' => 'CHARGEBACK', 'amount' => '10.00', 'currency' => 'USD',
            'due_at' => '2026-11-19T09:00:00Z', 'updated_at' => self::START,
            'reason' => ['original_code' => '4837', 'type' => 'FRAUDULENT', 'description' => 'Synthetic description']];
    }

    private function deposit(string $id): array
    {
        return ['id' => $id, 'global_account_id' => 'ga-owned', 'type' => 'BANK_TRANSFER', 'status' => 'PENDING', 'amount' => '10.01', 'currency' => 'AUD', 'created_at' => self::START];
    }

    private function signal(string $id, string $name, array $locator, string $account = 'acct-owner', bool $direct = false): string
    {
        return json_encode(['id' => $id, 'name' => $name, 'account_id' => $account, 'version' => '2026-08-21', 'created_at' => self::END, 'data' => $direct ? $locator : ['object' => $locator]], JSON_THROW_ON_ERROR);
    }

    private function deliver(string $body): Receipt
    {
        $timestamp = (string) now()->getTimestampMs();
        $this->call('POST', '/airwallex/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.$body, 'synthetic-observation-secret')], $body)->assertOk();

        return Receipt::where('digest', hash('sha256', $body))->firstOrFail();
    }

    /** @return list<string> */
    private function statuses(string $resourceType): array
    {
        return Observation::where('resource_type', $resourceType)->orderBy('id')->get()->map(fn (Observation $observation) => $observation->getAttribute('payload')['status'])->all();
    }
}

class ObservationOwner extends Model
{
    protected $table = 'observation_owners';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;
}
