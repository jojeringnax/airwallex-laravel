<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Closure;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\BrowserSetup;
use Jojeringnax\AirwallexLaravel\Payments\Client;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Instruments;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\MethodObservation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class PaymentsInstrumentTest extends TestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['airwallex.payments.account_id' => 'account-payments', 'airwallex.payments.client_id' => 'payments-client', 'airwallex.payments.api_key' => 'payments-key']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->customer = Customer::create([...app(Context::class)->attributes(), 'billable_type' => 'instrument_owner', 'billable_id' => 'one', 'remote_id' => 'cus-one', 'merchant_customer_id' => 'merchant-one', 'snapshot' => []]);
    }

    private function consent(array $overrides = []): array
    {
        $consent = array_replace(['id' => 'cst-one', 'customer_id' => 'cus-one', 'status' => 'VERIFIED', 'request_id' => 'synthetic-original',
            'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled', 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-11T09:00:00Z',
            'payment_method' => ['id' => 'mtd-one', 'type' => 'card'], 'metadata' => []], $overrides);
        if (($consent['payment_method'] ?? null) === null) {
            unset($consent['payment_method']);
        }

        return $consent;
    }

    private function method(array $overrides = []): array
    {
        return array_replace(['id' => 'mtd-one', 'customer_id' => 'cus-one', 'status' => 'CREATED', 'type' => 'card', 'updated_at' => '2026-11-11T09:00:00Z',
            'card' => ['last4' => '0008', 'brand' => 'visa', 'expiry_month' => '12', 'expiry_year' => '2030']], $overrides);
    }

    private function seedInstrument(): Instrument
    {
        return Instrument::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'remote_id' => 'cst-one', 'status' => 'VERIFIED', 'method_id' => 'mtd-one',
            'method_status' => 'CREATED', 'method_type' => 'card', 'next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled', 'snapshot' => [], 'revision' => 0]);
    }

    private function transport(Closure $response): object
    {
        app('cache')->flush();
        $http = new class($response) implements ClientInterface
        {
            public array $requests = [];

            public function __construct(private Closure $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                if (str_ends_with($request->getUri()->getPath(), '/authentication/login')) {
                    return FakeHttpClient::login();
                }

                return ($this->response)($request);
            }
        };
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }

    public function test_create_only_secret_is_ephemeral_and_same_key_recovery_reads_without_recreating_setup(): void
    {
        $http = $this->transport(function (RequestInterface $request): ResponseInterface {
            self::assertSame(0, DB::transactionLevel());
            $operation = Operation::firstOrFail();
            $response = $this->consent([...$operation->intent, 'request_id' => $operation->request_id, 'status' => 'REQUIRES_PAYMENT_METHOD', 'payment_method' => null]);
            if ($request->getMethod() === 'POST') {
                self::assertSame('in_flight', $operation->status);
                $response['client_secret'] = 'SYNTHETIC-SECRET-NEVER-PERSIST';
            }

            return FakeHttpClient::json($response);
        });
        $setup = app(Instruments::class)->createSetup($this->customer, 'setup-command');
        self::assertInstanceOf(BrowserSetup::class, $setup);
        self::assertTrue($setup->hasClientSecret());
        self::assertSame('SYNTHETIC-SECRET-NEVER-PERSIST', $setup->expose()['client_secret']);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', json_encode($setup, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SYNTHETIC-SECRET', print_r($setup, true));
        self::assertStringNotContainsString('SYNTHETIC-SECRET', DB::table('airwallex_payment_instruments')->value('snapshot'));
        self::assertStringNotContainsString('SYNTHETIC-SECRET', DB::table('airwallex_payment_operations')->value('intent'));
        self::assertSame('succeeded', Operation::first()->status);
        $again = app(Instruments::class)->createSetup($this->customer, 'setup-command');
        self::assertSame($setup->instrument->id, $again->instrument->id);
        self::assertFalse($again->hasClientSecret());
        self::assertSame(1, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/create'))));
        $this->expectException(ConflictException::class);
        $again->expose();
    }

    public function test_forged_callback_and_foreign_customer_account_or_method_never_change_mapping(): void
    {
        $instrument = $this->seedInstrument();
        $cases = [[$this->consent(['customer_id' => 'cus-foreign']), $this->method()],
            [$this->consent(['account_id' => 'foreign']), $this->method()],
            [$this->consent(), $this->method(['customer_id' => 'cus-foreign'])]];
        foreach ($cases as [$consent, $method]) {
            $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $method : $consent));
            try {
                app(Instruments::class)->complete($this->customer, 'cst-one');
                self::fail('Foreign canonical instrument was accepted.');
            } catch (OwnershipException) {
                self::assertSame(0, $instrument->fresh()->revision);
                self::assertSame('mtd-one', $instrument->fresh()->method_id);
            }
        }
        $http = $this->transport(fn () => throw new RuntimeException('No request expected'));
        try {
            app(Instruments::class)->complete($this->customer, 'cst-forged');
            self::fail('Unknown callback created an association.');
        } catch (OwnershipException) {
            self::assertSame([], $http->requests);
            self::assertSame(1, Instrument::count());
        }
        try {
            app(Instruments::class)->verify($this->customer, 'raw-card', 'cst-one', 'mtd-one', ['card' => ['number' => 'SYNTHETIC-RAW-CARD', 'cvc' => 'SYNTHETIC-CVC']]);
            self::fail('Raw card input entered a durable intent.');
        } catch (ConflictException) {
            self::assertSame(0, Operation::count());
            self::assertSame([], $http->requests);
        }
    }

    public function test_duplicate_completion_and_default_use_canonical_card_state_without_financial_mutations(): void
    {
        $instrument = $this->seedInstrument();
        $status = 'VERIFIED';
        $http = $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent(['status' => $status])));
        app(Instruments::class)->setDefault($this->customer, $instrument);
        foreach ([1, 2] as $repeat) {
            self::assertSame($instrument->id, app(Instruments::class)->verified($this->customer, 'cst-one')->id);
        }
        self::assertSame($instrument->id, app(Instruments::class)->default($this->customer)->id);
        self::assertSame(['POST', 'GET'], array_values(array_unique(array_map(fn ($request) => $request->getMethod(), $http->requests))));
        self::assertSame(0, Operation::count());
        // Fresh local reservation checks are DB-only and reject local revocation.
        $instrument->refresh()->update(['revoked_at' => now()->utc()]);
        $count = count($http->requests);
        try {
            DB::transaction(fn () => app(Instruments::class)->assertUsableReservation($this->customer, 'cst-one'));
            self::fail('Locally revoked consent was reserved.');
        } catch (EvidenceException) {
            self::assertCount($count, $http->requests);
        }
    }

    public function test_ambiguous_verification_never_replays_and_recovers_only_canonical_original_request(): void
    {
        $this->seedInstrument();
        $remote = $this->consent(['status' => 'REQUIRES_PAYMENT_METHOD']);
        $timeout = true;
        $http = $this->transport(function (RequestInterface $request) use (&$remote, &$timeout): ResponseInterface {
            if ($request->getMethod() === 'POST') {
                if ($timeout) {
                    throw new RuntimeException('Synthetic verification timeout');
                }
            }

            return FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $remote);
        });
        try {
            app(Instruments::class)->verify($this->customer, 'verify-command', 'cst-one', 'mtd-one');
            self::fail('Expected uncertain verification.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::first()->status);
        }
        $original = Operation::first();
        $count = count($http->requests);
        try {
            app(Instruments::class)->createSetup($this->customer, 'replacement-setup');
            self::fail('An unresolved verification permitted a competing customer action.');
        } catch (ConflictException) {
            self::assertCount($count, $http->requests);
            self::assertSame(1, Operation::count());
        }
        try {
            app(Instruments::class)->verify($this->customer, 'verify-command', 'cst-one', 'mtd-one');
            self::fail('Uncorrelated state proved verification.');
        } catch (AmbiguousOperationException) {
            self::assertSame($original->request_id, Operation::first()->request_id);
        }
        $remote = $this->consent(['status' => 'REQUIRES_CUSTOMER_ACTION', 'updated_at' => '2026-11-11T09:01:00Z', 'request_id' => $original->request_id]);
        $setup = app(Instruments::class)->verify($this->customer, 'verify-command', 'cst-one', 'mtd-one');
        self::assertSame('REQUIRES_CUSTOMER_ACTION', $setup->instrument->status);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame(1, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/verify'))));
    }

    public function test_disable_timeout_clears_default_and_late_canonical_reads_cannot_restore_local_revocation(): void
    {
        $instrument = $this->seedInstrument();
        $http = $this->transport(function (RequestInterface $request): ResponseInterface {
            if ($request->getMethod() === 'POST') {
                throw new RuntimeException('Synthetic disable timeout');
            }

            return FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent());
        });
        app(Instruments::class)->setDefault($this->customer, $instrument);
        try {
            app(Instruments::class)->disable($this->customer, 'disable-command', 'cst-one');
            self::fail('Expected ambiguous disable.');
        } catch (AmbiguousOperationException) {
            self::assertNotNull($instrument->fresh()->revoked_at);
            self::assertNull(DB::table('airwallex_payment_instrument_defaults')->value('instrument_id'));
        }
        app(Instruments::class)->complete($this->customer, 'cst-one');
        self::assertNotNull($instrument->fresh()->revoked_at);
        self::assertSame(1, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/disable'))));
        $this->expectException(EvidenceException::class);
        app(Instruments::class)->verified($this->customer, 'cst-one');
    }

    public function test_late_observation_cannot_replace_a_newer_projection_or_racing_revision(): void
    {
        $instrument = $this->seedInstrument();
        $remote = $this->consent();
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $remote));
        app(Instruments::class)->complete($this->customer, 'cst-one');
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent(['status' => 'DISABLED', 'updated_at' => '2026-11-11T08:00:00Z'])));
        try {
            app(Instruments::class)->complete($this->customer, 'cst-one');
            self::fail('Stale consent supplied fresh eligibility proof.');
        } catch (EvidenceException) {
            self::assertSame('VERIFIED', $instrument->fresh()->status);
        }
        $this->transport(function (RequestInterface $request) use ($instrument, $remote): ResponseInterface {
            if (str_contains($request->getUri()->getPath(), '/payment_consents/')) {
                Instrument::query()->whereKey($instrument->id)->increment('revision');
            }

            return FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $remote);
        });
        $this->expectException(ConflictException::class);
        app(Instruments::class)->complete($this->customer, 'cst-one');
    }

    public function test_unknown_setup_creation_recovers_with_owned_marker_without_reissuing_create_or_secret(): void
    {
        $failed = true;
        $http = $this->transport(function (RequestInterface $request) use (&$failed): ResponseInterface {
            if ($request->getMethod() === 'POST' && $failed) {
                throw new RuntimeException('Synthetic timeout before response identity');
            }
            $operation = Operation::firstOrFail();

            return FakeHttpClient::json($this->consent([...$operation->intent, 'request_id' => $operation->request_id]));
        });
        try {
            app(Instruments::class)->createSetup($this->customer, 'lost-setup');
            self::fail('Expected unknown setup outcome.');
        } catch (AmbiguousOperationException) {
            self::assertNull(Operation::first()->remote_id);
            self::assertSame(0, Instrument::count());
        }
        // Canonical method read is distinct from consent metadata retrieval.
        $this->transport(function (RequestInterface $request): ResponseInterface {
            $operation = Operation::firstOrFail();

            return FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent([...$operation->intent, 'request_id' => $operation->request_id]));
        });
        $instrument = app(Instruments::class)->recover(Operation::first(), 'cst-one');
        self::assertSame('cst-one', $instrument->remote_id);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame('cst-one', Operation::first()->remote_id);
        self::assertSame(1, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/create'))));
    }

    public function test_all_consents_uses_all_customer_scoped_pages_and_rejects_repeated_identity_before_adoption(): void
    {
        $page = 0;
        $this->transport(function (RequestInterface $request) use (&$page): ResponseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/payment_consents')) {
                parse_str($request->getUri()->getQuery(), $query);
                self::assertSame('cus-one', $query['customer_id']);
                self::assertSame((string) $page, $query['page_num']);
                $id = $page++ === 0 ? 'cst-one' : 'cst-two';

                return FakeHttpClient::json(['items' => [$this->consent(['id' => $id])], 'has_more' => $page === 1]);
            }

            return FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent(['id' => basename($request->getUri()->getPath())]));
        });
        self::assertCount(2, app(Instruments::class)->all($this->customer));
        self::assertSame(2, Instrument::count());
        $this->transport(fn () => FakeHttpClient::json(['items' => [$this->consent(['id' => 'cst-new'])], 'has_more' => true]));
        try {
            app(Instruments::class)->all($this->customer);
            self::fail('A repeated list page published partial instruments.');
        } catch (OwnershipException) {
            self::assertSame(2, Instrument::count());
        }
    }

    public function test_equal_consent_version_cannot_change_method_or_upgrade_merchant_eligibility_and_missing_version_fails(): void
    {
        $this->seedInstrument();
        $remote = $this->consent(['next_triggered_by' => 'customer']);
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $remote));
        app(Instruments::class)->complete($this->customer, 'cst-one');
        foreach ([$this->consent(), $this->consent(['next_triggered_by' => 'customer', 'payment_method' => ['id' => 'mtd-other', 'type' => 'card']])] as $changed) {
            $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method(['id' => $changed['payment_method']['id']]) : $changed));
            try {
                app(Instruments::class)->verified($this->customer, 'cst-one');
                self::fail('Equal-version eligibility changed.');
            } catch (EvidenceException) {
                self::assertSame('customer', Instrument::first()->next_triggered_by);
                self::assertSame('mtd-one', Instrument::first()->method_id);
            }
        }
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? array_diff_key($this->method(), ['updated_at' => true]) : $remote));
        $this->expectException(EvidenceException::class);
        app(Instruments::class)->complete($this->customer, 'cst-one');
    }

    public function test_fresh_method_disable_survives_stale_consent_and_older_method_cannot_restore_eligibility(): void
    {
        $instrument = $this->seedInstrument();
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent()));
        app(Instruments::class)->complete($this->customer, 'cst-one');
        $disabled = $this->method(['status' => 'DISABLED', 'updated_at' => '2026-11-11T09:01:00Z']);
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $disabled : $this->consent(['updated_at' => '2026-11-11T08:00:00Z'])));
        try {
            app(Instruments::class)->verified($this->customer, 'cst-one');
            self::fail('Stale consent returned retained CREATED method eligibility.');
        } catch (EvidenceException) {
            self::assertSame('CREATED', $instrument->fresh()->method_status);
            self::assertSame('DISABLED', MethodObservation::first()->status);
        }
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent()));
        try {
            app(Instruments::class)->verified($this->customer, 'cst-one');
            self::fail('Discarded Consent proof erased independently observed Method disable.');
        } catch (EvidenceException) {
            self::assertSame('DISABLED', MethodObservation::first()->status);
        }
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $disabled : $this->consent()));
        app(Instruments::class)->complete($this->customer, 'cst-one');
        self::assertSame('DISABLED', $instrument->fresh()->method_status);
        $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent()));
        try {
            app(Instruments::class)->verified($this->customer, 'cst-one');
            self::fail('Older CREATED method overwrote known disable.');
        } catch (EvidenceException) {
            self::assertSame('DISABLED', $instrument->fresh()->method_status);
        }
    }

    public function test_billing_source_bridge_requires_same_collector_account_even_when_opaque_method_id_matches(): void
    {
        $this->seedInstrument();
        $billing = \Jojeringnax\AirwallexLaravel\Models\Customer::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'billable_type' => 'instrument_owner', 'billable_id' => 'one', 'remote_id' => 'billing-one']);
        $this->transport(function (RequestInterface $request): ResponseInterface {
            $path = $request->getUri()->getPath();
            if (str_contains($path, '/billing/payment_sources/')) {
                return FakeHttpClient::json(['id' => 'source-one', 'billing_customer_id' => 'billing-one', 'linked_payment_account_id' => 'collector-test', 'external_id' => 'mtd-one', 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-11T09:00:00Z']);
            }

            return FakeHttpClient::json(str_contains($path, '/payment_methods/') ? $this->method() : $this->consent());
        });
        $this->expectException(OwnershipException::class);
        app(Instruments::class)->billingSource($this->customer, 'cst-one', $billing, 'source-one');
    }

    public function test_failed_expired_or_customer_triggered_consents_are_not_saved_charge_eligibility(): void
    {
        $instrument = $this->seedInstrument();
        foreach ([$this->consent(['status' => 'EXPIRED']), $this->consent(['status' => 'REQUIRES_PAYMENT_METHOD']), $this->consent(['next_triggered_by' => 'customer'])] as $remote) {
            $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $remote));
            try {
                app(Instruments::class)->verified($this->customer, 'cst-one');
                self::fail('Unusable consent was accepted for a saved-card charge.');
            } catch (EvidenceException) {
                self::assertSame(0, Operation::count());
            }
            $instrument->refresh()->update(['snapshot' => []]);
        }
    }

    public function test_offboarded_customer_retains_canonical_consent_reads_and_original_recovery_while_new_actions_stay_blocked(): void
    {
        $instrument = $this->seedInstrument();
        $operation = Operation::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'kind' => 'consent.verify', 'action_key' => 'original-verify', 'target_id' => 'cst-one',
            'request_id' => 'f43ba121-f0b8-47bb-9d61-b432a777416c', 'intent_hash' => hash('sha256', 'original-verify'), 'intent' => ['payment_method' => ['id' => 'mtd-one', 'type' => 'card']],
            'status' => 'ambiguous', 'lock_key' => 'customer/'.$this->customer->id]);
        $http = $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/payment_methods/') ? $this->method() : $this->consent(['request_id' => $operation->request_id])));
        $this->customer->update(['disabled_at' => now()->utc()]);
        self::assertSame($instrument->id, app(Instruments::class)->complete($this->customer, 'cst-one')->id);
        self::assertSame($instrument->id, app(Instruments::class)->recover($operation)->id);
        self::assertSame('succeeded', $operation->fresh()->status);
        $count = count($http->requests);
        foreach ([fn () => app(Instruments::class)->createSetup($this->customer, 'new-setup'),
            fn () => app(Instruments::class)->disable($this->customer, 'new-disable', 'cst-one'),
            fn () => app(Instruments::class)->verified($this->customer, 'cst-one'),
            fn () => app(Instruments::class)->assertUsableReservation($this->customer, 'cst-one'),
            fn () => app(Instruments::class)->assertMethodReservation($this->customer, 'mtd-one')] as $action) {
            try {
                $action();
                self::fail('Offboarded customer started a fresh setup, mutation or charge reservation.');
            } catch (ConflictException) {
                self::assertCount($count, $http->requests);
            }
        }
        self::assertSame(1, Operation::count());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }
}
