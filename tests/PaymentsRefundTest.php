<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Amount;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Receipt;
use Jojeringnax\AirwallexLaravel\Payments\Models\Refund;
use Jojeringnax\AirwallexLaravel\Payments\Operations;
use Jojeringnax\AirwallexLaravel\Payments\Receipts;
use Jojeringnax\AirwallexLaravel\Payments\Recovery;
use Jojeringnax\AirwallexLaravel\Services\CreationRetries;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class PaymentsRefundTest extends TestCase
{
    private Payments $payments;

    private Customer $customer;

    private Intent $intent;

    private array $payment = [];

    private array $refunds = [];

    private array $attempts = [];

    private array $requests = [];

    private string $nextStatus = 'RECEIVED';

    private bool $timeoutWrite = false;

    private bool $failRead = false;

    private ?Closure $onWrite = null;

    private ?Closure $afterWrite = null;

    private ?Closure $pages = null;

    private int $version = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['airwallex.payments.account_id' => 'account-payments', 'airwallex.payments.client_id' => 'payments-client', 'airwallex.payments.api_key' => 'payments-key',
            'airwallex.payments.webhook.secret' => 'synthetic-refund-webhook-secret']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('refund_owners', fn (Blueprint $table) => $table->string('id')->primary());
        $owner = RefundOwner::create(['id' => 'owner']);
        $this->payments = Payments::for($owner);
        $this->customer = Customer::create([...app(Context::class)->attributes(), 'billable_type' => $owner->getMorphClass(), 'billable_id' => $owner->id, 'merchant_customer_id' => 'merchant', 'remote_id' => 'customer-payments']);
        $operation = app(Operations::class)->prepare($this->customer, 'original-payment', 'intent.create', ['amount' => '100.00']);
        app(Operations::class)->succeed($operation);
        $this->payment = ['id' => 'intent-owned', 'customer_id' => $this->customer->remote_id, 'merchant_order_id' => 'order-original',
            'amount' => '100.00', 'currency' => 'USD', 'status' => 'SUCCEEDED', 'captured_amount' => '100.00', 'updated_at' => '2026-11-11T12:00:00Z'];
        $this->intent = Intent::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'operation_id' => $operation->id,
            'remote_id' => 'intent-owned', 'merchant_order_id' => 'order-original', 'amount' => '100.00', 'currency' => 'USD',
            'status' => 'SUCCEEDED', 'captured_amount' => '100.00', 'snapshot' => $this->payment]);
        $reply = function (RequestInterface $request): ResponseInterface {
            self::assertSame(0, DB::transactionLevel(), 'Provider I/O must occur outside the reservation transaction.');
            $this->requests[] = $request;
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/authentication/login')) {
                return FakeHttpClient::login();
            }
            if (str_contains($path, '/payment_intents/')) {
                return FakeHttpClient::json($this->payment);
            }
            if (str_contains($path, '/payment_attempts/')) {
                return FakeHttpClient::json($this->attempts[basename($path)] ?? ['id' => 'attempt-foreign', 'payment_intent_id' => 'foreign-intent', 'currency' => 'USD']);
            }
            if ($request->getMethod() === 'POST') {
                $operation = Operation::where('kind', 'refund.create')->where('status', 'in_flight')->firstOrFail();
                self::assertSame($operation->intent['amount'], Refund::where('operation_id', $operation->id)->firstOrFail()->amount);
                $this->onWrite?->__invoke();
                $id = 'refund-'.(count($this->refunds) + 1);
                $remote = ['id' => $id, ...$operation->intent, 'request_id' => $operation->request_id,
                    'currency' => 'USD', 'status' => $this->nextStatus, 'updated_at' => $this->time(), 'client_secret' => 'SYNTHETIC-PRIVATE'];
                $this->refunds[$id] = $remote;
                $this->afterWrite?->__invoke($id);
                if ($this->timeoutWrite) {
                    throw new RuntimeException('Synthetic timeout after unknown provider write.');
                }

                return FakeHttpClient::json($remote);
            }
            if ($path === '/api/v1/pa/refunds') {
                parse_str($request->getUri()->getQuery(), $query);
                self::assertSame('intent-owned', $query['payment_intent_id']);

                return FakeHttpClient::json($this->pages === null ? ['items' => array_values($this->refunds), 'has_more' => false] : ($this->pages)($query));
            }
            if ($this->failRead) {
                $this->failRead = false;
                throw new RuntimeException('Synthetic failed refund readback.');
            }
            $id = basename($path);
            self::assertArrayHasKey($id, $this->refunds);

            return FakeHttpClient::json($this->refunds[$id]);
        };
        $this->app->instance(ClientInterface::class, new class($reply) implements ClientInterface
        {
            public function __construct(private Closure $reply) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->reply)($request);
            }
        });
    }

    public function test_partial_pending_and_explicit_full_remaining_preserve_amounts_and_completion_semantics(): void
    {
        $first = $this->payments->refundIntent($this->intent, 'partial-one', '30', ['reason' => 'Return']);
        self::assertSame('30.00', $first->amount);
        self::assertSame('RECEIVED', $first->status);
        self::assertFalse($first->completed());
        $write = array_values(array_filter($this->requests, fn (RequestInterface $request) => str_ends_with($request->getUri()->getPath(), '/refunds/create')))[0];
        self::assertStringContainsString('"amount":30.00', (string) $write->getBody());
        self::assertStringContainsString('"payment_intent_id":"intent-owned"', (string) $write->getBody());
        self::assertStringContainsString(Operation::findOrFail($first->operation_id)->request_id, (string) $write->getBody());
        self::assertSame('70.00', $this->payments->refundableAmount($this->intent));
        $this->nextStatus = 'ACCEPTED';
        $fullAmount = $this->payments->refundableAmount($this->intent);
        $second = $this->payments->refundIntent($this->intent, 'remaining-explicit', $fullAmount);
        self::assertTrue($second->completed());
        self::assertSame('0.00', $this->payments->refundableAmount($this->intent));
        self::assertSame($first->id, $this->payments->refundIntent($this->intent, 'partial-one', '30', ['reason' => 'Return'])->id);
        self::assertSame(2, $this->writes());
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE', $first->getRawOriginal('snapshot'));
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE', json_encode($first));
        try {
            $this->payments->refundIntent($this->intent, 'over-refund', '0.01');
            self::fail('Cumulative refunds exceeded capture.');
        } catch (ConflictException) {
            self::assertSame(2, $this->writes());
            self::assertSame('failed', Operation::where('action_key', 'over-refund')->first()->status);
        }
    }

    public function test_unseen_pending_local_reservation_prevents_overstating_an_eventually_consistent_list(): void
    {
        $this->payments->refundIntent($this->intent, 'first', '60');
        $this->pages = static fn () => ['items' => [], 'has_more' => false];
        self::assertSame('40.00', $this->payments->refundableAmount($this->intent));
        $this->expectException(ConflictException::class);
        $this->payments->refundIntent($this->intent, 'second-too-large', '41');
    }

    public function test_overlapping_refund_keys_preserve_the_first_reservation_and_refundable_capacity(): void
    {
        $this->onWrite = function (): void {
            $this->onWrite = null;
            try {
                $this->payments->refundIntent($this->intent, 'concurrent', '30');
                self::fail('A second Refund displaced the in-flight original.');
            } catch (ConflictException) {
                self::assertSame('in_flight', Operation::where('action_key', 'first')->sole()->status);
            }
        };
        $this->payments->refundIntent($this->intent, 'first', '40');
        self::assertSame('succeeded', Operation::where('action_key', 'first')->sole()->status);
        self::assertSame(1, $this->writes());
        self::assertSame(1, Refund::count());
        self::assertSame('60.00', $this->payments->refundableAmount($this->intent));
        $this->payments->refundIntent($this->intent, 'concurrent', '30');
        self::assertSame('30.00', $this->payments->refundableAmount($this->intent));
        $this->payments->refundIntent($this->intent, 'last', '30');
        self::assertSame('0.00', $this->payments->refundableAmount($this->intent));
    }

    public function test_unknown_write_retains_identity_and_operator_locator_requires_original_proof(): void
    {
        $this->timeoutWrite = true;
        foreach (range(1, 2) as $attempt) {
            try {
                $this->payments->refundIntent($this->intent, 'unknown', '20');
                self::fail('Expected unknown write outcome.');
            } catch (AmbiguousOperationException) {
                self::assertSame('ambiguous', Operation::where('action_key', 'unknown')->first()->status);
            }
        }
        self::assertSame(1, $this->writes());
        $operation = Operation::where('action_key', 'unknown')->firstOrFail();
        self::assertNull($operation->remote_id);
        $this->timeoutWrite = false;
        try {
            $this->payments->refundIntent($this->intent, 'replacement-key-is-not-recovery', '20');
            self::fail('A different key released an uncertain Refund.');
        } catch (ConflictException) {
            self::assertSame(1, $this->writes());
            self::assertSame(1, Refund::count());
            self::assertNotNull($operation->fresh()->lock_key);
        }
        app(CreationRetries::class)->allowRetry($operation, 'consumer', 'explicit replacement');
        $replacement = $this->payments->refundIntent($this->intent, 'replacement-key-is-not-recovery', '20');
        self::assertSame(2, $this->writes());
        self::assertSame(2, Refund::count());
        self::assertSame('superseded', $operation->fresh()->status);
        self::assertSame('refund-2', $replacement->remote_id);
        self::assertSame('60.00', $this->payments->refundableAmount($this->intent));
        self::assertTrue(app(Recovery::class)->process('operation', $operation, 'refund-1'));
        self::assertNull(Refund::where('operation_id', $operation->id)->firstOrFail()->remote_id);
        self::assertSame('SUPERSEDED_CREATION_LOCATOR', Observation::latest('id')->firstOrFail()->payload['status']);
        self::assertSame(2, $this->writes());
    }

    public function test_returned_id_is_durable_before_readback_failure_and_known_recovery_never_posts_again(): void
    {
        $this->failRead = true;
        try {
            $this->payments->refundIntent($this->intent, 'read-failure', '20');
            self::fail('Failed confirmation was treated as complete.');
        } catch (AmbiguousOperationException) {
            $operation = Operation::where('action_key', 'read-failure')->firstOrFail();
            self::assertSame('refund-1', $operation->remote_id);
        }
        $refund = $this->payments->refundIntent($this->intent, 'read-failure', '20');
        self::assertSame('refund-1', $refund->remote_id);
        self::assertSame(1, $this->writes());
    }

    public function test_canonical_accepted_and_settled_observations_have_one_financial_identity_and_reject_stale_reads(): void
    {
        $refund = $this->payments->refundIntent($this->intent, 'first', '20');
        $this->refunds['refund-1'] = [...$this->refunds['refund-1'], 'status' => 'ACCEPTED', 'updated_at' => $this->time()];
        $refund = $this->payments->reconcileRefund($refund);
        self::assertTrue($refund->completed());
        $this->refunds['refund-1'] = [...$this->refunds['refund-1'], 'status' => 'SETTLED', 'updated_at' => $this->time()];
        $refund = $this->payments->reconcileRefund($refund);
        self::assertTrue($refund->completed());
        $rows = Observation::where('resource_type', 'airwallex_payment_refunds')->get();
        self::assertCount(3, $rows);
        self::assertSame(['refund/refund-1'], $rows->pluck('payload.financial_identity')->unique()->values()->all());
        $this->payments->reconcileRefund($refund);
        self::assertSame(3, Observation::where('resource_type', 'airwallex_payment_refunds')->count());
        $this->refunds['refund-1'] = [...$this->refunds['refund-1'], 'status' => 'RECEIVED', 'updated_at' => '2026-11-11T12:00:00Z'];
        $this->expectException(EvidenceException::class);
        $this->payments->reconcileRefund($refund);
    }

    public function test_stale_failed_list_cannot_release_a_newer_completed_reservation(): void
    {
        $this->nextStatus = 'ACCEPTED';
        $this->payments->refundIntent($this->intent, 'first', '100');
        $this->refunds['refund-1'] = [...$this->refunds['refund-1'], 'status' => 'FAILED', 'updated_at' => '2026-11-11T12:00:00Z'];
        $this->expectException(EvidenceException::class);
        $this->payments->refundIntent($this->intent, 'must-not-release', '1');
    }

    public function test_known_failure_is_not_completion_and_only_a_new_explicit_action_may_use_its_released_capacity(): void
    {
        $this->nextStatus = 'FAILED';
        $failed = $this->payments->refundIntent($this->intent, 'failed', '40');
        self::assertFalse($failed->completed());
        self::assertSame('100.00', $this->payments->refundableAmount($this->intent));
        $this->payments->refundIntent($this->intent, 'failed', '40');
        self::assertSame(1, $this->writes());
        $this->nextStatus = 'RECEIVED';
        $this->payments->refundIntent($this->intent, 'separate-authorized-action', '40');
        self::assertSame(2, $this->writes());
    }

    #[DataProvider('unprovablePages')]
    public function test_incomplete_foreign_or_duplicate_refund_pages_stop_before_the_create(array $pages): void
    {
        $this->pages = static fn (array $query) => $pages[(int) $query['page_num']];
        try {
            $this->payments->refundIntent($this->intent, 'blocked-page', '1');
            self::fail('Unproved pagination authorized a write.');
        } catch (EvidenceException|OwnershipException) {
            self::assertSame(0, $this->writes());
            self::assertSame('failed', Operation::where('action_key', 'blocked-page')->first()->status);
        }
    }

    public static function unprovablePages(): array
    {
        $row = ['id' => 'external', 'payment_intent_id' => 'intent-owned', 'amount' => '10.00', 'currency' => 'USD', 'status' => 'RECEIVED', 'updated_at' => '2026-11-11T12:00:00Z'];

        return [
            [[['items' => [], 'has_more' => true]]],
            [[['items' => [$row], 'has_more' => true], ['items' => [$row], 'has_more' => false]]],
            [[['items' => [[...$row, 'payment_intent_id' => 'foreign']], 'has_more' => false]]],
            [[['items' => [[...$row, 'payment_attempt_id' => 'attempt-foreign']], 'has_more' => false]]],
        ];
    }

    public function test_non_captured_payments_and_managed_target_overrides_are_not_refundable(): void
    {
        $this->payment = [...$this->payment, 'status' => 'REQUIRES_CAPTURE', 'captured_amount' => '0.00', 'updated_at' => '2026-11-11T12:01:00Z'];
        try {
            $this->payments->refundIntent($this->intent, 'uncaptured', '1');
            self::fail('Authorization was treated as captured cash.');
        } catch (ConflictException) {
            self::assertSame(0, $this->writes());
        }
        $this->expectException(ConflictException::class);
        $this->payments->refundIntent($this->intent, 'override', '1', ['payment_intent_id' => 'foreign']);
    }

    public function test_exact_unsigned_reservation_subtraction_borrows_without_floats(): void
    {
        self::assertSame('900719925474099300', Amount::subtract('900719925474099301', '1'));
        self::assertSame('99999999999999999999', Amount::subtract('100000000000000000000', '1'));
        self::assertSame('0', Amount::subtract('123', '123'));
        $this->expectException(EvidenceException::class);
        Amount::subtract('1', '2');
    }

    public function test_signed_external_partial_refund_has_one_projection_and_canonical_transitions(): void
    {
        $this->externalRefund('dashboard-partial', '25.01', 'RECEIVED', ['reason' => 'Returned item']);
        $receipt = $this->signedRefund('event-first', 'dashboard-partial', 'refund.accepted');
        self::assertSame('processed', $receipt->status);
        $refund = Refund::sole();
        self::assertNull($refund->operation_id);
        self::assertSame('provider_created', $refund->origin());
        self::assertSame($this->intent->id, $refund->intent_id);
        self::assertSame('25.01', $refund->amount);
        self::assertSame('Returned item', $refund->reason());
        self::assertSame('RECEIVED', $refund->status);
        self::assertFalse($refund->completed());
        self::assertSame('74.99', $this->payments->refundableAmount($this->intent));
        $this->externalRefund('dashboard-partial', '25.01', 'ACCEPTED', ['reason' => 'Returned item']);
        self::assertSame('processed', $this->signedRefund('event-late', 'dashboard-partial', 'refund.received')->status);
        self::assertTrue($refund->fresh()->completed());
        $this->signedRefund('event-late', 'dashboard-partial', 'refund.received');
        $replay = app(Receipts::class)->requestReplay($receipt);
        self::assertSame('processed', app(Receipts::class)->process($replay->id)->status);
        $this->externalRefund('dashboard-partial', '25.01', 'SETTLED');
        self::assertSame('SETTLED', $this->payments->reconcileRefund($refund)->status);
        $observations = Observation::where('resource_type', 'airwallex_payment_refunds')->orderBy('id')->get();
        self::assertSame(['RECEIVED', 'ACCEPTED', 'SETTLED'], $observations->pluck('payload.status')->all());
        self::assertSame(['refund/dashboard-partial'], $observations->pluck('payload.financial_identity')->unique()->values()->all());
        self::assertSame(['provider_created'], $observations->pluck('payload.origin')->unique()->values()->all());
        self::assertSame(1, Refund::count());
        self::assertSame(0, $this->writes());
    }

    public function test_missed_full_refund_and_failed_refund_recover_across_all_pages_using_canonical_reads(): void
    {
        $this->externalRefund('dashboard-full', '100', 'ACCEPTED', ['payment_intent_id' => null, 'payment_attempt_id' => 'attempt-owned']);
        $this->attempts['attempt-owned'] = ['id' => 'attempt-owned', 'payment_intent_id' => 'intent-owned', 'currency' => 'USD', 'captured_amount' => '100.00'];
        $this->externalRefund('dashboard-failed', '15.75', 'FAILED');
        $this->pages = fn (array $query) => (int) $query['page_num'] === 0
            ? ['items' => [$this->refunds['dashboard-full']], 'has_more' => true]
            : ['items' => [$this->refunds['dashboard-failed']], 'has_more' => false];
        [$full, $failed] = $this->payments->reconcileRefunds($this->intent);
        self::assertTrue($full->completed());
        self::assertSame('attempt-owned', $full->paymentAttemptId());
        self::assertSame('100.00', $full->amount);
        self::assertFalse($failed->completed());
        self::assertSame('FAILED', $failed->status);
        $this->payments->reconcileRefunds($this->intent);
        self::assertSame(2, Refund::count());
        self::assertSame(2, Observation::where('resource_type', 'airwallex_payment_refunds')->count());
        self::assertSame('0.00', $this->payments->refundableAmount($this->intent));
        self::assertSame(0, $this->writes());
    }

    public function test_external_receipt_cannot_adopt_foreign_wrong_currency_or_uncaptured_refund(): void
    {
        $cases = [
            [['payment_intent_id' => 'unmapped'], 'ignored'],
            [['account_id' => 'wrong-account'], 'quarantined'],
            [['currency' => 'EUR'], 'quarantined'],
            [['payment_attempt_id' => 'attempt-foreign'], 'quarantined'],
            [['amount' => '100.01'], 'failed'],
            [['amount' => '0.00'], 'failed'],
        ];
        foreach ($cases as $index => [$overrides, $receiptStatus]) {
            $this->externalRefund('bad-owner', '5.00', 'ACCEPTED', $overrides);
            self::assertSame($receiptStatus, $this->signedRefund('invalid-'.$index, 'bad-owner', 'refund.accepted')->status);
            self::assertSame(0, Refund::count());
        }
        $this->payment = [...$this->payment, 'status' => 'REQUIRES_CAPTURE', 'captured_amount' => '0.00', 'updated_at' => '2026-11-11T12:01:00Z'];
        $this->externalRefund('no-capture', '5.00', 'ACCEPTED');
        self::assertSame('failed', $this->signedRefund('uncaptured', 'no-capture', 'refund.accepted')->status);
        self::assertSame(0, Observation::where('resource_type', 'airwallex_payment_refunds')->count());
        self::assertSame(0, $this->writes());
    }

    public function test_signed_refund_arriving_during_package_write_recovers_original_without_second_record(): void
    {
        $this->nextStatus = 'ACCEPTED';
        $this->afterWrite = function (string $id): void {
            // The signed locator deliberately omits request_id. Only canonical GET can correlate it.
            $receipt = $this->signedRefund('during-original-write', $id, 'refund.accepted');
            self::assertSame('processed', $receipt->status);
            self::assertSame('package_initiated', Refund::sole()->origin());
            self::assertSame('succeeded', Operation::where('kind', 'refund.create')->sole()->status);
        };
        $result = $this->payments->refundIntent($this->intent, 'original-package-refund', '30');
        self::assertSame(Refund::sole()->id, $result->id);
        self::assertSame(1, $this->writes());
        self::assertSame(1, Observation::where('resource_type', 'airwallex_payment_refunds')->count());
        self::assertSame($result->id, $this->payments->reconcileRefunds($this->intent)[0]->id);
    }

    private function externalRefund(string $id, string $amount, string $status, array $overrides = []): void
    {
        $this->refunds[$id] = ['id' => $id, 'amount' => $amount, 'status' => $status, 'payment_intent_id' => 'intent-owned',
            'currency' => 'USD', 'updated_at' => $this->time(), ...$overrides];
    }

    private function signedRefund(string $eventId, string $refundId, string $name): Receipt
    {
        $body = json_encode(['id' => $eventId, 'name' => $name, 'account_id' => 'account-payments', 'version' => '2026-08-21',
            'created_at' => '2026-11-11T12:00:00Z', 'data' => ['object' => ['id' => $refundId]]], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestampMs();
        $receipts = app(Receipts::class);
        $receipt = $receipts->accept($body, $timestamp, hash_hmac('sha256', $timestamp.$body, 'synthetic-refund-webhook-secret'));

        return $receipts->process($receipt->id);
    }

    private function writes(): int
    {
        return count(array_filter($this->requests, fn (RequestInterface $request) => str_ends_with($request->getUri()->getPath(), '/refunds/create')));
    }

    private function time(): string
    {
        return '2026-11-11T12:00:00.'.str_pad((string) ++$this->version, 9, '0', STR_PAD_LEFT).'Z';
    }
}

class RefundOwner extends Model
{
    protected $table = 'refund_owners';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
