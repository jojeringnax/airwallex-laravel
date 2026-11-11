<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\AirwallexBillable;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\RecoveryManager;
use Jojeringnax\AirwallexLaravel\Tests\Consumers\Coexistence\CashierTeam;
use Jojeringnax\AirwallexLaravel\Tests\Consumers\Coexistence\StripeSubscription;
use Jojeringnax\AirwallexLaravel\Tests\Consumers\Coexistence\StripeTransport;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionBuilder;
use Psr\Http\Client\ClientInterface;
use Stripe\ApiRequestor;

class CashierCoexistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! trait_exists(Billable::class)) {
            self::markTestSkipped('Run the explicit isolated Cashier fixture described in docs/usage.md.');
        }
        require_once __DIR__.'/Consumers/coexistence/Models.php';
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('coexistence_teams', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('stripe_id')->nullable();
        });
        Schema::create('coexistence_stripe_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('cashier_team_id');
            $table->string('type');
            $table->string('stripe_id');
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stripe_subscription_id');
        });
        Cashier::useCustomerModel(CashierTeam::class);
        Cashier::useSubscriptionModel(StripeSubscription::class);
        config(['cashier.secret' => 'sk_test_synthetic_fixture']);
        Relation::enforceMorphMap(['coexistence_team' => CashierTeam::class]);
    }

    protected function tearDown(): void
    {
        if (class_exists(Cashier::class)) {
            Cashier::useCustomerModel('App\\Models\\User');
            Cashier::useSubscriptionModel(Subscription::class);
        }
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_model_keeps_cashier_methods_and_airwallex_history_and_current_name_separate(): void
    {
        $team = CashierTeam::create(['id' => 'team', 'stripe_id' => 'cus_legacy']);
        $legacy = $team->subscriptions()->create(['type' => 'membership', 'stripe_id' => 'sub_legacy', 'stripe_status' => 'active']);
        $stripe = new StripeTransport;
        ApiRequestor::setHttpClient($stripe);
        $billing = new AirwallexBillable($team);
        self::assertStringNotContainsString('cus_legacy', print_r($billing, true));
        try {
            serialize($billing);
            self::fail('Authorized model wrappers must not be serialized.');
        } catch (\LogicException) {
            self::assertSame([], $stripe->requests);
        }
        self::assertNull($billing->subscription('membership'));
        self::assertFalse($billing->subscribed('membership'));
        self::assertTrue($team->subscribed('membership'));
        self::assertInstanceOf(SubscriptionBuilder::class, $team->newSubscription('membership', 'price_legacy'));
        self::assertInstanceOf(\Jojeringnax\AirwallexLaravel\SubscriptionBuilder::class, $billing->newSubscription('membership', 'price-test'));
        self::assertSame($team, $billing->newSubscription('membership', 'price-test')->billable());
        self::assertSame([], $stripe->requests);

        config(['airwallex.models.subscription' => CoexistenceSubscription::class]);
        $old = Billing::subscription(['id' => 'sub-old', 'status' => 'CANCELLED']);
        $active = Billing::subscription(['id' => 'sub-new', 'collection_method' => 'OUT_OF_BAND']);
        $http = new FakeHttpClient([
            FakeHttpClient::login(), FakeHttpClient::json(['id' => 'customer-test']),
            ...$this->observation($old),
            FakeHttpClient::json($active), ...$this->observation($active),
        ]);
        $this->app->bind(ClientInterface::class, fn () => $http);
        $customer = $billing->createOrGetAirwallexCustomer('customer');
        self::assertSame('coexistence_team', $customer->billable_type);
        self::assertSame('team', $customer->billable_id);
        $historical = $billing->importSubscription('membership', 'sub-old');
        self::assertSame($historical->id, $billing->subscription('membership')->id);
        self::assertFalse($billing->subscribed('membership'));
        $current = $billing->newSubscription('membership', 'price-test')->create('new', ['collection_method' => 'OUT_OF_BAND']);
        self::assertInstanceOf(CoexistenceSubscription::class, $current);
        self::assertTrue($billing->subscribed('membership'));
        self::assertSame($current->id, $billing->subscription('membership')->id);
        self::assertCount(2, $billing->subscriptions()->get());
        self::assertSame($legacy->id, $team->fresh()->subscription('membership')->id);
        self::assertSame('cus_legacy', $team->fresh()->stripe_id);
        self::assertSame([], $stripe->requests);
    }

    public function test_invoices_setup_cancellation_and_recovery_do_not_dispatch_into_cashier(): void
    {
        $team = CashierTeam::create(['id' => 'team', 'stripe_id' => 'cus_legacy']);
        $legacy = $team->subscriptions()->create(['type' => 'membership', 'stripe_id' => 'sub_legacy', 'stripe_status' => 'active']);
        $stripe = new StripeTransport;
        ApiRequestor::setHttpClient($stripe);
        $billing = new AirwallexBillable($team);
        $remote = Billing::subscription(['collection_method' => 'OUT_OF_BAND']);
        $cancelled = Billing::subscription(['status' => 'CANCELLED', 'collection_method' => 'OUT_OF_BAND', 'updated_at' => '2026-11-10T12:01:00Z']);
        $setup = Billing::checkout(['id' => 'setup-test', 'mode' => 'SETUP']);
        $http = new FakeHttpClient([
            FakeHttpClient::login(), FakeHttpClient::json(['id' => 'customer-test']),
            ...$this->observation($remote),
            FakeHttpClient::json($setup), FakeHttpClient::json($setup),
            FakeHttpClient::json($this->invoice()), FakeHttpClient::json(['items' => [$this->invoice()]]),
            ...$this->observation($remote), FakeHttpClient::json($cancelled), ...$this->observation($cancelled),
        ]);
        $this->app->bind(ClientInterface::class, fn () => $http);
        $billing->createOrGetAirwallexCustomer('customer');
        $subscription = $billing->importSubscription('membership', 'sub-test');
        self::assertSame('SETUP', $billing->setupPaymentMethod('setup', ['success_url' => 'https://example.test/success', 'back_url' => 'https://example.test/back'])->mode);
        self::assertSame('invoice-test', $billing->findInvoice('invoice-test')->id);
        self::assertCount(1, $billing->invoices());
        $subscription->cancelNow('cancel', 'NONE');
        $operation = Operation::where('action_key', 'cancel')->firstOrFail();
        self::assertSame($subscription->id, app(RecoveryManager::class)->operation($operation)->id);
        self::assertSame([], $stripe->requests);
        self::assertTrue($team->fresh()->subscribed('membership'));
        self::assertCount(0, $team->invoices());
        $legacy->cancelNow();
        self::assertSame('re_legacy', $team->refund('pi_legacy', ['amount' => 100])->id);
        self::assertCount(3, $stripe->requests);
        self::assertSame('canceled', $legacy->fresh()->stripe_status);
        self::assertSame('CANCELLED', $billing->subscription('membership')->status);
    }

    public function test_unsaved_models_are_rejected_without_provider_access(): void
    {
        $this->expectException(ConflictException::class);
        new AirwallexBillable(new CashierTeam);
    }

    private function invoice(): array
    {
        return ['id' => 'invoice-test', 'billing_customer_id' => 'customer-test', 'subscription_id' => 'sub-test', 'status' => 'FINALIZED', 'payment_status' => 'UNPAID', 'currency' => 'USD', 'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z'];
    }

    private function observation(array $remote): array
    {
        return [FakeHttpClient::json($remote), FakeHttpClient::json(['items' => [Billing::item(['subscription_id' => $remote['id']])]]), FakeHttpClient::json($remote)];
    }
}

class CoexistenceSubscription extends \Jojeringnax\AirwallexLaravel\Models\Subscription {}
