<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\PaymentSource;
use Jojeringnax\AirwallexLaravel\Services\CheckoutManager;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;

class CheckoutWorkflowTest extends TestCase
{
    private Team $team;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        $this->team = Team::create(['id' => 'team']);
        $this->customer = app(OperationManager::class)->reserveCustomer($this->team);
        $this->customer->update(['remote_id' => 'customer-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fake(array $responses): FakeHttpClient
    {
        app('cache')->flush();
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        $this->app->instance(ClientInterface::class, $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }

    private function checkoutOptions(): array
    {
        return ['success_url' => 'https://app.example.test/success', 'back_url' => 'https://app.example.test/back'];
    }

    private function create(): Checkout
    {
        $this->fake([FakeHttpClient::json(Billing::checkout()), FakeHttpClient::json(Billing::checkout())]);

        return $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
    }

    #[DataProvider('managedCouponCheckoutModes')]
    public function test_managed_coupon_checkout_proves_frozen_terms_and_original_discount_before_subscription_association(bool $raw, bool $missing, bool $flat = false): void
    {
        $coupon = ['id' => 'coupon-checkout', 'active' => true, 'discount_model' => $flat ? 'FLAT' : 'PERCENTAGE', 'percentage_off' => $flat ? null : 25, 'amount_off' => $flat ? '5.50' : null, 'currency' => $flat ? 'USD' : null, 'duration_type' => 'ONCE', 'duration' => null];
        $instructions = [['type' => 'COUPON', 'coupon' => ['id' => $coupon['id']]]];
        $remote = Billing::checkout(['discounts' => $instructions, 'currency' => 'USD']);
        $prices = $flat ? [FakeHttpClient::json(['id' => 'price-test', 'currency' => 'USD'])] : [];
        $http = $this->fake([FakeHttpClient::json($coupon), ...$prices, FakeHttpClient::json($coupon), ...$prices, FakeHttpClient::json($remote), FakeHttpClient::json($remote)]);
        $options = [...$this->checkoutOptions(), ...($raw ? ['discounts' => $instructions] : [])];
        $builder = $this->team->newSubscription('coupon-checkout', 'price-test');
        if (! $raw) {
            $builder->withCoupon($coupon['id']);
        }
        $checkout = $builder->checkout('coupon-checkout', $options);
        $operation = Operation::sole();
        self::assertSame('ONCE', $operation->intent['coupon_terms']['duration_type']);
        self::assertSame($instructions, json_decode((string) $http->requests[$flat ? 5 : 3]->getBody(), true)['discounts']);
        self::assertNull($operation->observation);
        self::assertSame(0, Subscription::count());
        self::assertFalse($this->team->subscribed('coupon-checkout'));
        $discount = ['id' => 'discount-checkout', 'applied_to' => 'SUBSCRIPTION', 'applied_to_id' => 'sub-coupon-checkout', 'billing_customer_id' => 'customer-test', 'source' => 'COUPON', 'source_id' => $coupon['id'], 'discount_model' => $coupon['discount_model'], 'percentage_off' => $coupon['percentage_off'], 'amount_off' => $coupon['amount_off'], 'currency' => $coupon['currency'], 'duration_type' => 'ONCE', 'duration' => null, 'created_at' => '2026-11-10T12:01:00Z', 'starts_at' => '2026-11-10T12:01:00Z'];
        $subscription = Billing::subscription(['id' => 'sub-coupon-checkout', 'applied_discounts' => $missing ? [] : [$discount]]);
        $completed = [...$remote, 'status' => 'COMPLETED', 'subscription_id' => $subscription['id'], 'updated_at' => '2026-11-10T12:01:00Z'];
        $plan = [FakeHttpClient::json($completed), FakeHttpClient::json($subscription), Billing::page([]), FakeHttpClient::json($subscription)];
        $this->fake($plan);
        if ($missing) {
            try {
                $checkout->reconcile();
                self::fail('Hosted success without Discount proof associated a subscription.');
            } catch (EvidenceException) {
                self::assertSame(0, Subscription::count());
                self::assertNull($operation->fresh()->observation);
                self::assertFalse($this->team->subscribed('coupon-checkout'));
            }
            $subscription['applied_discounts'] = [$discount];
            $this->fake([FakeHttpClient::json($completed), FakeHttpClient::json($subscription), Billing::page([]), FakeHttpClient::json($subscription)]);
        }
        $checkout = $checkout->reconcile();
        self::assertSame('discount-checkout', $operation->fresh()->observation['discount_proof']['id']);
        self::assertSame($checkout->subscription_id, $operation->fresh()->subscription_id);
        self::assertTrue($this->team->subscribed('coupon-checkout'));
        if (! $raw && ! $missing && ! $flat) {
            $removed = [...$subscription, 'applied_discounts' => [], 'updated_at' => '2026-11-10T12:02:00Z'];
            $this->fake([FakeHttpClient::json($subscription), Billing::page([]), FakeHttpClient::json($subscription), FakeHttpClient::json($removed), FakeHttpClient::json($removed), Billing::page([]), FakeHttpClient::json($removed)]);
            Subscription::sole()->removeCoupon('hosted-remove', 'coupon-checkout');
            self::assertSame('succeeded', Operation::where('action_key', 'hosted-remove')->sole()->status);
        }
        $later = [...$subscription, 'applied_discounts' => [], 'updated_at' => '2026-12-10T12:00:00Z'];
        $this->fake([FakeHttpClient::json($completed), FakeHttpClient::json($later), Billing::page([]), FakeHttpClient::json($later)]);
        $checkout->reconcile();
        self::assertSame('discount-checkout', $operation->fresh()->observation['discount_proof']['id']);
        self::assertTrue($this->team->subscribed('coupon-checkout'));
    }

    public static function managedCouponCheckoutModes(): array
    {
        return [[false, false], [false, true], [true, false], [false, false, true]];
    }

    public function test_hosted_checkout_is_redirectable_but_browser_success_never_grants_access(): void
    {
        $http = $this->fake([FakeHttpClient::json(Billing::checkout()), FakeHttpClient::json(Billing::checkout())]);
        $recipe = require __DIR__.'/../examples/checkout.php';
        $checkout = $recipe($this->team, 'checkout', 'price-test', $this->checkoutOptions());
        $body = json_decode((string) $http->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $operation = Operation::first();
        self::assertSame('SUBSCRIPTION', $body['mode']);
        self::assertSame('HOSTED', $body['ui_mode']);
        self::assertSame(3, $body['line_items'][0]['quantity']);
        self::assertSame('customer-test', $body['billing_customer_id']);
        self::assertSame($operation->request_id, $body['request_id']);
        self::assertSame($operation->id, $body['metadata']['_laravel_operation_id']);
        self::assertSame($operation->id, $body['subscription_data']['metadata']['_laravel_operation_id']);
        self::assertArrayNotHasKey('starts_at', $body['subscription_data']);
        self::assertSame('https://checkout.airwallex.com/synthetic-session', $checkout->redirect()->getTargetUrl());
        self::assertFalse($this->team->subscribed('default'));
        self::assertSame(0, Subscription::count());
        self::assertSame($operation->id, SubscriptionSlot::first()->operation_id);
        self::assertStringNotContainsString('SYNTHETIC-CLIENT-SECRET', json_encode($checkout));
        self::assertStringNotContainsString('synthetic-session', $checkout->getRawOriginal('url'));
        self::assertStringNotContainsString('SYNTHETIC-CLIENT-SECRET', json_encode($checkout->snapshot));
        self::assertSame($checkout->id, $this->team->newSubscription('default', 'price-test')->quantity(3)->checkout('checkout', $this->checkoutOptions())->id);
        self::assertCount(3, $http->requests);
    }

    public function test_completion_verifies_owned_subscription_and_repeated_completion_is_stable(): void
    {
        $checkout = $this->create();
        $completed = Billing::checkout(['status' => 'COMPLETED', 'url' => null, 'subscription_id' => 'sub-test', 'updated_at' => '2026-11-10T12:05:00Z']);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->fake([FakeHttpClient::json($completed), FakeHttpClient::json(Billing::subscription()), Billing::page([Billing::item()]), FakeHttpClient::json(Billing::subscription())]);
            $result = $checkout->reconcile();
            self::assertNotNull($result->subscription_id);
            self::assertSame('sub-test', $this->team->subscription('default')->remote_id);
            self::assertTrue($this->team->subscribed('default'));
        }
        self::assertSame(1, Subscription::count());
        self::assertNull(SubscriptionSlot::first()->operation_id);
    }

    public function test_setup_verifies_source_without_subscriptions_or_access(): void
    {
        $active = Billing::checkout(['mode' => 'SETUP']);
        $http = $this->fake([FakeHttpClient::json($active), FakeHttpClient::json($active)]);
        $recipe = require __DIR__.'/../examples/setup.php';
        $checkout = $recipe($this->team, 'setup', $this->checkoutOptions());
        $body = json_decode((string) $http->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('SETUP', $body['mode']);
        self::assertSame('USD', $body['currency']);
        self::assertArrayNotHasKey('line_items', $body);
        self::assertSame(0, SubscriptionSlot::count());
        $complete = [...$active, 'status' => 'COMPLETED', 'url' => null, 'payment_source_id' => 'source-test', 'updated_at' => '2026-11-10T12:10:00Z'];
        $this->fake([FakeHttpClient::json($complete), FakeHttpClient::json(Billing::source())]);
        self::assertSame('source-test', $checkout->reconcile()->payment_source_id);
        self::assertSame(0, Subscription::count());
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_source_listing_is_scoped_paginated_and_sanitized(): void
    {
        $http = $this->fake([Billing::page([Billing::source(['client_secret' => 'SYNTHETIC-SOURCE-SECRET'])], 'next'), Billing::page([Billing::source(['id' => 'source-two'])])]);
        $sources = $this->team->paymentSources(['page_size' => 1]);
        self::assertCount(2, $sources);
        self::assertSame('source-test', $sources[0]->id);
        self::assertStringContainsString('billing_customer_id=customer-test', (string) $http->requests[2]->getUri());
        self::assertStringContainsString('linked_payment_account_id=collector-test', (string) $http->requests[2]->getUri());
        self::assertStringContainsString('page=next', (string) $http->requests[2]->getUri());
        self::assertArrayNotHasKey('client_secret', $sources[0]->toArray());
        self::assertStringNotContainsString('SYNTHETIC-SOURCE-SECRET', json_encode($sources));
        $this->expectException(OwnershipException::class);
        $this->team->paymentSources(['billing_customer_id' => 'foreign']);
    }

    public function test_source_snapshot_is_immutable_through_nested_sdk_objects_and_constructor_inputs(): void
    {
        $this->fake([Billing::page([Billing::source(['metadata' => new \stdClass, 'extra' => ['nested' => new \stdClass]])])]);
        $source = $this->team->paymentSources()->first();
        $exported = $source->toArray();
        $exported['metadata']->changed = 'changed export';
        $exported['extra']['nested']->changed = 'changed nested export';
        self::assertSame([], get_object_vars($source->toArray()['metadata']));
        self::assertSame([], get_object_vars($source->toArray()['extra']['nested']));

        $original = new \stdClass;
        $constructed = new PaymentSource('source', 'customer', 'collector', ['nested' => [$original], 'exact' => '1.00000000000000000001']);
        $original->changed = 'changed constructor input';
        self::assertSame([], get_object_vars($constructed->toArray()['nested'][0]));
        self::assertSame('1.00000000000000000001', $constructed->toArray()['exact']);
    }

    public function test_foreign_checkout_or_source_cannot_finalize_mapping(): void
    {
        $this->fake([FakeHttpClient::json(Billing::checkout(['billing_customer_id' => 'foreign']))]);
        try {
            $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
            self::fail('Expected ambiguity');
        } catch (AmbiguousOperationException $exception) {
            self::assertSame('ownership', $exception->causeCategory);
        }
        self::assertSame(0, Checkout::count());
        self::assertSame(1, SubscriptionSlot::count());
    }

    public function test_timeout_never_replays_and_unknown_id_recovery_requires_verified_metadata(): void
    {
        $http = $this->fake([new ConnectException('synthetic timeout', new Request('POST', 'https://example.test'))]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
                self::fail('Expected ambiguity');
            } catch (AmbiguousOperationException) {
            }
        }
        self::assertCount(2, $http->requests);
        $operation = Operation::first();
        self::assertNull($operation->remote_id);
        $remote = Billing::checkout(['metadata' => ['_laravel_operation_id' => $operation->id]]);
        $this->fake([FakeHttpClient::json($remote)]);
        $checkout = app(CheckoutManager::class)->recover($operation, 'checkout-test');
        self::assertSame('checkout-test', $checkout->remote_id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_cancelled_and_expired_sessions_release_only_their_own_name(): void
    {
        foreach (['CANCELLED', 'EXPIRED'] as $index => $status) {
            $active = Billing::checkout(['id' => 'checkout-'.$index]);
            $this->fake([FakeHttpClient::json($active), FakeHttpClient::json($active)]);
            $checkout = $this->team->newSubscription('default', 'price-test')->checkout('checkout-'.$index, $this->checkoutOptions());
            $this->fake([FakeHttpClient::json([...$active, 'status' => $status, 'url' => null, 'updated_at' => '2026-11-10T12:10:00Z'])]);
            $checkout->reconcile();
            self::assertSame(0, SubscriptionSlot::count());
        }
        self::assertSame(0, Subscription::count());
    }

    public function test_terminal_checkout_with_result_identity_retains_ambiguity_and_name(): void
    {
        $checkout = $this->create();
        $this->fake([FakeHttpClient::json(Billing::checkout(['status' => 'CANCELLED', 'subscription_id' => 'sub-test', 'updated_at' => '2026-11-10T12:01:00Z']))]);
        try {
            $checkout->reconcile();
            self::fail('Expected conflicting terminal evidence');
        } catch (EvidenceException) {
        }
        self::assertSame(1, SubscriptionSlot::count());
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_metadata_update_and_bodyless_cancel_keep_creation_identity(): void
    {
        $checkout = $this->create();
        $marker = Operation::first()->id;
        $metadata = ['reference' => 'updated', '_laravel_operation_id' => $marker];
        $changed = Billing::checkout(['metadata' => $metadata, 'updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([FakeHttpClient::json(Billing::checkout()), FakeHttpClient::json($changed), FakeHttpClient::json($changed)]);
        $checkout = $checkout->updateMetadata('metadata', ['reference' => 'updated']);
        $body = json_decode((string) $http->requests[2]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($metadata, $body['metadata']);
        self::assertArrayNotHasKey('request_id', $body);
        $cancelled = [...$changed, 'status' => 'CANCELLED', 'url' => null, 'updated_at' => '2026-11-10T12:02:00Z'];
        $http = $this->fake([FakeHttpClient::json($changed), FakeHttpClient::json($cancelled), FakeHttpClient::json($cancelled)]);
        $checkout = $checkout->cancel('cancel-session');
        self::assertSame('', (string) $http->requests[2]->getBody());
        self::assertSame('CANCELLED', $checkout->status);
        self::assertSame($marker, $checkout->operation_id);
        self::assertSame(0, SubscriptionSlot::count());
    }

    public function test_foreign_setup_source_is_never_recorded_as_a_verified_link(): void
    {
        $active = Billing::checkout(['mode' => 'SETUP']);
        $this->fake([FakeHttpClient::json($active), FakeHttpClient::json($active)]);
        $checkout = $this->team->setupPaymentMethod('setup', $this->checkoutOptions());
        $complete = [...$active, 'status' => 'COMPLETED', 'payment_source_id' => 'source-test', 'updated_at' => '2026-11-10T12:10:00Z'];
        $this->fake([FakeHttpClient::json($complete), FakeHttpClient::json(Billing::source(['billing_customer_id' => 'foreign']))]);
        try {
            $checkout->reconcile();
            self::fail('Expected source ownership rejection');
        } catch (OwnershipException) {
        }
        self::assertNull($checkout->fresh()->payment_source_id);
        self::assertSame('source-test', $checkout->fresh()->remote_payment_source_id);
        self::assertSame(0, Subscription::count());
    }

    public function test_completed_checkout_cannot_adopt_a_foreign_subscription(): void
    {
        $checkout = $this->create();
        $complete = Billing::checkout(['status' => 'COMPLETED', 'subscription_id' => 'sub-test', 'updated_at' => '2026-11-10T12:01:00Z']);
        $this->fake([FakeHttpClient::json($complete), FakeHttpClient::json(Billing::subscription(['billing_customer_id' => 'foreign']))]);
        try {
            $checkout->reconcile();
            self::fail('Expected owner rejection');
        } catch (OwnershipException) {
        }
        self::assertNull($checkout->fresh()->subscription_id);
        self::assertSame(0, Subscription::count());
        self::assertSame(1, SubscriptionSlot::count());
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_explicit_unknown_id_with_wrong_correlation_remains_ambiguous(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'checkout', 'checkout.subscription', [], ['name' => 'default', 'payload' => []], name: 'default');
        $operation->update(['status' => 'ambiguous']);
        $this->fake([FakeHttpClient::json(Billing::checkout(['metadata' => ['_laravel_operation_id' => 'foreign']]))]);
        try {
            app(CheckoutManager::class)->recover($operation, 'checkout-test');
            self::fail('Expected correlation rejection');
        } catch (AmbiguousOperationException $exception) {
            self::assertSame('ownership', $exception->causeCategory);
        }
        self::assertNull($operation->fresh()->remote_id);
        self::assertSame(0, Checkout::count());
        self::assertSame(1, SubscriptionSlot::count());
    }

    public function test_optional_checkout_entity_can_be_absent_without_fabricating_observed_values(): void
    {
        $remote = Billing::checkout();
        unset($remote['legal_entity_id']);
        $http = $this->fake([FakeHttpClient::json($remote), FakeHttpClient::json($remote)]);
        $checkout = $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
        self::assertSame('succeeded', Operation::first()->status);
        self::assertArrayNotHasKey('legal_entity_id', $checkout->snapshot);
        self::assertSame('entity-test', json_decode((string) $http->requests[1]->getBody(), true)['legal_entity_id']);
        self::assertSame('https://checkout.airwallex.com/synthetic-session', $checkout->redirect()->getTargetUrl());
    }

    public function test_contradictory_checkout_response_cannot_be_resolved_by_optional_omission(): void
    {
        $remote = Billing::checkout();
        unset($remote['legal_entity_id']);
        $this->fake([FakeHttpClient::json([...$remote, 'legal_entity_id' => 'foreign'])]);
        try {
            $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
            self::fail('Contradictory response was accepted.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::first()->status);
        }
        $this->fake([FakeHttpClient::json($remote)]);
        try {
            $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
            self::fail('Omission resolved contradictory response evidence.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::first()->status);
            self::assertSame(0, Checkout::count());
        }
        $this->fake([FakeHttpClient::json(Billing::checkout())]);
        $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
        self::assertSame('succeeded', Operation::first()->status);
    }

    public function test_malformed_response_routing_retains_only_safe_unresolved_evidence(): void
    {
        $this->fake([FakeHttpClient::json(Billing::checkout(['legal_entity_id' => ['client_secret' => 'SYNTHETIC-NESTED-SECRET']]))]);
        try {
            $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
            self::fail('Malformed routing response was accepted.');
        } catch (AmbiguousOperationException) {
            $operation = Operation::first();
            self::assertSame(['legal_entity_id' => null, 'linked_payment_account_id' => 'collector-test'], $operation->observed_routing);
            self::assertArrayNotHasKey('observed_routing', $operation->toArray());
            self::assertStringNotContainsString('SYNTHETIC-NESTED-SECRET', (string) $operation->getRawOriginal('observed_routing'));
        }
    }

    public function test_required_checkout_collector_cannot_be_absent(): void
    {
        $remote = Billing::checkout();
        unset($remote['linked_payment_account_id']);
        $this->fake([FakeHttpClient::json($remote)]);
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
        } finally {
            self::assertSame(0, Checkout::count());
            self::assertSame(1, SubscriptionSlot::count());
        }
    }

    public function test_recorded_response_then_local_failure_recovers_by_reads_only(): void
    {
        config(['airwallex.models.checkout' => FailingCheckout::class]);
        FailingCheckout::$fail = true;
        $this->fake([FakeHttpClient::json(Billing::checkout()), FakeHttpClient::json(Billing::checkout())]);
        try {
            $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
            self::fail('Expected local failure');
        } catch (AmbiguousOperationException $exception) {
            self::assertStringNotContainsString('SYNTHETIC-STORAGE-SECRET', (string) $exception);
        }
        self::assertSame('checkout-test', Operation::first()->remote_id);
        self::assertSame(0, Checkout::count());
        FailingCheckout::$fail = false;
        $http = $this->fake([FakeHttpClient::json(Billing::checkout())]);
        $checkout = $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
        self::assertInstanceOf(FailingCheckout::class, $checkout);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertCount(2, $http->requests);
        self::assertSame('GET', $http->requests[1]->getMethod());
    }

    public function test_verified_early_notification_then_lost_response_never_regresses_success(): void
    {
        app('cache')->flush();
        $http = new InterleavingHttpClient([
            FakeHttpClient::login(),
            function () {
                app(CheckoutManager::class)->recover(Operation::first(), 'checkout-test');
                throw new ConnectException('response lost after verified notification', new Request('POST', 'https://example.test'));
            },
            function () {
                return FakeHttpClient::json(Billing::checkout(['metadata' => ['_laravel_operation_id' => Operation::first()->id]]));
            },
        ]);
        $this->app->instance(ClientInterface::class, $http);
        $this->app->forgetInstance(Client::class);
        try {
            $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
            self::fail('Expected transport ambiguity signal');
        } catch (AmbiguousOperationException) {
        }
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame(1, Checkout::count());
        $checkout = $this->team->newSubscription('default', 'price-test')->checkout('checkout', $this->checkoutOptions());
        self::assertSame('checkout-test', $checkout->remote_id);
        self::assertCount(3, $http->requests);
        self::assertSame(1, SubscriptionSlot::count());
    }

    public function test_metadata_maps_keep_numeric_keys_and_invalid_null_is_not_repaired(): void
    {
        $http = $this->fake([FakeHttpClient::json(Billing::checkout()), FakeHttpClient::json(Billing::checkout())]);
        $this->team->newSubscription('default', 'price-test')->checkout('checkout', [...$this->checkoutOptions(), 'metadata' => (object) ['1' => 'one']]);
        $body = json_decode((string) $http->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('one', $body['metadata'][1]);
        self::assertArrayNotHasKey(0, $body['metadata']);
        $this->fake([]);
        try {
            $this->team->setupPaymentMethod('invalid-null', [...$this->checkoutOptions(), 'metadata' => null]);
            self::fail('Expected invalid null');
        } catch (ConflictException) {
        }
        self::assertSame('failed', Operation::where('action_key', 'invalid-null')->first()->status);
    }

    public function test_cancel_ack_without_cancelled_state_remains_held_until_positive_read_proof(): void
    {
        $checkout = $this->create();
        $active = Billing::checkout();
        $this->fake([FakeHttpClient::json($active), FakeHttpClient::json($active), FakeHttpClient::json($active)]);
        try {
            $checkout->cancel('cancel');
            self::fail('Expected unconfirmed cancellation');
        } catch (AmbiguousOperationException) {
        }
        $operation = Operation::where('action_key', 'cancel')->first();
        self::assertSame('ambiguous', $operation->status);
        self::assertNotNull($operation->lock_key);
        $this->fake([FakeHttpClient::json($active)]);
        try {
            $checkout->cancel('cancel');
            self::fail('Expected still unconfirmed');
        } catch (AmbiguousOperationException) {
        }
        self::assertSame('ambiguous', $operation->fresh()->status);
        $cancelled = [...$active, 'status' => 'CANCELLED', 'updated_at' => '2026-11-10T12:01:00Z'];
        $http = $this->fake([FakeHttpClient::json($cancelled)]);
        self::assertSame('CANCELLED', $checkout->cancel('cancel')->status);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame('GET', $http->requests[1]->getMethod());
    }

    public function test_metadata_ack_without_requested_values_never_falsely_confirms(): void
    {
        $checkout = $this->create();
        $active = Billing::checkout();
        $this->fake([FakeHttpClient::json($active), FakeHttpClient::json($active), FakeHttpClient::json($active)]);
        try {
            $checkout->updateMetadata('meta', ['reference' => 'desired']);
            self::fail('Expected unconfirmed metadata');
        } catch (AmbiguousOperationException) {
        }
        self::assertSame('ambiguous', Operation::where('action_key', 'meta')->first()->status);
    }

    public function test_hosted_auto_charge_subscription_requires_owned_canonical_source(): void
    {
        $checkout = $this->create();
        $complete = Billing::checkout(['status' => 'COMPLETED', 'subscription_id' => 'sub-test', 'updated_at' => '2026-11-10T12:01:00Z']);
        $subscription = Billing::subscription(['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test']);
        $this->fake([FakeHttpClient::json($complete), FakeHttpClient::json($subscription), Billing::page([]), FakeHttpClient::json($subscription), FakeHttpClient::json(Billing::source(['billing_customer_id' => 'foreign']))]);
        try {
            $checkout->reconcile();
            self::fail('Expected canonical source owner rejection');
        } catch (OwnershipException) {
        }
        self::assertSame(0, Subscription::count());
        self::assertFalse($this->team->subscribed('default'));
        $this->fake([FakeHttpClient::json($complete), FakeHttpClient::json($subscription), Billing::page([]), FakeHttpClient::json($subscription), FakeHttpClient::json(Billing::source())]);
        self::assertNotNull($checkout->reconcile()->subscription_id);
        self::assertTrue($this->team->subscribed('default'));
    }

    public function test_a_stale_source_verification_cannot_overwrite_a_newer_verified_source(): void
    {
        $active = Billing::checkout(['mode' => 'SETUP']);
        $this->fake([FakeHttpClient::json($active), FakeHttpClient::json($active)]);
        $checkout = $this->team->setupPaymentMethod('setup', $this->checkoutOptions());
        app('cache')->flush();
        $first = [...$active, 'status' => 'COMPLETED', 'payment_source_id' => 'source-a', 'updated_at' => '2026-11-10T12:01:00Z'];
        $second = [...$first, 'payment_source_id' => 'source-b', 'updated_at' => '2026-11-10T12:02:00Z'];
        $http = new InterleavingHttpClient([
            FakeHttpClient::login(), FakeHttpClient::json($first),
            function () use ($checkout, $second) {
                $this->fake([FakeHttpClient::json($second), FakeHttpClient::json(Billing::source(['id' => 'source-b']))]);
                $checkout->reconcile();

                return FakeHttpClient::json(Billing::source(['id' => 'source-a']));
            },
        ]);
        $this->app->instance(ClientInterface::class, $http);
        $this->app->forgetInstance(Client::class);
        try {
            $checkout->reconcile();
            self::fail('Expected stale verification conflict');
        } catch (EvidenceException) {
        }
        self::assertSame('source-b', $checkout->fresh()->remote_payment_source_id);
        self::assertSame('source-b', $checkout->fresh()->payment_source_id);
    }

    public function test_parent_identity_survives_unknown_setup_recovery_child_failure(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'setup', 'checkout.setup', [], ['payload' => [], 'name' => null]);
        $operation->update(['status' => 'ambiguous']);
        $parent = Billing::checkout(['mode' => 'SETUP', 'status' => 'COMPLETED', 'payment_source_id' => 'source-test', 'metadata' => ['_laravel_operation_id' => $operation->id]]);
        $this->fake([FakeHttpClient::json($parent), FakeHttpClient::json(['code' => 'unavailable'], 503)]);
        try {
            app(CheckoutManager::class)->recover($operation, 'checkout-test');
            self::fail('Expected retryable source failure');
        } catch (AmbiguousOperationException) {
        }
        self::assertSame('checkout-test', $operation->fresh()->remote_id);
        self::assertSame('ambiguous', $operation->fresh()->status);
        self::assertNull(Checkout::first()->payment_source_id);
        $http = $this->fake([FakeHttpClient::json($parent), FakeHttpClient::json(Billing::source())]);
        self::assertSame('source-test', app(CheckoutManager::class)->recover($operation->fresh())->payment_source_id);
        self::assertSame('succeeded', $operation->fresh()->status);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function test_equal_versions_cannot_change_positive_candidate_ids_even_via_omission(): void
    {
        $active = Billing::checkout(['mode' => 'SETUP']);
        $this->fake([FakeHttpClient::json($active), FakeHttpClient::json($active)]);
        $checkout = $this->team->setupPaymentMethod('setup', $this->checkoutOptions());
        $complete = [...$active, 'status' => 'COMPLETED', 'payment_source_id' => 'source-a', 'updated_at' => '2026-11-10T12:10:00Z'];
        $foreign = Billing::source(['id' => 'source-a', 'billing_customer_id' => 'foreign']);
        $this->fake([FakeHttpClient::json($complete), FakeHttpClient::json($foreign)]);
        try {
            $checkout->reconcile();
            self::fail('Expected owner rejection');
        } catch (OwnershipException) {
        }
        unset($complete['payment_source_id']);
        $this->fake([FakeHttpClient::json($complete), FakeHttpClient::json($foreign)]);
        try {
            $checkout->reconcile();
            self::fail('Expected retained candidate owner rejection');
        } catch (OwnershipException) {
        }
        $this->fake([FakeHttpClient::json([...$complete, 'payment_source_id' => 'source-b'])]);
        try {
            $checkout->reconcile();
            self::fail('Expected equal-version result conflict');
        } catch (EvidenceException) {
        }
        self::assertSame('source-a', $checkout->fresh()->remote_payment_source_id);
        self::assertNull($checkout->fresh()->payment_source_id);
    }

    public function test_expired_or_untrusted_url_cannot_redirect(): void
    {
        $checkout = $this->create();
        Carbon::setTestNow('2026-11-10T13:00:00Z');
        $this->expectException(EvidenceException::class);
        $checkout->redirect();
    }
}

class FailingCheckout extends Checkout
{
    public static bool $fail = false;

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (): void {
            if (self::$fail) {
                throw new \RuntimeException('SYNTHETIC-STORAGE-SECRET');
            }
        });
    }
}
