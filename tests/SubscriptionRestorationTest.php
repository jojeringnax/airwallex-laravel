<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Events\SubscriptionReconciled;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

class SubscriptionRestorationTest extends TestCase
{
    private Team $team;

    private Customer $customer;

    private const ORIGINAL_END = '2027-01-01T00:00:00.123456789+01:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $this->team = Team::create(['id' => 'team']);
        $this->customer = app(OperationManager::class)->reserveCustomer($this->team);
        $this->customer->update(['remote_id' => 'customer-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('endingModes')]
    public function test_original_finite_bound_is_saved_before_schedule_and_restored_with_distinct_identity(bool $dated): void
    {
        $original = $this->original();
        $subscription = $this->seedSubscription($original);
        $end = $dated ? '2026-11-20T12:34:56.123456789+0230' : '2026-12-01T00:00:00Z';
        $scheduled = $this->scheduled($dated ? ['cancel_at_period_end' => false, 'ends_at' => $end] : []);
        $http = $this->http([...$this->observation($original), FakeHttpClient::json($scheduled), ...$this->observation($scheduled)]);
        $subscription = $dated ? $subscription->cancelAt('off', $end, $this->endingOptions()) : $subscription->cancelAtPeriodEnd('off');
        $off = Operation::where('action_key', 'off')->firstOrFail();
        self::assertSame(self::ORIGINAL_END, $off->intent['original_bounds']['ends_at']);
        self::assertFalse($off->intent['original_bounds']['cancel_at_period_end']);
        self::assertSame($end, $subscription->scheduled_ends_at);
        self::assertTrue($subscription->onGracePeriod());
        if ($dated) {
            $body = json_decode((string) $http->requests[4]->getBody(), true);
            self::assertSame($end, $body['ends_at']);
            self::assertNull($body['duration']);
            self::assertNull($body['remaining_duration']);
            self::assertArrayNotHasKey('cancel_at_period_end', $body);
            self::assertFalse($subscription->cancel_at_period_end);
        }
        $restored = $this->original(['updated_at' => '2026-11-10T12:02:00Z']);
        $http = $this->http([...$this->observation($scheduled), FakeHttpClient::json($restored), ...$this->observation($restored)]);
        $result = $subscription->restoreRenewal('on', 'off');
        $on = Operation::where('action_key', 'on')->firstOrFail();
        self::assertSame(['cancel_at_period_end' => false, 'ends_at' => self::ORIGINAL_END, 'request_id' => $on->request_id],
            json_decode((string) $http->requests[4]->getBody(), true));
        self::assertNotSame($off->request_id, $on->request_id);
        self::assertSame('succeeded', $on->status);
        self::assertNull($result->scheduled_ends_at);
        self::assertFalse($result->cancel_at_period_end);
        self::assertSame(self::ORIGINAL_END, $result->ends_at);
        $result->restoreRenewal('on', 'off');
        self::assertCount(8, $http->requests);
    }

    public static function endingModes(): array
    {
        return [[false], [true]];
    }

    private function endingOptions(): array
    {
        return ['default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'];
    }

    #[DataProvider('invalidDatedEnds')]
    public function test_invalid_dated_ending_refuses_before_http(string $end, array $changes): void
    {
        $subscription = $this->seedSubscription($this->original($changes));
        $http = $this->http([]);
        try {
            $subscription->cancelAt('invalid-date', $end, $this->endingOptions());
            self::fail('Unsupported dated ending reached HTTP.');
        } catch (EvidenceException|ConflictException) {
            self::assertCount(0, $http->requests);
            self::assertSame(0, Operation::count());
        }
    }

    public static function invalidDatedEnds(): array
    {
        return [
            ['2026-11-10T12:00:00Z', []],
            ['2026-11-10T11:00:00Z', []],
            ['2028-01-01T00:00:00Z', []],
            ['2026-11-20T00:00:00Z', ['status' => 'UNPAID']],
            ['2026-11-20T00:00:00Z', ['collection_method' => 'CHARGE_ON_CHECKOUT']],
            ['2026-11-20T00:00:00Z', ['status' => 'IN_TRIAL']],
            ['2026-11-20T00:00:00Z', ['ends_at' => null, 'duration' => null, 'remaining_duration' => null]],
            ['2026-11-20T00:00:00Z', ['status' => 'NEW_STATE']],
            ['2026-11-20T00:00:00Z', ['cancel_at_period_end' => true]],
            ['2026-11-20T00:00:00Z', ['starts_at' => '2026-12-01T00:00:00Z', 'status' => 'PENDING']],
        ];
    }

    public function test_dated_end_recovers_original_response_and_retains_cap_across_missing_bounds_and_old_observations(): void
    {
        $original = $this->original();
        $subscription = $this->seedSubscription($original);
        $end = '2026-11-20T12:00:00.123456+02:00';
        $scheduled = $this->scheduled(['cancel_at_period_end' => false, 'ends_at' => $end]);
        $this->http([...$this->observation($original), FakeHttpClient::json($scheduled), new RuntimeException('synthetic canonical read interruption')]);
        try {
            $subscription->cancelAt('dated', new \DateTimeImmutable($end), $this->endingOptions());
            self::fail('Expected ambiguous canonical read.');
        } catch (AmbiguousOperationException) {
            self::assertSame('sub-test', Operation::first()->remote_id);
        }
        $competing = $this->http($this->observation($original));
        try {
            $subscription->cancelNow('competing-date', 'NONE');
            self::fail('A competing cancellation bypassed the dated action.');
        } catch (ConflictException) {
            self::assertCount(4, $competing->requests);
            self::assertSame(1, Operation::count());
        }
        $http = $this->http($this->observation($scheduled));
        $result = $subscription->cancelAt('dated', $end, $this->endingOptions());
        self::assertSame($end, $result->scheduled_ends_at);
        self::assertTrue($result->onGracePeriod());
        self::assertCount(4, $http->requests);
        self::assertSame('succeeded', Operation::first()->status);
        $missing = [...$scheduled, 'ends_at' => null, 'updated_at' => '2026-11-10T12:02:00Z'];
        $this->http($this->observation($missing));
        $result = $result->reconcile();
        self::assertSame($end, $result->scheduled_ends_at);
        $this->http($this->observation($original));
        $result = $result->reconcile();
        self::assertSame($end, $result->scheduled_ends_at);
        Carbon::setTestNow($end);
        self::assertFalse($result->valid());
        self::assertFalse($result->onGracePeriod());
    }

    public function test_false_flag_without_exact_original_end_retains_access_cap_until_read_recovery_proves_restoration(): void
    {
        $subscription = $this->scheduledSubscription();
        $wrong = $this->scheduled(['cancel_at_period_end' => false, 'ends_at' => '2027-02-01T00:00:00Z', 'updated_at' => '2026-11-10T12:02:00Z']);
        $http = $this->http([...$this->observation($this->scheduled()), FakeHttpClient::json($wrong), ...$this->observation($wrong)]);
        try {
            $subscription->restoreRenewal('on', 'off');
            self::fail('False alone proved restoration.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::where('action_key', 'on')->first()->status);
            self::assertSame('2026-12-01T00:00:00Z', $subscription->fresh()->scheduled_ends_at);
        }
        Carbon::setTestNow('2026-12-01T00:00:00Z');
        self::assertFalse($subscription->fresh()->valid());
        $restored = $this->original(['updated_at' => '2026-11-10T12:03:00Z']);
        $http = $this->http($this->observation($restored));
        $result = $subscription->restoreRenewal('on', 'off');
        self::assertNull($result->scheduled_ends_at);
        self::assertTrue($result->valid());
        self::assertSame(0, count(array_filter($http->requests, fn ($request) => $request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/update'))));
    }

    public function test_timeout_holds_same_command_and_competing_cancel_cannot_write(): void
    {
        $subscription = $this->scheduledSubscription();
        $http = $this->http([...$this->observation($this->scheduled()), new RuntimeException('synthetic timeout'), ...$this->observation($this->scheduled()), ...$this->observation($this->scheduled())]);
        foreach (range(1, 2) as $attempt) {
            try {
                $subscription->restoreRenewal('on', 'off');
                self::fail('Expected ambiguous restoration.');
            } catch (AmbiguousOperationException) {
                self::assertNotNull(Operation::where('action_key', 'on')->first()->lock_key);
            }
        }
        try {
            $subscription->cancelNow('competing', 'NONE');
            self::fail('A competing action bypassed the hold.');
        } catch (ConflictException) {
            self::assertSame('2026-12-01T00:00:00Z', $subscription->fresh()->scheduled_ends_at);
        }
        self::assertSame(1, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/update'))));
    }

    #[DataProvider('unsupportedCombinations')]
    public function test_unsupported_original_combinations_are_not_promoted_to_restoration(array $originalChanges): void
    {
        $original = $this->original($originalChanges);
        $subscription = $this->seedSubscription($original);
        $scheduled = $this->scheduled($originalChanges);
        $this->http([...$this->observation($original), FakeHttpClient::json($scheduled), ...$this->observation($scheduled)]);
        $subscription = $subscription->cancelAtPeriodEnd('off');
        $http = $this->http($this->observation($scheduled));
        try {
            $subscription->restoreRenewal('on', 'off');
            self::fail('Unsupported restoration reached the provider.');
        } catch (EvidenceException|ConflictException) {
            self::assertSame(4, count($http->requests));
            self::assertSame(0, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/update'))));
        }
    }

    public static function unsupportedCombinations(): array
    {
        return [
            [['status' => 'IN_TRIAL']], [['status' => 'UNPAID']],
            [['collection_method' => 'CHARGE_ON_CHECKOUT', 'payment_source_id' => 'source-test']],
            [['ends_at' => null, 'duration' => null, 'remaining_duration' => null]],
        ];
    }

    public function test_historical_schedule_without_original_intent_cannot_reconstruct_a_finite_end(): void
    {
        $subscription = $this->seedSubscription($this->scheduled());
        $http = $this->http($this->observation($this->scheduled()));
        $this->expectException(EvidenceException::class);
        $subscription->restoreRenewal('on', 'missing-historical-off');
    }

    #[DataProvider('automaticCombinations')]
    public function test_verified_automatic_restoration_preserves_state_and_source_and_clears_only_proven_bounds(string $status, bool $indefinite, bool $dated): void
    {
        $original = $this->automatic($status, $indefinite);
        $subscription = $this->seedSubscription($original);
        $scheduled = [...$original, 'cancel_at_period_end' => ! $dated, 'ends_at' => '2026-12-01T00:00:00Z', 'duration' => ['period' => 1, 'period_unit' => 'MONTH'], 'updated_at' => '2026-11-10T12:01:00Z'];
        $bounds = $indefinite ? ['ends_at' => null, 'duration' => null, 'remaining_duration' => null] : null;
        $this->http([...$this->observation($original), FakeHttpClient::json($scheduled), ...$this->observation($scheduled)]);
        $subscription = $dated ? $subscription->cancelAt('off', '2026-12-01T00:00:00Z', $this->endingOptions(), $bounds) : $subscription->cancelAtPeriodEnd('off', $bounds);
        $off = Operation::where('action_key', 'off')->firstOrFail();
        self::assertSame($bounds ?? ['ends_at' => self::ORIGINAL_END], $off->intent['renewal_bounds']);
        $restored = [...$original, 'updated_at' => '2026-11-10T12:02:00Z'];
        $http = $this->http([...$this->observation($scheduled), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($restored), ...$this->observation($restored)]);
        $result = $subscription->restoreRenewal('on', 'off');
        $on = Operation::where('action_key', 'on')->firstOrFail();
        self::assertSame(['cancel_at_period_end' => false, ...($bounds ?? ['ends_at' => self::ORIGINAL_END]), 'request_id' => $on->request_id], json_decode((string) $http->requests[5]->getBody(), true));
        self::assertSame('succeeded', $on->status);
        self::assertSame($status, $result->status);
        self::assertSame('source-test', $result->snapshot['payment_source_id']);
        self::assertNull($result->scheduled_ends_at);
        self::assertSame($indefinite ? null : self::ORIGINAL_END, $result->ends_at);
        if ($indefinite) {
            self::assertArrayNotHasKey('ends_at', $result->snapshot);
        }
        if (in_array($status, ['PENDING', 'UNPAID'], true)) {
            self::assertFalse($result->valid());
        }
        if ($status === 'ACTIVE' && $indefinite) {
            Carbon::setTestNow('2026-12-02T00:00:00Z');
            self::assertTrue($result->fresh()->valid());
        }
        $this->http([]);
        $result->restoreRenewal('on', 'off');
    }

    public static function automaticCombinations(): array
    {
        return [['ACTIVE', false, false], ['ACTIVE', true, false], ['PENDING', true, false], ['IN_TRIAL', true, false], ['UNPAID', true, false], ['ACTIVE', false, true], ['ACTIVE', true, true], ['PENDING', true, true], ['IN_TRIAL', true, true]];
    }

    public function test_optional_omission_before_scheduling_does_not_invent_indefinite_intent(): void
    {
        $original = $this->automatic('ACTIVE', true);
        $subscription = $this->seedSubscription($original);
        $scheduled = [...$original, 'cancel_at_period_end' => true, 'ends_at' => '2026-12-01T00:00:00Z', 'updated_at' => '2026-11-10T12:01:00Z'];
        $this->http([...$this->observation($original), FakeHttpClient::json($scheduled), ...$this->observation($scheduled)]);
        $subscription = $subscription->cancelAtPeriodEnd('off');
        $http = $this->http($this->observation($scheduled));
        try {
            $subscription->restoreRenewal('on', 'off');
            self::fail('Omitted bounds became indefinite intent.');
        } catch (EvidenceException) {
            self::assertCount(4, $http->requests);
            self::assertNull(Operation::where('action_key', 'on')->first());
        }
    }

    public function test_indefinite_intent_cannot_erase_an_observed_finite_lifetime(): void
    {
        $original = $this->automatic('ACTIVE', false);
        $subscription = $this->seedSubscription($original);
        $http = $this->http($this->observation($original));
        try {
            $subscription->cancelAtPeriodEnd('off', ['ends_at' => null, 'duration' => null, 'remaining_duration' => null]);
            self::fail('Finite lifetime was discarded.');
        } catch (EvidenceException) {
            self::assertCount(4, $http->requests);
            self::assertSame(0, Operation::count());
        }
    }

    public function test_automatic_source_ownership_is_verified_before_restoration_write(): void
    {
        [$subscription, $scheduled, $original] = $this->indefiniteSchedule();
        $http = $this->http([...$this->observation($scheduled), FakeHttpClient::json(Billing::source(['billing_customer_id' => 'foreign']))]);
        try {
            $subscription->restoreRenewal('on', 'off');
            self::fail('Foreign source reached restoration.');
        } catch (OwnershipException) {
            self::assertSame('failed', Operation::where('action_key', 'on')->first()->status);
            self::assertSame(0, count(array_filter($http->requests, fn ($request) => $request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/update'))));
        }
    }

    public function test_contradictory_indefinite_result_keeps_cap_and_recovers_original_command_without_write(): void
    {
        [$subscription, $scheduled, $original] = $this->indefiniteSchedule();
        $wrong = [...$original, 'duration' => ['period' => 1, 'period_unit' => 'MONTH'], 'updated_at' => '2026-11-10T12:02:00Z'];
        $this->http([...$this->observation($scheduled), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($wrong), ...$this->observation($wrong)]);
        try {
            $subscription->restoreRenewal('on', 'off');
            self::fail('Contradictory bounds proved restoration.');
        } catch (AmbiguousOperationException) {
            self::assertSame('2026-12-01T00:00:00Z', $subscription->fresh()->scheduled_ends_at);
            self::assertSame('2026-12-01T00:00:00Z', $subscription->fresh()->ends_at);
        }
        Carbon::setTestNow('2026-12-02T00:00:00Z');
        $restored = [...$original, 'duration' => null, 'remaining_duration' => null, 'ends_at' => null, 'updated_at' => '2026-12-02T00:00:00Z'];
        $http = $this->http($this->observation($restored));
        $observations = [];
        Event::listen(SubscriptionReconciled::class, function () use (&$observations, $subscription): void {
            $fresh = $subscription->fresh();
            $observations[] = [$fresh->ends_at, $fresh->scheduled_ends_at, $fresh->valid()];
        });
        $result = $subscription->restoreRenewal('on', 'off');
        self::assertNull($result->scheduled_ends_at);
        self::assertNull($result->ends_at);
        self::assertTrue($result->valid());
        self::assertSame('succeeded', Operation::where('action_key', 'on')->first()->status);
        self::assertSame([[null, null, true]], $observations);
        self::assertCount(4, $http->requests);
    }

    public function test_conflicting_limit_between_canonical_reads_cannot_prove_indefinite_restoration(): void
    {
        [$subscription, $scheduled, $original] = $this->indefiniteSchedule();
        $restored = [...$original, 'updated_at' => '2026-11-10T12:02:00Z'];
        $contradiction = [...$restored, 'duration' => ['period' => 1, 'period_unit' => 'MONTH']];
        $this->http([...$this->observation($scheduled), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($restored), FakeHttpClient::json($contradiction), Billing::page([]), FakeHttpClient::json($restored)]);
        try {
            $subscription->restoreRenewal('on', 'off');
            self::fail('Conflicting reads erased a positive limit.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::where('action_key', 'on')->first()->status);
            self::assertSame('2026-12-01T00:00:00Z', $subscription->fresh()->scheduled_ends_at);
        }
    }

    #[DataProvider('restorationCompletionModes')]
    public function test_synchronous_listener_reconciliation_does_not_repeat_restoration_confirmation(bool $interruptCompletion): void
    {
        [$subscription, $scheduled, $original] = $this->indefiniteSchedule();
        if ($interruptCompletion) {
            $this->app->instance(OperationManager::class, new class(app(BillingContext::class)) extends OperationManager
            {
                private bool $interrupted = false;

                public function succeed(Operation $operation): void
                {
                    if ($operation->action_key === 'on' && ! $this->interrupted) {
                        $this->interrupted = true;
                        throw new RuntimeException('Synthetic interruption after the confirmed cap clear.');
                    }
                    parent::succeed($operation);
                }
            });
        }
        $restored = [...$original, 'updated_at' => '2026-11-10T12:02:00Z'];
        $http = $this->http([...$this->observation($scheduled), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($restored), ...$this->observation($restored), ...$this->observation($restored)]);
        $observed = false;
        Event::listen(SubscriptionReconciled::class, function () use ($subscription, &$observed): void {
            if (! $observed) {
                $observed = true;
                $subscription->reconcile();
            }
        });
        try {
            $subscription->restoreRenewal('on', 'off');
            self::assertFalse($interruptCompletion);
        } catch (AmbiguousOperationException $error) {
            if (! $interruptCompletion) {
                throw $error;
            }
            $operation = Operation::where('action_key', 'on')->sole();
            $identity = [$operation->request_id, $operation->intent_hash, $operation->intent];
            self::assertSame('ambiguous', $operation->status);
            self::assertNull($subscription->fresh()->ends_at);
            self::assertNull($subscription->fresh()->scheduled_ends_at);
            $retry = $this->http($this->observation($restored));
            $subscription->restoreRenewal('on', 'off');
            self::assertCount(4, $retry->requests);
            self::assertSame($identity, [$operation->fresh()->request_id, $operation->fresh()->intent_hash, $operation->fresh()->intent]);
        }
        self::assertTrue($observed);
        self::assertSame('succeeded', Operation::where('action_key', 'on')->first()->status);
        self::assertNull($subscription->fresh()->ends_at);
        self::assertNull($subscription->fresh()->scheduled_ends_at);
        self::assertCount(12, $http->requests);
    }

    public static function restorationCompletionModes(): array
    {
        return [[false], [true]];
    }

    private function automatic(string $status, bool $indefinite): array
    {
        $original = $this->original(['status' => $status, 'collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test', 'current_period_ends_at' => '2026-12-01T00:00:00Z']);
        if ($indefinite) {
            unset($original['ends_at'], $original['duration'], $original['remaining_duration']);
        }
        if ($status === 'IN_TRIAL') {
            $original['trial_starts_at'] = '2026-11-01T00:00:00Z';
            $original['trial_ends_at'] = '2026-11-11T00:00:00Z';
        }

        return $original;
    }

    private function indefiniteSchedule(): array
    {
        $original = $this->automatic('ACTIVE', true);
        $subscription = $this->seedSubscription($original);
        $scheduled = [...$original, 'cancel_at_period_end' => true, 'ends_at' => '2026-12-01T00:00:00Z', 'updated_at' => '2026-11-10T12:01:00Z'];
        $this->http([...$this->observation($original), FakeHttpClient::json($scheduled), ...$this->observation($scheduled)]);

        return [$subscription->cancelAtPeriodEnd('off', ['ends_at' => null, 'duration' => null, 'remaining_duration' => null]), $scheduled, $original];
    }

    public function test_new_restoration_cannot_be_submitted_at_an_already_effective_scheduled_end(): void
    {
        $subscription = $this->scheduledSubscription();
        Carbon::setTestNow('2026-12-01T00:00:00Z');
        $http = $this->http($this->observation($this->scheduled()));
        try {
            $subscription->restoreRenewal('too-late', 'off');
            self::fail('An elapsed cancellation boundary was reversed.');
        } catch (EvidenceException) {
            self::assertCount(4, $http->requests);
            self::assertNull(Operation::where('action_key', 'too-late')->first());
            self::assertFalse($subscription->fresh()->valid());
        }
    }

    public function test_prepared_restoration_cannot_make_its_first_write_after_the_saved_schedule_boundary(): void
    {
        $subscription = $this->scheduledSubscription();
        $this->app->instance(OperationManager::class, new class(app(BillingContext::class)) extends OperationManager
        {
            public function execute(Operation $operation, \Closure $write, \Closure $confirm): mixed
            {
                throw new RuntimeException('Synthetic interruption before first dispatch.');
            }
        });
        $this->http($this->observation($this->scheduled()));
        try {
            $subscription->restoreRenewal('prepared-on', 'off');
            self::fail('Expected synthetic interruption.');
        } catch (RuntimeException) {
            self::assertSame('prepared', Operation::where('action_key', 'prepared-on')->first()->status);
        }
        $this->app->instance(OperationManager::class, new OperationManager(app(BillingContext::class)));
        Carbon::setTestNow('2026-12-01T00:00:00Z');
        $http = $this->http([]);
        try {
            $subscription->restoreRenewal('prepared-on', 'off');
            self::fail('Prepared restoration crossed its cancellation boundary.');
        } catch (EvidenceException) {
            self::assertCount(0, $http->requests);
            self::assertSame('failed', Operation::where('action_key', 'prepared-on')->first()->status);
        }
    }

    public function test_prepared_restart_rechecks_canonical_collection_before_restoration_dispatch(): void
    {
        $subscription = $this->scheduledSubscription();
        $this->app->instance(OperationManager::class, new class(app(BillingContext::class)) extends OperationManager
        {
            public function execute(Operation $operation, \Closure $write, \Closure $confirm): mixed
            {
                throw new RuntimeException('Synthetic interruption before first dispatch.');
            }
        });
        $this->http($this->observation($this->scheduled()));
        try {
            $subscription->restoreRenewal('prepared-on', 'off');
        } catch (RuntimeException) {
            self::assertSame('prepared', Operation::where('action_key', 'prepared-on')->first()->status);
        }
        $this->app->instance(OperationManager::class, new OperationManager(app(BillingContext::class)));
        $changed = $this->scheduled(['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test', 'updated_at' => '2026-11-10T12:03:00Z']);
        $http = $this->http($this->observation($changed));
        try {
            $subscription->restoreRenewal('prepared-on', 'off');
            self::fail('Prepared restoration trusted obsolete local collection proof.');
        } catch (EvidenceException) {
            self::assertCount(4, $http->requests);
            self::assertSame('failed', Operation::where('action_key', 'prepared-on')->first()->status);
        }
    }

    private function original(array $overrides = []): array
    {
        return Billing::subscription(['currency' => 'EUR', 'ends_at' => self::ORIGINAL_END,
            'duration' => ['period' => 2, 'period_unit' => 'MONTH'], ...$overrides]);
    }

    private function scheduled(array $overrides = []): array
    {
        return $this->original(['cancel_at_period_end' => true, 'ends_at' => '2026-12-01T00:00:00Z',
            'current_period_ends_at' => '2026-12-01T00:00:00Z', 'updated_at' => '2026-11-10T12:01:00Z', ...$overrides]);
    }

    private function scheduledSubscription(): Subscription
    {
        $subscription = $this->seedSubscription($this->original());
        $scheduled = $this->scheduled();
        $this->http([...$this->observation($this->original()), FakeHttpClient::json($scheduled), ...$this->observation($scheduled)]);

        return $subscription->cancelAtPeriodEnd('off');
    }

    private function seedSubscription(array $remote): Subscription
    {
        $this->http($this->observation($remote));

        return $this->team->importSubscription('membership', $remote['id']);
    }

    private function observation(array $remote): array
    {
        return [FakeHttpClient::json($remote), Billing::page([]), FakeHttpClient::json($remote)];
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
