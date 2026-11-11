<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Amount;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class PaymentsIntentTest extends TestCase
{
    private Payments $payments;

    private Customer $customer;

    private array $state = [];

    private array $requests = [];

    private ?Closure $afterWrite = null;

    private ?Closure $beforeRead = null;

    private int $version = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['airwallex.payments.account_id' => 'account-payments', 'airwallex.payments.client_id' => 'payments-client', 'airwallex.payments.api_key' => 'payments-key']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('intent_owners', fn (Blueprint $table) => $table->string('id')->primary());
        $owner = IntentOwner::create(['id' => 'owner']);
        $this->payments = Payments::for($owner);
        $this->customer = Customer::create([...app(Context::class)->attributes(), 'billable_type' => $owner->getMorphClass(), 'billable_id' => $owner->id, 'merchant_customer_id' => 'merchant', 'remote_id' => 'customer-payments']);
        $reply = function (RequestInterface $request): ResponseInterface {
            $this->requests[] = $request;
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/authentication/login')) {
                return FakeHttpClient::login();
            }
            if (str_contains($path, '/payment_methods/')) {
                return FakeHttpClient::json(['id' => 'method-owned', 'customer_id' => 'customer-payments', 'status' => 'CREATED', 'type' => 'card', 'updated_at' => '2026-11-11T12:00:00Z']);
            }
            if (str_contains($path, '/payment_consents/')) {
                return FakeHttpClient::json(['id' => 'consent-owned', 'customer_id' => 'customer-payments', 'status' => 'VERIFIED', 'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled', 'payment_method' => ['id' => 'method-owned', 'type' => 'card'], 'updated_at' => '2026-11-11T12:00:00Z']);
            }
            if ($request->getMethod() === 'GET' && $this->beforeRead !== null) {
                ($this->beforeRead)();
            }
            if ($request->getMethod() === 'POST') {
                $operation = Operation::where('status', 'in_flight')->firstOrFail();
                if ($operation->kind === 'intent.create') {
                    $this->state = ['id' => 'intent-owned', ...$operation->intent, 'status' => 'REQUIRES_PAYMENT_METHOD', 'captured_amount' => '0.00', 'updated_at' => '2026-11-11T12:00:00.123456789Z'];
                }
                $this->state['request_id'] = $operation->request_id;
                if ($this->afterWrite !== null) {
                    ($this->afterWrite)($operation, $this->state);
                }
                $this->advance([]);
            }

            return FakeHttpClient::json([...$this->state, 'client_secret' => 'private-bootstrap-secret']);
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

    public function test_exact_conversion_and_creation_reserve_merchant_order_and_original_command(): void
    {
        self::assertSame('9007199254740993.01', Amount::fromMinor('900719925474099301', 'USD'));
        self::assertSame('1234', Amount::fromMinor('1234', 'JPY'));
        self::assertSame('1.234', Amount::fromMinor('1234', 'KWD'));
        $intent = $this->payments->createIntentMinor('create', 'order-one', '1234', 'EUR');
        self::assertSame('12.34', $intent->amount);
        self::assertFalse($intent->paid());
        self::assertSame('succeeded', Operation::first()->status);
        self::assertStringContainsString('"amount":12.34', (string) $this->requests[1]->getBody());
        self::assertSame($intent->id, $this->payments->createIntentMinor('create', 'order-one', '1234', 'EUR')->id);
        self::assertSame(1, Observation::count());
        try {
            $this->payments->createIntent('new-key-same-order', 'order-one', '12.34', 'EUR');
            self::fail('Order must retain original intent identity.');
        } catch (ConflictException) {
            self::assertSame(1, Intent::count());
            self::assertSame(1, Operation::count());
        }
        $other = IntentOwner::create(['id' => 'other-owner']);
        Customer::create([...app(Context::class)->attributes(), 'billable_type' => $other->getMorphClass(), 'billable_id' => $other->id, 'merchant_customer_id' => 'other-merchant', 'remote_id' => 'other-customer']);
        $writes = $this->writes();
        try {
            Payments::for($other)->createIntent('other-owner-command', 'order-one', '12.34', 'EUR');
            self::fail('Merchant order identity is account-scoped across customers.');
        } catch (ConflictException) {
            self::assertSame($writes, $this->writes());
            self::assertSame(1, Intent::count());
        }
        $this->expectException(EvidenceException::class);
        $this->payments->createIntent('float', 'another-order', 12.34, 'EUR');
    }

    public function test_browser_bootstrap_and_callback_cannot_mark_payment_captured_or_leak_secret(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '100', 'USD');
        $bootstrap = $this->payments->intentBootstrap($intent);
        self::assertSame('private-bootstrap-secret', $bootstrap->expose()['client_secret']);
        self::assertStringNotContainsString('private-bootstrap-secret', json_encode($bootstrap));
        self::assertStringNotContainsString('private-bootstrap-secret', var_export($intent->toArray(), true));
        self::assertStringNotContainsString('private-bootstrap-secret', $intent->getRawOriginal('snapshot'));
        $this->advance(['status' => 'REQUIRES_CAPTURE']);
        self::assertFalse($this->payments->reconcileIntent($intent)->paid());
        $this->advance(['status' => 'PENDING_REVIEW']);
        self::assertFalse($this->payments->reconcileIntent($intent)->paid());
        $this->advance(['status' => 'SUCCEEDED']);
        unset($this->state['captured_amount']);
        self::assertFalse($this->payments->reconcileIntent($intent)->paid());
        $this->advance(['captured_amount' => '40.00']);
        $partial = $this->payments->reconcileIntent($intent);
        self::assertTrue($partial->paid());
        self::assertSame('40.00', $partial->captured_amount);
        self::assertSame('100.00', $partial->amount); // Application still decides payable completion.
    }

    public function test_customer_present_confirmation_and_pending_capture_hold_original_financial_identity(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '100', 'USD');
        $this->afterWrite = function (Operation $operation, array &$state): void {
            if ($operation->kind === 'intent.confirm') {
                $state['status'] = 'REQUIRES_CAPTURE';
            } elseif ($operation->kind === 'intent.capture') {
                $state['status'] = 'PENDING';
            }
        };
        $intent = $this->payments->confirmIntentWithMethod($intent, 'confirm', 'method-owned');
        self::assertFalse($intent->paid());
        $pending = $this->payments->captureIntent($intent, 'capture', '40');
        $operation = Operation::where('action_key', 'capture')->firstOrFail();
        self::assertSame('acknowledged', $operation->status);
        self::assertNotNull($operation->lock_key);
        self::assertFalse($pending->paid());
        $writes = $this->writes();
        try {
            $this->payments->captureIntent($pending, 'replacement-capture', '40');
            self::fail('Pending capture must hold its original reservation.');
        } catch (ConflictException) {
            self::assertSame($writes, $this->writes());
        }
        $this->advance(['status' => 'SUCCEEDED', 'captured_amount' => '40.00']);
        $complete = $this->payments->recoverIntent($operation->id);
        self::assertTrue($complete->paid());
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertNull($operation->fresh()->lock_key);
        self::assertSame($writes, $this->writes());
    }

    public function test_timeout_retains_returned_or_operator_known_identity_without_mutation_replay(): void
    {
        $this->afterWrite = fn () => throw new RuntimeException('synthetic lost response');
        try {
            $this->payments->createIntent('create', 'order', '10', 'USD');
            self::fail('Expected ambiguity.');
        } catch (AmbiguousOperationException $exception) {
            $operation = Operation::findOrFail($exception->operationId);
        }
        $this->afterWrite = null;
        $writes = $this->writes();
        $result = $this->payments->recoverIntent($operation->id, 'intent-owned');
        self::assertSame('intent-owned', $result->remote_id);
        self::assertFalse($result->paid());
        self::assertSame($writes, $this->writes());
        self::assertSame('succeeded', $operation->fresh()->status);
    }

    public function test_zero_and_foreign_amount_owner_observations_never_create_financial_completion(): void
    {
        try {
            $this->payments->createIntentMinor('zero', 'order-zero', '0', 'USD');
            self::fail('Zero is an application business decision.');
        } catch (ConflictException) {
            self::assertSame([], $this->requests);
        }
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        $this->state['customer_id'] = 'foreign';
        $this->state['status'] = 'SUCCEEDED';
        $this->state['captured_amount'] = '10.00';
        $this->expectException(OwnershipException::class);
        $this->payments->reconcileIntent($intent);
    }

    public function test_conflicting_equal_version_observation_cannot_replace_confirmed_financial_state(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        $this->state['status'] = 'SUCCEEDED';
        $this->state['captured_amount'] = '10.00';
        try {
            $this->payments->continueIntent($intent);
            self::fail('Conflicting same-version financial evidence must fail.');
        } catch (EvidenceException) {
            self::assertFalse($intent->fresh()->paid());
            self::assertSame('REQUIRES_PAYMENT_METHOD', $intent->fresh()->status);
        }
        $this->advance([]);
        self::assertTrue($this->payments->continueIntent($intent)->paid());
    }

    public function test_cancellation_remains_reserved_until_positive_canonical_termination(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        $pending = $this->payments->cancelIntent($intent, 'cancel');
        $operation = Operation::where('action_key', 'cancel')->firstOrFail();
        self::assertSame('acknowledged', $operation->status);
        self::assertSame('REQUIRES_PAYMENT_METHOD', $pending->status);
        $writes = $this->writes();
        $this->advance(['status' => 'CANCELLED']);
        self::assertSame('CANCELLED', $this->payments->recoverIntent($operation->id)->status);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame($writes, $this->writes());
    }

    public function test_saved_card_claim_rechecks_revocation_after_canonical_preflight_without_a_charge(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        $instrument = Instrument::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id,
            'remote_id' => 'consent-owned', 'method_id' => 'method-owned', 'status' => 'VERIFIED', 'method_status' => 'CREATED', 'method_type' => 'card',
            'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled', 'snapshot' => ['consent' => ['updated_at' => '2026-11-11T12:00:00Z']]]);
        $this->beforeRead = function () use ($instrument): void {
            if (Operation::where('kind', 'intent.confirm')->where('status', 'prepared')->exists()) {
                $instrument->forceFill(['revoked_at' => now()])->save();
            }
        };
        $writes = $this->writes();
        try {
            $this->payments->confirmIntent($intent, 'saved-charge', 'consent-owned');
            self::fail('Revocation before the actual claim must stop the charge.');
        } catch (EvidenceException) {
            self::assertSame($writes, $this->writes());
            $operation = Operation::where('action_key', 'saved-charge')->firstOrFail();
            self::assertSame('failed', $operation->status);
            self::assertNull($operation->lock_key);
            self::assertFalse($intent->fresh()->paid());
        }
    }

    public function test_saved_card_customer_action_requires_canonical_capture_after_browser_completion(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        Instrument::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id,
            'remote_id' => 'consent-owned', 'method_id' => 'method-owned', 'status' => 'VERIFIED', 'method_status' => 'CREATED', 'method_type' => 'card',
            'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled', 'snapshot' => ['consent' => ['updated_at' => '2026-11-11T12:00:00Z']]]);
        $this->afterWrite = function (Operation $operation, array &$state): void {
            self::assertSame('merchant', $operation->intent['body']['triggered_by']);
            self::assertSame('consent-owned', $operation->intent['body']['payment_consent_id']);
            $state['status'] = 'REQUIRES_CUSTOMER_ACTION';
            $state['next_action'] = ['type' => 'redirect', 'url' => 'https://example.test/synthetic-3ds'];
        };
        $actionRequired = $this->payments->confirmIntent($intent, 'saved-charge', 'consent-owned');
        self::assertFalse($actionRequired->paid());
        self::assertSame('redirect', $this->payments->intentBootstrap($actionRequired)->expose()['next_action']['type']);
        self::assertFalse($this->payments->continueIntent($actionRequired)->paid());
        $writes = $this->writes();
        $this->advance(['status' => 'SUCCEEDED', 'captured_amount' => '10.00']);
        self::assertTrue($this->payments->continueIntent($actionRequired)->paid());
        self::assertSame($writes, $this->writes());
    }

    private function writes(): int
    {
        return count(array_filter($this->requests, fn (RequestInterface $request) => $request->getMethod() === 'POST' && ! str_contains($request->getUri()->getPath(), '/authentication/')));
    }

    private function advance(array $changes): void
    {
        $this->state = [...$this->state, ...$changes, 'updated_at' => sprintf('2026-11-11T12:00:%02d.123456789Z', ++$this->version)];
    }
}

class IntentOwner extends Model
{
    protected $table = 'intent_owners';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
