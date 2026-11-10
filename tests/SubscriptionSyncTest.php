<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionItem;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\Outbox;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;

class SubscriptionSyncTest extends TestCase
{
    private Customer $customer;

    private Team $team;

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

    private function observation(array $remote, array $items = []): array
    {
        return [FakeHttpClient::json($remote), Billing::page($items), FakeHttpClient::json($remote)];
    }

    #[DataProvider('states')]
    public function test_named_import_observes_states_without_remote_mutations(string $state, bool $valid): void
    {
        $remote = Billing::subscription(['status' => $state, 'trial_starts_at' => '2026-11-01T00:00:00Z', 'trial_ends_at' => '2026-12-01T00:00:00Z']);
        $http = $this->fake($this->observation($remote, [Billing::item()]));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        self::assertSame($state, $subscription->status);
        self::assertSame($valid, $this->team->subscribed('default'));
        self::assertSame('1.000000000000000000001', $subscription->items()->first()->quantity);
        self::assertSame(1, DomainEvent::count());
        self::assertNotNull($subscription->synced_at);
        self::assertNotNull($subscription->items_synced_at);
        self::assertSame($state !== 'CANCELLED' ? 1 : 0, SubscriptionSlot::count());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public static function states(): array
    {
        return [['PENDING', false], ['IN_TRIAL', true], ['ACTIVE', true], ['UNPAID', false], ['CANCELLED', false], ['FUTURE_STATE', false]];
    }

    public function test_all_pages_are_atomic_and_keep_exact_quantity_and_nullable_shapes(): void
    {
        $remote = Billing::subscription(['extra' => ['empty' => new \stdClass, 'client_secret' => 'SYNTHETIC-SECRET']]);
        $http = $this->fake([FakeHttpClient::json($remote), Billing::page([Billing::item()], 'bookmark-two'), Billing::page([Billing::item(['id' => 'other', 'quantity' => null])]), FakeHttpClient::json($remote)]);
        $subscription = $this->team->importSubscription('default', 'sub-test');
        self::assertSame(2, $subscription->items()->count());
        self::assertStringContainsString('page=bookmark-two', (string) $http->requests[3]->getUri());
        self::assertNull($subscription->items()->where('remote_id', 'other')->first()->quantity);
        self::assertInstanceOf(\stdClass::class, $subscription->snapshot['extra']['empty']);
        self::assertArrayNotHasKey('client_secret', $subscription->snapshot['extra']);
        $before = $subscription->fresh()->getAttributes();
        $this->fake([FakeHttpClient::json($remote), Billing::page([Billing::item(['id' => 'partial'])], 'page-two'), FakeHttpClient::json(['code' => 'unavailable'], 503)]);
        try {
            $subscription->reconcile();
            self::fail('Expected failed final page');
        } catch (ApiException) {
        }
        self::assertSame($before, $subscription->fresh()->getAttributes());
        self::assertSame(2, $subscription->items()->count());
    }

    public function test_foreign_parent_or_item_and_malformed_pages_never_associate(): void
    {
        $this->fake([FakeHttpClient::json(Billing::subscription(['billing_customer_id' => 'foreign']))]);
        try {
            $this->team->importSubscription('default', 'sub-test');
            self::fail('Expected owner rejection');
        } catch (OwnershipException) {
        }
        $this->fake([FakeHttpClient::json(Billing::subscription()), Billing::page([Billing::item(['subscription_id' => 'foreign'])])]);
        try {
            $this->team->importSubscription('default', 'sub-test');
            self::fail('Expected item owner rejection');
        } catch (OwnershipException) {
        }
        $this->fake([FakeHttpClient::json(Billing::subscription()), Billing::page([Billing::item()], 'repeat'), Billing::page([Billing::item(['id' => 'other'])], 'repeat')]);
        try {
            $this->team->importSubscription('default', 'sub-test');
            self::fail('Expected repeated cursor rejection');
        } catch (EvidenceException) {
        }
        self::assertSame(0, Subscription::count());
        self::assertSame(0, SubscriptionSlot::count());
        self::assertSame(0, DomainEvent::count());
    }

    public function test_import_conflicts_with_current_name_and_inflight_creation(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'create', 'subscription.create', [], [], name: 'default');
        $this->fake($this->observation(Billing::subscription()));
        try {
            $this->team->importSubscription('default', 'sub-test');
            self::fail('Expected pending name conflict');
        } catch (ConflictException) {
        }
        self::assertSame($operation->id, SubscriptionSlot::first()->operation_id);
        self::assertSame(0, Subscription::count());
    }

