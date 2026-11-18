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
use Jojeringnax\AirwallexLaravel\Payments\Instruments;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\MethodObservation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Operations;
use Jojeringnax\AirwallexLaravel\Services\CreationRetries;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class PaymentsIntentTest extends TestCase
{
    private Payments $payments;

    private Customer $customer;

    private array $state = [];

    private array $attempts = [];

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
            if (str_ends_with($path, '/pa/customers/customer-payments')) {
                return FakeHttpClient::json(['id' => 'customer-payments', 'merchant_customer_id' => 'merchant', 'account_id' => 'account-payments']);
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
            if (str_contains($path, '/payment_attempts/')) {
                return FakeHttpClient::json($this->attempts[basename($path)]);
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

    public function test_migration_report_pair_is_adopted_once_and_used_for_an_explicit_merchant_charge(): void
    {
        $unmapped = IntentOwner::create(['id' => 'migration-owner']);
        $this->customer->delete();
        $payments = Payments::for($unmapped);
        $customer = $payments->linkCustomer('customer-payments');
        $instruments = app(Instruments::class);
        $imported = $instruments->adoptVerifiedCard($customer, 'consent-owned', 'method-owned');

        self::assertSame($customer->id, $imported->customer_id);
        self::assertSame('merchant', $imported->next_triggered_by);
        self::assertSame('unscheduled', $imported->merchant_trigger_reason);
        self::assertSame($imported->id, $instruments->adoptVerifiedCard($customer, 'consent-owned', 'method-owned')->id);
        self::assertSame(1, Instrument::count());
        self::assertSame(1, Observation::count());
        self::assertNull($instruments->default($customer));
        self::assertSame(0, $this->writes());

        $instruments->setDefault($customer, $imported);
        $reads = count($this->requests);
        try {
            $payments->linkCustomer('different-report-customer');
            self::fail('Conflicting report customer displaced an existing mapping.');
        } catch (OwnershipException) {
            self::assertCount($reads, $this->requests);
            self::assertSame($customer->id, $payments->customer()->id);
            self::assertSame('customer-payments', $payments->customer()->remote_id);
            self::assertSame($imported->id, DB::table('airwallex_payment_instrument_defaults')->value('instrument_id'));
        }

        $this->afterWrite = function (Operation $operation, array &$state): void {
            if ($operation->kind === 'intent.confirm') {
                self::assertSame('consent-owned', $operation->intent['body']['payment_consent_id']);
                $state['status'] = 'SUCCEEDED';
                $state['captured_amount'] = '1.25';
            }
        };
        $intent = $payments->createIntent('migration-create', 'migration-order', '1.25', 'EUR');
        $paid = $payments->confirmIntent($intent, 'migration-confirm', $imported->remote_id);

        self::assertSame($intent->id, $paid->id);
        self::assertTrue($paid->paid());
        self::assertSame('1.25', $paid->captured_amount);
        self::assertSame(2, $this->writes());
        self::assertSame($paid->id, $payments->confirmIntent($intent, 'migration-confirm', $imported->remote_id)->id);
        self::assertSame(2, $this->writes());
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

    public function test_sle_uses_provider_zero_precision_and_retains_original_command(): void
    {
        self::assertSame('100', Amount::fromMinor('100', 'SLE'));
        self::assertSame('100', Amount::units('100.00', 'SLE'));
        $intent = $this->payments->createIntentMinor('sle', 'sle-order', '100', 'SLE');
        self::assertSame('100', $intent->amount);
        self::assertSame('SLE', $intent->currency);
        self::assertStringContainsString('"amount":100,', (string) $this->requests[1]->getBody());
        $operation = Operation::firstOrFail();
        $requestId = $operation->request_id;
        $writes = $this->writes();

        self::assertSame($intent->id, $this->payments->createIntent('sle', 'sle-order', '100.00', 'SLE')->id);
        self::assertSame($writes, $this->writes());
        self::assertSame($requestId, $operation->fresh()->request_id);
        self::assertSame('100', $operation->fresh()->intent['amount']);
        self::assertSame(1, Operation::count());
        self::assertSame(1, Intent::count());
    }

    public function test_excess_or_unknown_currency_precision_refuses_before_any_http_or_command(): void
    {
        foreach ([['1.01', 'SLE'], ['1.001', 'USD'], ['1', 'ZZZ']] as [$amount, $currency]) {
            try {
                $this->payments->createIntent('invalid-amount', 'invalid-order', $amount, $currency);
                self::fail('Unsupported currency precision reached a financial command.');
            } catch (EvidenceException) {
                self::assertSame([], $this->requests);
                self::assertSame(0, Operation::count());
                self::assertSame(0, Intent::count());
            }
        }
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
        // A revoked Consent does not detach an otherwise usable Method from an explicit CIT action.
        Instrument::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id,
            'remote_id' => 'revoked-consent', 'method_id' => 'method-owned', 'status' => 'VERIFIED', 'method_status' => 'CREATED', 'method_type' => 'card',
            'revoked_at' => now(), 'snapshot' => []]);
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

    #[DataProvider('lostResponseChoices')]
    public function test_timeout_retains_returned_or_operator_known_identity_without_mutation_replay(bool $retry): void
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
        if ($retry) {
            try {
                $this->payments->createIntent('replacement', 'order', '10', 'USD');
                self::fail('A different key released an unknown Intent/order reservation.');
            } catch (ConflictException) {
                self::assertSame($writes, $this->writes());
                self::assertSame('ambiguous', $operation->fresh()->status);
                self::assertSame('order', Intent::sole()->order_reservation);
            }
            app(CreationRetries::class)->allowRetry($operation, 'consumer', 'explicit replacement');
            $replacement = $this->payments->createIntent('replacement', 'order', '10', 'USD');
            self::assertSame($writes + 1, $this->writes());
            self::assertSame('superseded', $operation->fresh()->status);
            $history = Intent::where('operation_id', $operation->id)->firstOrFail();
            self::assertNull($history->order_reservation);
            self::assertSame('order', $history->merchant_order_id);
            self::assertSame('order', $replacement->order_reservation);
            self::assertSame(2, Intent::count());

            return;
        }
        $result = $this->payments->recoverIntent($operation->id, 'intent-owned');
        self::assertSame('intent-owned', $result->remote_id);
        self::assertFalse($result->paid());
        self::assertSame($writes, $this->writes());
        self::assertSame('succeeded', $operation->fresh()->status);
    }

    public static function lostResponseChoices(): array
    {
        return [[false], [true]];
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
            self::assertArrayNotHasKey('triggered_by', $operation->intent['body']);
            self::assertSame('consent-owned', $operation->intent['body']['payment_consent_id']);
            $state['status'] = 'REQUIRES_CUSTOMER_ACTION';
            $state['next_action'] = ['type' => 'redirect_iframe', 'method' => 'GET', 'url' => 'https://example.test/synthetic-3ds?private=challenge'];
            $state['latest_payment_attempt'] = ['id' => 'attempt-original', 'authentication_data' => ['proof' => 'private-acs-proof']];
            $this->attempts['attempt-original'] = ['id' => 'attempt-original', 'payment_intent_id' => 'intent-owned', 'currency' => 'USD',
                'status' => 'AUTHENTICATION_REDIRECTED', 'payment_consent_id' => 'consent-owned', 'payment_method' => ['id' => 'method-owned']];
        };
        $actionRequired = $this->payments->confirmIntent($intent, 'saved-charge', 'consent-owned');
        self::assertFalse($actionRequired->paid());
        self::assertSame('customer_action_required', $this->payments->paymentAttempt($actionRequired)?->outcome());
        $writes = $this->writes();

        $this->beforeRead = function () use ($actionRequired): void {
            $this->beforeRead = null;
            self::assertFalse($this->payments->continueIntent($actionRequired)->paid());
        };
        try {
            $this->payments->savedCardChallenge($actionRequired, 'consent-owned');
        } catch (ConflictException) {
            // The first read was overtaken. Retry against fresh canonical evidence.
        }
        $challenge = $this->payments->savedCardChallenge($actionRequired, 'consent-owned');
        self::assertSame('intent-owned', $challenge->expose()['payment_intent_id']);
        self::assertSame('attempt-original', $challenge->expose()['payment_attempt_id']);
        self::assertSame('redirect_iframe', $challenge->expose()['type']);
        self::assertSame('https://example.test/synthetic-3ds?private=challenge', $challenge->expose()['url']);
        self::assertStringNotContainsString('example.test', json_encode($challenge, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('example.test', var_export($challenge->__debugInfo(), true));
        self::assertArrayNotHasKey('next_action', $actionRequired->fresh()->snapshot);
        self::assertArrayNotHasKey('authentication_data', $actionRequired->fresh()->snapshot['latest_payment_attempt']);
        self::assertStringNotContainsString('private=', json_encode(Observation::all()->pluck('payload'), JSON_THROW_ON_ERROR));
        try {
            serialize($challenge);
            self::fail('Challenge URL must be unavailable through PHP serialization.');
        } catch (\LogicException) {
            self::assertSame($writes, $this->writes());
        }
        $embedded = $this->state['latest_payment_attempt'];
        unset($this->state['latest_payment_attempt']);
        try {
            $this->payments->savedCardChallenge($actionRequired, 'consent-owned');
            self::fail('A retained previous attempt cannot prove a fresh challenge.');
        } catch (EvidenceException) {
            self::assertSame('attempt-original', $this->payments->paymentAttempt($actionRequired)?->id);
        }
        $this->state['latest_payment_attempt'] = $embedded;
        $this->attempts['attempt-original']['payment_method']['id'] = 'different-method';
        try {
            $this->payments->savedCardChallenge($actionRequired, 'consent-owned');
            self::fail('Different card passed challenge proof.');
        } catch (OwnershipException) {
            self::assertSame($writes, $this->writes());
        }

        // Simulate the issuer's completion of the original browser challenge.
        $this->attempts['attempt-original']['payment_method']['id'] = 'method-owned';
        $this->attempts['attempt-original']['status'] = 'PAID';
        $this->advance(['status' => 'SUCCEEDED', 'captured_amount' => '10.00']);
        $complete = $this->payments->continueIntent($actionRequired);
        self::assertTrue($complete->paid());
        self::assertTrue($this->payments->continueIntent($actionRequired)->paid());
        self::assertSame('captured', $this->payments->paymentAttempt($complete)?->outcome());
        self::assertSame($actionRequired->id, $complete->id);
        self::assertSame('10.00', $complete->captured_amount);
        self::assertSame(1, Observation::where('resource_type', 'airwallex_payment_intents')->get()
            ->filter(fn (Observation $observation) => $observation->payload['status'] === 'SUCCEEDED')->count());
        self::assertSame($writes, $this->writes());
        Instrument::query()->where('remote_id', 'consent-owned')->update(['revoked_at' => now()]);
        MethodObservation::query()->where('remote_id', 'method-owned')->update(['status' => 'DISABLED', 'provider_updated_at' => '2026-11-11T12:01:00Z']);
        $beforeRepeat = count($this->requests);
        self::assertTrue($this->payments->confirmIntent($complete, 'saved-charge', 'consent-owned')->paid());
        foreach (array_slice($this->requests, $beforeRepeat) as $request) {
            self::assertSame('GET', $request->getMethod());
            self::assertStringContainsString('/payment_intents/', $request->getUri()->getPath());
        }
        try {
            $this->payments->confirmIntent($complete, 'new-charge-after-disable', 'consent-owned');
            self::fail('A fresh command bypassed revoked/disabled instrument proof.');
        } catch (EvidenceException) {
            self::assertSame($writes, $this->writes());
        }
    }

    public function test_stale_created_method_cannot_bypass_retained_disable_for_customer_present_payment(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        MethodObservation::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id,
            'remote_id' => 'method-owned', 'status' => 'DISABLED', 'method_type' => 'card', 'provider_updated_at' => '2026-11-11T12:01:00Z']);
        $writes = $this->writes();
        try {
            $this->payments->confirmIntentWithMethod($intent, 'stale-method-charge', 'method-owned');
            self::fail('A stale CREATED Method erased a known disable.');
        } catch (EvidenceException) {
            self::assertSame($writes, $this->writes());
            self::assertSame('DISABLED', MethodObservation::first()->status);
            self::assertNull(Operation::where('action_key', 'stale-method-charge')->first());
        }
    }

    public function test_customer_present_claim_rechecks_global_method_disable_after_preflight(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        $this->beforeRead = function (): void {
            if (Operation::where('kind', 'intent.confirm')->where('status', 'prepared')->exists()) {
                MethodObservation::query()->where('remote_id', 'method-owned')->update(['status' => 'DISABLED', 'provider_updated_at' => '2026-11-11T12:01:00Z']);
            }
        };
        $writes = $this->writes();
        try {
            $this->payments->confirmIntentWithMethod($intent, 'disabled-before-claim', 'method-owned');
            self::fail('A Method disabled before the claim reached payment confirmation.');
        } catch (EvidenceException) {
            $operation = Operation::where('action_key', 'disabled-before-claim')->firstOrFail();
            self::assertSame($writes, $this->writes());
            self::assertSame('failed', $operation->status);
            self::assertNull($operation->lock_key);
        }
    }

    #[DataProvider('attemptOutcomes')]
    public function test_canonical_latest_and_explicit_attempt_distinguish_prior_failure_from_no_attempt(array $details, string $outcome): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        self::assertNull($this->payments->paymentAttempt($intent));
        $this->attempts['attempt-one'] = ['id' => 'attempt-one', 'payment_intent_id' => 'intent-owned', 'currency' => 'USD',
            'payment_method' => ['card' => ['brand' => 'visa']], ...$details];
        $this->advance(['latest_payment_attempt' => ['id' => 'attempt-one', 'status' => 'FAKE_EMBEDDED_STATUS']]);
        $attempt = $this->payments->paymentAttempt($intent);
        self::assertNotNull($attempt);
        self::assertSame('attempt-one', $attempt->id);
        self::assertSame('intent-owned', $attempt->paymentIntentId);
        self::assertSame($details['status'], $attempt->status);
        self::assertSame($outcome, $attempt->outcome());
        self::assertSame($details['failure_details']['code'] ?? null, $attempt->declineCode);
        self::assertSame($details['failure_details']['message'] ?? null, $attempt->reason);
        self::assertEquals($attempt, $this->payments->paymentAttempt($intent, 'attempt-one'));
        self::assertSame('REQUIRES_PAYMENT_METHOD', $intent->fresh()->status);
        $this->attempts['attempt-one']['payment_intent_id'] = 'foreign-intent';
        $this->expectException(OwnershipException::class);
        $this->payments->paymentAttempt($intent, 'attempt-one');
    }

    public static function attemptOutcomes(): array
    {
        return [
            'temporary decline' => [['status' => 'FAILED', 'failure_code' => 'provider_unavailable', 'failure_details' => ['code' => 'provider_unavailable', 'message' => 'Issuer unavailable']], 'temporary_decline'],
            'unusable card' => [['status' => 'FAILED', 'failure_code' => 'authorization_failed', 'provider_original_response_code' => '54'], 'unusable_card'],
            'unresolved' => [['status' => 'PENDING_AUTHORIZATION'], 'pending'],
            'new decline' => [['status' => 'FAILED', 'failure_details' => ['code' => 'new_issuer_code', 'message' => 'Unfamiliar failure']], 'failed'],
            'new state' => [['status' => 'NEW_STATE'], 'unrecognized'],
        ];
    }

    public function test_timeout_and_unresolved_attempt_keep_the_original_confirmation_without_a_second_charge(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        $this->afterWrite = function (Operation $operation, array &$state): never {
            self::assertSame('intent.confirm', $operation->kind);
            $state['latest_payment_attempt'] = ['id' => 'attempt-original'];
            $this->attempts['attempt-original'] = ['id' => 'attempt-original', 'payment_intent_id' => 'intent-owned', 'currency' => 'USD', 'status' => 'RECEIVED'];
            $this->advance([]);
            throw new RuntimeException('Synthetic lost confirmation response.');
        };
        try {
            $this->payments->confirmIntentWithMethod($intent, 'original', 'method-owned');
            self::fail('The synthetic lost response should retain the original action.');
        } catch (AmbiguousOperationException $error) {
            $operation = Operation::findOrFail($error->operationId);
        }
        $this->afterWrite = null;
        $writes = $this->writes();
        self::assertSame($intent->id, $this->payments->recoverIntent($operation->id)->id);
        self::assertSame('acknowledged', $operation->fresh()->status);
        self::assertSame('pending', $this->payments->paymentAttempt($intent)?->outcome());
        try {
            $this->payments->confirmIntentWithMethod($intent, 'replacement', 'method-owned');
            self::fail('An unresolved payment was charged again.');
        } catch (ConflictException) {
            self::assertSame($writes, $this->writes());
        }
        $this->attempts['attempt-original']['status'] = 'FAILED';
        self::assertSame($intent->id, $this->payments->recoverIntent($operation->id)->id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame($writes, $this->writes());
    }

    public function test_previous_failed_attempt_cannot_release_new_confirmation_hold_until_distinct_attempt_is_visible(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        $this->attempts['attempt-old'] = ['id' => 'attempt-old', 'payment_intent_id' => 'intent-owned', 'status' => 'FAILED'];
        $this->advance(['latest_payment_attempt' => ['id' => 'attempt-old']]);
        $this->payments->confirmIntentWithMethod($intent, 'confirmation', 'method-owned');
        $operation = Operation::where('action_key', 'confirmation')->sole();
        self::assertSame(['attempt_id' => 'attempt-old', 'consent_method_id' => null], $operation->confirmation_baseline);
        self::assertSame('acknowledged', $operation->status);
        $writes = $this->writes();
        try {
            $this->payments->confirmIntentWithMethod($intent, 'competing-confirmation', 'method-owned');
            self::fail('A previous failed attempt released the new confirmation hold.');
        } catch (ConflictException) {
            self::assertSame($writes, $this->writes());
        }

        $this->attempts['attempt-new'] = ['id' => 'attempt-new', 'payment_intent_id' => 'intent-owned', 'status' => 'FAILED'];
        $this->state['latest_payment_attempt'] = ['id' => 'attempt-new'];
        try {
            $this->payments->paymentAttempt($intent);
            self::fail('Different attempt identity at the same version was accepted.');
        } catch (EvidenceException) {
            self::assertSame('attempt-old', $intent->fresh()->snapshot['latest_payment_attempt']['id']);
        }
        $this->advance([]);
        self::assertSame($intent->id, $this->payments->recoverIntent($operation->id)->id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame('attempt-new', $this->payments->paymentAttempt($intent)?->id);
        self::assertSame($writes, $this->writes());
    }

    public function test_legacy_prepared_consent_command_keeps_its_identity_with_corrected_provider_body(): void
    {
        $intent = $this->payments->createIntent('create', 'order', '10', 'USD');
        Instrument::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id,
            'remote_id' => 'consent-owned', 'method_id' => 'method-owned', 'status' => 'VERIFIED', 'method_status' => 'CREATED', 'method_type' => 'card',
            'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled', 'snapshot' => ['consent' => ['updated_at' => '2026-11-11T12:00:00Z']]]);
        $oldBody = ['payment_consent_id' => 'consent-owned', 'triggered_by' => 'merchant'];
        $legacy = app(Operations::class)->prepare($this->customer, 'legacy', 'intent.confirm',
            ['body' => $oldBody, 'before_captured' => '0.00'], $intent->remote_id, 'customer/'.$this->customer->id, $oldBody);
        $this->afterWrite = function (Operation $operation, array &$state) use ($legacy): void {
            self::assertSame($legacy->id, $operation->id);
            self::assertSame($legacy->request_id, $operation->request_id);
            self::assertSame('method-owned', $operation->confirmation_baseline['consent_method_id']);
            $state['status'] = 'REQUIRES_CAPTURE';
        };
        $confirmed = $this->payments->confirmIntent($intent, 'legacy', 'consent-owned');
        self::assertSame('REQUIRES_CAPTURE', $confirmed->status);
        $request = collect($this->requests)->first(fn (RequestInterface $request) => str_ends_with($request->getUri()->getPath(), '/confirm'));
        $wire = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['payment_consent_id' => 'consent-owned', 'request_id' => $legacy->request_id], $wire);
        self::assertSame($oldBody, $legacy->fresh()->intent['body']);
        $writes = $this->writes();
        self::assertSame($intent->id, $this->payments->confirmIntent($intent, 'legacy', 'consent-owned')->id);
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
