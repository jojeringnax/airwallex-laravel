<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionTransition;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
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
        $recipe = require __DIR__.'/../examples/schedule-transition.php';
        $plan = $recipe($this->subscription, 'downgrade', '2026-12-01T01:00:00.123456789+01:00', '2026-12-01T00:10:00Z', [['id' => 'item-test', 'quantity' => 2]]);
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

    private function plan(string $action = 'plan'): SubscriptionTransition
    {
        return $this->subscription->scheduleTransition($action, '2026-12-01T00:00:00Z', [['id' => 'item-test', 'quantity' => 2]], self::OPTIONS, '2026-12-01T00:10:00Z');
    }

    private function observation(?array $remote = null, ?array $item = null): array
    {
        return [FakeHttpClient::json($remote ?? Billing::subscription()), Billing::page([$item ?? Billing::item(['quantity' => '1'])]), FakeHttpClient::json($remote ?? Billing::subscription())];
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