    public function test_repeat_import_is_idempotent_but_cannot_rename_or_reparent(): void
    {
        $remote = Billing::subscription();
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $this->fake($this->observation($remote));
        self::assertSame($subscription->id, $this->team->importSubscription('default', 'sub-test')->id);
        self::assertSame(1, DomainEvent::count());
        $this->expectException(ConflictException::class);
        $this->team->importSubscription('support', 'sub-test');
    }

    public function test_late_history_updates_never_displace_or_release_current_generation(): void
    {
        $old = Billing::subscription(['status' => 'CANCELLED']);
        $this->fake($this->observation($old));
        $historical = $this->team->importSubscription('default', 'sub-test');
        $new = Billing::subscription(['id' => 'sub-new', 'status' => 'ACTIVE']);
        $this->fake($this->observation($new));
        $current = $this->team->importSubscription('default', 'sub-new');
        $this->fake($this->observation([...$old, 'updated_at' => '2026-11-11T00:00:00Z']));
        $historical->reconcile();
        self::assertSame($current->id, $this->team->subscription('default')->id);
        self::assertSame($current->id, SubscriptionSlot::first()->subscription_id);
        self::assertTrue($this->team->subscribed('default'));
        SubscriptionSlot::query()->delete();
        self::assertSame($current->id, $this->team->subscription('default')->id);
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_older_snapshots_and_cancellation_resurrection_are_rejected(): void
    {
        $remote = Billing::subscription(['status' => 'CANCELLED']);
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $this->fake($this->observation([...$remote, 'updated_at' => '2026-11-09T00:00:00Z', 'status' => 'ACTIVE']));
        self::assertSame('CANCELLED', $subscription->reconcile()->status);
        $this->fake($this->observation([...$remote, 'updated_at' => '2026-11-11T00:00:00Z', 'status' => 'ACTIVE']));
        $this->expectException(EvidenceException::class);
        $subscription->reconcile();
    }

    public function test_unknown_item_shapes_and_extra_fields_preserve_lifecycle_access(): void
    {
        $remote = Billing::subscription(['future_extension' => ['data' => 'retained']]);
        $this->fake($this->observation($remote, [Billing::item(['price' => null, 'quantity' => null, 'rate_card_version' => ['id' => 'rate-card']])]));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        self::assertTrue($subscription->active());
        self::assertFalse($subscription->items()->first()->canMutate());
        self::assertSame(['data' => 'retained'], $subscription->snapshot['future_extension']);
        self::assertNull($subscription->items()->first()->price_id);
    }

    public function test_a_concurrent_equal_version_refresh_cannot_restore_an_older_item_set(): void
    {
        $remote = Billing::subscription();
        $this->fake($this->observation($remote, [Billing::item()]));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $older = $subscription->fresh();
        $initialRevision = $older->revision;
        $http = new InterleavingHttpClient([
            FakeHttpClient::login(), FakeHttpClient::json($remote), Billing::page([Billing::item()]),
            function () use ($remote, $subscription) {
                $this->fake($this->observation($remote, [Billing::item(['id' => 'new-item', 'updated_at' => '2026-11-10T13:00:00Z'])]));
                $subscription->reconcile();

                return FakeHttpClient::json($remote);
            },
        ]);
        $factory = new HttpFactory;
        $client = new Client(Environment::Sandbox, new Credentials('synthetic-client', 'synthetic-key'), $http, $factory, $factory);
        $synchronizer = new SubscriptionSynchronizer($client, app(BillingContext::class), app(OperationManager::class), app(Outbox::class));
        $result = $synchronizer->reconcile($older);
        self::assertSame('new-item', $result->items()->first()->remote_id);
        self::assertSame($initialRevision + 1, $result->revision);
    }

    public function test_late_history_does_not_release_a_new_inflight_name_reservation(): void
    {
        $remote = Billing::subscription(['status' => 'CANCELLED']);
        $this->fake($this->observation($remote));
        $history = $this->team->importSubscription('default', 'sub-test');
        $operation = app(OperationManager::class)->prepare($this->customer, 'new-checkout', 'checkout.subscription', [], [], name: 'default');
        $this->fake($this->observation([...$remote, 'updated_at' => '2026-11-11T00:00:00Z']));
        $history->reconcile();
        self::assertSame($operation->id, SubscriptionSlot::first()->operation_id);
        self::assertNull(SubscriptionSlot::first()->subscription_id);
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_confirmation_uses_only_its_own_durable_reservation(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'create', 'subscription.create', [], [], name: 'default');
        $operation->update(['status' => 'in_flight', 'remote_id' => 'sub-test']);
        $this->fake($this->observation(Billing::subscription(), [Billing::item()]));
        $subscription = app(SubscriptionSynchronizer::class)->confirm($this->customer, 'default', 'sub-test', $operation);
        self::assertSame($subscription->id, SubscriptionSlot::first()->subscription_id);
        self::assertNull(SubscriptionSlot::first()->operation_id);
    }

    public function test_unsupported_item_time_remains_inspectable_without_poisoning_later_repair(): void
    {
        $remote = Billing::subscription();
        $this->fake($this->observation($remote, [Billing::item(['updated_at' => 'unfamiliar-time-shape'])]));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        self::assertTrue($subscription->active());
        self::assertNull($subscription->items()->first()->provider_updated_at);
        self::assertFalse($subscription->items()->first()->canMutate());
        self::assertSame('unfamiliar-time-shape', $subscription->items()->first()->snapshot['updated_at']);
        $this->fake($this->observation($remote, [Billing::item()]));
        self::assertTrue($subscription->reconcile()->items()->first()->canMutate());
    }

    public function test_absent_or_null_optional_dates_never_clear_a_confirmed_access_cap(): void
    {
        $remote = Billing::subscription(['ends_at' => '2026-11-09T00:00:00Z']);
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        self::assertFalse($subscription->active());
        unset($remote['ends_at']);
        $remote['updated_at'] = '2026-11-10T13:00:00Z';
        $this->fake($this->observation($remote));
        $subscription = $subscription->reconcile();
        self::assertFalse($subscription->active());
        self::assertArrayNotHasKey('ends_at', $subscription->snapshot);
        self::assertSame('2026-11-09T00:00:00Z', $subscription->ends_at);
        $remote['ends_at'] = null;
        $remote['updated_at'] = '2026-11-10T14:00:00Z';
        $this->fake($this->observation($remote));
        $subscription = $subscription->reconcile();
        self::assertFalse($subscription->active());
        self::assertNull($subscription->snapshot['ends_at']);
        $remote['ends_at'] = '2026-11-11T00:00:00Z';
        $remote['updated_at'] = '2026-11-10T15:00:00Z';
        $this->fake($this->observation($remote));
        self::assertTrue($subscription->reconcile()->active());
    }

    public function test_conflicting_context_and_creation_identity_are_rejected(): void
    {
        $this->fake([FakeHttpClient::json(Billing::subscription(['org_id' => 'foreign']))]);
        try {
            $this->team->importSubscription('default', 'sub-test');
            self::fail('Expected context rejection');
        } catch (OwnershipException) {
        }
        $operation = app(OperationManager::class)->prepare($this->customer, 'create', 'subscription.create', [], [], name: 'default');
        $operation->update(['status' => 'in_flight', 'remote_id' => 'known-subscription']);
        try {
            app(SubscriptionSynchronizer::class)->confirm($this->customer, 'default', 'different-subscription', $operation);
            self::fail('Expected identity rejection');
        } catch (OwnershipException) {
        }
        self::assertSame(0, Subscription::count());
    }

    public function test_unsupported_price_flags_never_authorize_item_mutation(): void
    {
        foreach ([['id' => '', 'pricing_model' => 'PER_UNIT', 'metered' => false], ['id' => 'price', 'pricing_model' => 'PER_UNIT'], ['id' => 'price', 'pricing_model' => 'PER_UNIT', 'metered' => null]] as $price) {
            $item = new SubscriptionItem(['snapshot' => Billing::item(['price' => $price])]);
            self::assertFalse($item->canMutate());
        }
    }

    public function test_checked_in_named_import_recipe_executes_against_the_real_sdk(): void
    {
        $this->fake($this->observation(Billing::subscription()));
        $recipe = require dirname(__DIR__).'/examples/import-subscription.php';
        $subscription = $recipe($this->team, 'support', 'sub-test');
        self::assertSame('support', $subscription->name);
        self::assertTrue($this->team->subscribed('support'));
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_equal_item_versions_cannot_regress_quantity_but_tolerate_unknown_extras(): void
    {
        $remote = Billing::subscription();
        $this->fake($this->observation($remote, [Billing::item(['quantity' => '2'])]));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $this->fake($this->observation($remote, [Billing::item(['quantity' => '1'])]));
        try {
            $subscription->reconcile();
            self::fail('Expected equal-version conflict');
        } catch (EvidenceException) {
        }
        self::assertSame('2', $subscription->items()->first()->quantity);
        $this->fake($this->observation($remote, [Billing::item(['quantity' => '2', 'future_extra' => ['keep' => true]])]));
        $subscription = $subscription->reconcile();
        self::assertSame(['keep' => true], $subscription->items()->first()->snapshot['future_extra']);
    }

    public function test_unsaved_customer_identity_cannot_import_a_foreign_subscription(): void
    {
        $this->customer->remote_id = 'foreign-customer';
        $this->fake($this->observation(Billing::subscription(['billing_customer_id' => 'foreign-customer'])));
        $this->expectException(OwnershipException::class);
        app(SubscriptionSynchronizer::class)->import($this->customer, 'default', 'sub-test');
    }

    public function test_new_scheduled_period_end_caps_a_retained_later_finite_end(): void
    {
        $remote = Billing::subscription(['ends_at' => '2027-01-01T00:00:00Z']);
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        unset($remote['ends_at']);
        $remote = [...$remote, 'cancel_at_period_end' => true, 'current_period_ends_at' => '2026-12-01T00:00:00Z', 'updated_at' => '2026-11-10T12:01:00Z'];
        $this->fake($this->observation($remote));
        $subscription = $subscription->reconcile();
        self::assertTrue($subscription->active());
        self::assertSame('2027-01-01T00:00:00Z', $subscription->ends_at);
        Carbon::setTestNow('2026-12-01T00:00:00Z');
        self::assertFalse($subscription->active());
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_confirmed_schedule_cap_survives_partial_reads_and_period_rollover(): void
    {
        $remote = Billing::subscription(['cancel_at_period_end' => true, 'current_period_ends_at' => '2026-12-01T00:00:00Z']);
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        self::assertSame('2026-12-01T00:00:00Z', $subscription->scheduled_ends_at);
        unset($remote['current_period_ends_at']);
        $remote['updated_at'] = '2026-11-10T12:01:00Z';
        $this->fake($this->observation($remote));
        $subscription = $subscription->reconcile();
        self::assertTrue($subscription->active());
        $remote['current_period_ends_at'] = '2027-01-01T00:00:00Z';
        $remote['updated_at'] = '2026-12-02T12:00:00Z';
        $this->fake($this->observation($remote));
        $subscription = $subscription->reconcile();
        Carbon::setTestNow('2026-12-02T12:00:00Z');
        self::assertSame('2026-12-01T00:00:00Z', $subscription->scheduled_ends_at);
        self::assertFalse($subscription->active());
    }

    public function test_new_schedule_without_fresh_bound_does_not_reuse_an_unrelated_finite_end(): void
    {
        $remote = Billing::subscription(['ends_at' => '2027-01-01T00:00:00Z']);
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        unset($remote['ends_at']);
        $remote = [...$remote, 'cancel_at_period_end' => true, 'updated_at' => '2026-11-10T12:01:00Z'];
        $this->fake($this->observation($remote));
        $subscription = $subscription->reconcile();
        self::assertSame('2027-01-01T00:00:00Z', $subscription->ends_at);
        self::assertNull($subscription->scheduled_ends_at);
        self::assertFalse($subscription->active());
    }

    public function test_parent_changes_during_paging_retain_previous_projection(): void
    {
        $remote = Billing::subscription();
        $this->fake([FakeHttpClient::json($remote), Billing::page([]), FakeHttpClient::json([...$remote, 'status' => 'UNPAID', 'updated_at' => '2026-11-10T13:00:00Z'])]);
        try {
            $this->team->importSubscription('default', 'sub-test');
            self::fail('Expected unstable snapshot');
        } catch (EvidenceException) {
        }
        self::assertSame(0, Subscription::count());
    }
}
