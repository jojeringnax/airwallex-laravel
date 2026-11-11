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

    public function test_original_finite_bound_is_saved_before_schedule_and_restored_with_distinct_identity(): void
    {
        $original = $this->original();
        $subscription = $this->seedSubscription($original);
        $scheduled = $this->scheduled();
        $this->http([...$this->observation($original), FakeHttpClient::json($scheduled), ...$this->observation($scheduled)]);
        $subscription = $subscription->cancelAtPeriodEnd('off');
        $off = Operation::where('action_key', 'off')->firstOrFail();
        self::assertSame(self::ORIGINAL_END, $off->intent['original_bounds']['ends_at']);
        self::assertFalse($off->intent['original_bounds']['cancel_at_period_end']);
        self::assertSame('2026-12-01T00:00:00Z', $subscription->scheduled_ends_at);
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
            [['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test']],
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
