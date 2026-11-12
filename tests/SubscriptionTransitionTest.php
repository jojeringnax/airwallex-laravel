<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionTransition;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Timestamp;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

class SubscriptionTransitionTest extends TestCase
{
    private Subscription $subscription;

    private const OPTIONS = ['default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $team = Team::create(['id' => 'team']);
        $customer = app(OperationManager::class)->reserveCustomer($team);
        $customer->update(['remote_id' => 'customer-test']);
        $this->http($this->observation());
        $this->subscription = $team->importSubscription('membership', 'sub-test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exact_future_plan_is_durable_and_first_execution_uses_one_stable_financial_command(): void
    {
        $this->http($this->observation());
        $plan = $this->subscription->scheduleTransition('downgrade', '2026-12-01T01:00:00.123456789+01:00', [['id' => 'item-test', 'quantity' => 2]], self::OPTIONS, '2026-12-01T00:10:00Z');
        self::assertSame('2026-12-01T01:00:00.123456789+01:00', $plan->effective_at);
        self::assertSame(0, Operation::count());
        $http = $this->http([]);
        Carbon::setTestNow('2026-12-01T00:00:00.123456Z');
        self::assertSame('scheduled', $plan->run()->status);
        self::assertCount(0, $http->requests);
        Carbon::setTestNow('2026-12-01T00:00:01Z');
        $remote = Billing::subscription(['updated_at' => '2026-12-01T00:00:01Z']);
        $item = Billing::item(['quantity' => '2', 'updated_at' => '2026-12-01T00:00:01Z']);
        $http = $this->http([...$this->observation(), FakeHttpClient::json($remote), ...$this->observation($remote, $item)]);
        self::assertSame('completed', $plan->run()->status);
        $operation = Operation::firstOrFail();
        self::assertSame('transition/'.$plan->id, $operation->action_key);
        self::assertSame('succeeded', $operation->status);
        self::assertNull($plan->fresh()->getAttribute('active_key'));
        self::assertArrayNotHasKey('cancel_at_period_end', json_decode((string) $http->requests[4]->getBody(), true));
        $plan->run();
        self::assertCount(8, $http->requests);
    }

    public function test_replace_is_atomic_before_execution_and_same_key_cannot_change_its_plan(): void
    {
        $this->http($this->observation());
        $first = $this->plan('first');
        $this->http($this->observation());
        $second = $first->replace('replacement', '2026-12-02T00:00:00Z', [['id' => 'item-test', 'quantity' => 3]], self::OPTIONS, '2026-12-02T00:10:00Z');
        self::assertSame('cancelled', $first->fresh()->status);
        self::assertSame('scheduled', $second->status);
        self::assertSame(1, SubscriptionTransition::whereNotNull('active_key')->count());
        $this->expectException(ConflictException::class);
        $this->subscription->scheduleTransition('replacement', '2026-12-03T00:00:00Z', [['id' => 'item-test', 'quantity' => 3]], self::OPTIONS, '2026-12-03T00:10:00Z');
    }

    public function test_changed_source_or_missed_window_blocks_before_any_financial_operation(): void
    {
        $this->http($this->observation());
        $plan = $this->plan();
        Carbon::setTestNow('2026-12-01T00:00:01Z');
        $changed = Billing::item(['quantity' => '3', 'updated_at' => '2026-11-10T12:01:00Z']);
        $remote = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->http($this->observation($remote, $changed));
        try {
            $plan->run();
            self::fail('Changed source was applied.');
        } catch (EvidenceException) {
            self::assertSame('blocked', $plan->fresh()->status);
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
        self::assertSame('cancelled', $plan->cancel()->status);
        $this->http($this->observation($remote, $changed));
        Carbon::setTestNow('2026-11-10T12:02:00Z');
        $late = $this->plan('late');
        Carbon::setTestNow('2026-12-01T00:11:00Z');
        $this->http($this->observation($remote, $changed));
        try {
            $late->run();
            self::fail('A missed execution window silently caught up.');
        } catch (EvidenceException) {
            self::assertSame('blocked', $late->fresh()->status);
            self::assertSame(0, Operation::count());
        }
    }

    public function test_ambiguous_attempt_is_never_replayed_replaced_or_released_by_expiry(): void
    {
        $this->http($this->observation());
        $plan = $this->plan();
        Carbon::setTestNow('2026-12-01T00:00:01Z');
        $http = $this->http([...$this->observation(), new RuntimeException('synthetic timeout'), ...$this->observation()]);
        foreach (range(1, 2) as $attempt) {
            try {
                $plan->run();
                self::fail('Expected ambiguity.');
            } catch (AmbiguousOperationException) {
                self::assertSame('ambiguous', $plan->fresh()->status);
                self::assertNotNull($plan->fresh()->getAttribute('active_key'));
            }
            Carbon::setTestNow('2026-12-02T00:00:00Z');
        }
        self::assertSame(1, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/update'))));
        $this->expectException(ConflictException::class);
        $plan->cancel();
    }

    public function test_concurrent_generation_plan_is_reserved_and_financial_controls_cannot_reset_auto_renew(): void
    {
        $this->http($this->observation());
        $plan = $this->plan();
        $this->http($this->observation());
        try {
            $this->plan('competing');
            self::fail('Two future plans reserved one generation.');
        } catch (ConflictException) {
            self::assertSame(1, SubscriptionTransition::count());
            self::assertSame(0, Operation::count());
        }
        $plan->cancel();
        $this->expectException(ConflictException::class);
        $this->subscription->scheduleTransition('controls', '2026-12-01T00:00:00Z', [['id' => 'item-test', 'quantity' => 2]], [...self::OPTIONS, 'cancel_at_period_end' => false], '2026-12-01T00:10:00Z');
    }

    public function test_recorded_response_recovers_after_window_without_repeating_the_item_write(): void
    {
        $this->http($this->observation());
        $plan = $this->plan();
        Carbon::setTestNow('2026-12-01T00:00:01Z');
        $changed = Billing::subscription(['updated_at' => '2026-12-01T00:00:01Z']);
        $http = $this->http([...$this->observation(), FakeHttpClient::json($changed), FakeHttpClient::json(['code' => 'unavailable'], 503)]);
        try {
            $plan->run();
            self::fail('Failed readback proved completion.');
        } catch (AmbiguousOperationException) {
            self::assertSame('sub-test', Operation::first()->remote_id);
            self::assertSame('ambiguous', $plan->fresh()->status);
        }
        Carbon::setTestNow('2026-12-02T00:00:00Z');
        $http = $this->http($this->observation($changed, Billing::item(['quantity' => '2', 'updated_at' => '2026-12-01T00:00:01Z'])));
        self::assertSame('completed', $plan->run()->status);
        self::assertCount(4, $http->requests);
        self::assertSame('succeeded', Operation::first()->status);
    }

    public function test_prepared_transition_restarted_after_expiry_never_sends_its_first_write(): void
    {
        $this->http($this->observation());
        $plan = $this->plan();
        Carbon::setTestNow('2026-12-01T00:00:01Z');
        $this->app->instance(OperationManager::class, new class(app(BillingContext::class)) extends OperationManager
        {
            public function execute(Operation $operation, \Closure $write, \Closure $confirm): mixed
            {
                throw new RuntimeException('Synthetic process interruption before first dispatch.');
            }
        });
        $this->http($this->observation());
        try {
            $plan->run();
            self::fail('Expected synthetic interruption.');
        } catch (RuntimeException) {
            self::assertSame('prepared', Operation::first()->status);
        }
        // A real process interruption leaves the pre-dispatch executing row; reconstruct exactly that durable state.
        SubscriptionTransition::whereKey($plan->id)->update(['status' => 'executing']);
        $this->app->instance(OperationManager::class, new OperationManager(app(BillingContext::class)));
        Carbon::setTestNow('2026-12-02T00:00:00Z');
        $http = $this->http([]);
        try {
            $plan->run();
            self::fail('Prepared operation bypassed its expired window.');
        } catch (EvidenceException) {
            self::assertCount(0, $http->requests);
            self::assertSame('failed', Operation::first()->status);
            self::assertSame('blocked', $plan->fresh()->status);
        }
    }

    public function test_source_preflight_crossing_expiry_stops_before_the_first_write(): void
    {
        $remote = Billing::subscription(['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test', 'updated_at' => '2026-11-10T12:01:00Z']);
        $this->http($this->observation($remote));
        $plan = $this->plan();
        Carbon::setTestNow('2026-12-01T00:09:59Z');
        $http = new InterleavingHttpClient([FakeHttpClient::login(), ...$this->observation($remote), function () {
            Carbon::setTestNow('2026-12-01T00:10:01Z');

            return FakeHttpClient::json(Billing::source());
        }]);
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);
        try {
            $plan->run();
            self::fail('Source preflight crossing expiry reached the mutation.');
        } catch (EvidenceException) {
            self::assertSame('failed', Operation::first()->status);
            self::assertSame('blocked', $plan->fresh()->status);
            self::assertCount(5, $http->requests);
        }
    }

    public function test_skipping_a_whole_billing_cycle_blocks_even_inside_a_generous_execution_window(): void
    {
        $this->http($this->observation());
        $plan = $this->subscription->scheduleTransition('skipped-cycle', '2026-12-01T00:00:00Z', [['id' => 'item-test', 'quantity' => 2]], self::OPTIONS, '2027-01-05T00:00:00Z');
        Carbon::setTestNow('2027-01-02T00:00:00Z');
        $http = $this->http($this->observation());
        try {
            $plan->run();
            self::fail('A later cycle received a missed financial action.');
        } catch (EvidenceException) {
            self::assertSame('blocked', $plan->fresh()->status);
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
    }

    private function plan(string $action = 'plan'): SubscriptionTransition
    {
        return $this->subscription->scheduleTransition($action, '2026-12-01T00:00:00Z', [['id' => 'item-test', 'quantity' => 2]], self::OPTIONS, '2026-12-01T00:10:00Z');
    }

    private function observation(?array $remote = null, ?array $item = null): array
    {
        $remote = [...Billing::subscription(), 'current_period_starts_at' => now()->startOfMonth()->format('Y-m-d\\TH:i:sP'), 'current_period_ends_at' => now()->startOfMonth()->addMonth()->format('Y-m-d\\TH:i:sP'), ...($remote ?? [])];
        if (Timestamp::compare($remote['updated_at'], $remote['current_period_starts_at']) < 0) {
            $remote['updated_at'] = $remote['current_period_starts_at'];
        }

        return [FakeHttpClient::json($remote), Billing::page([$item ?? Billing::item(['quantity' => '1'])]), FakeHttpClient::json($remote)];
    }

    private function http(array $responses): FakeHttpClient
    {
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }
}
