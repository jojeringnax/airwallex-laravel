<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\RecoveryManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;

class RecoveryCommandTest extends TestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        $team = Team::create(['id' => 'recovery-team']);
        $this->customer = app(OperationManager::class)->reserveCustomer($team);
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

    private function observation(array $remote): array
    {
        return [FakeHttpClient::json($remote), Billing::page([Billing::item(['subscription_id' => $remote['id']])]), FakeHttpClient::json($remote)];
    }

    private function creation(): Operation
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'create', 'subscription.create', [], ['name' => 'default', 'payload' => ['collection_method' => 'OUT_OF_BAND', 'legal_entity_id' => 'entity-test', 'linked_payment_account_id' => 'collector-test']], name: 'default');
        $operation->update(['status' => 'ambiguous']);

        return $operation;
    }

    public function test_inspection_is_bounded_context_scoped_safe_and_requires_no_credentials(): void
    {
        $operation = $this->creation();
        config(['airwallex.api_key' => null, 'airwallex.client_id' => null]);
        self::assertSame(0, Artisan::call('airwallex:operations', ['--limit' => '1']));
        $output = Artisan::output();
        self::assertStringContainsString($operation->id, $output);
        self::assertStringContainsString('ambiguous', $output);
        self::assertStringNotContainsString('OUT_OF_BAND', $output);
        self::assertStringNotContainsString($operation->request_id, $output);
        self::assertSame(1, Artisan::call('airwallex:operations', ['--limit' => '0']));
        self::assertSame(1, Artisan::call('airwallex:operations', ['--remote-id' => 'sub-test']));
        self::assertSame(1, Artisan::call('airwallex:operations', ['--id' => '', '--remote-id' => 'sub-test', '--reconcile' => true]));
        self::assertNull($operation->fresh()->remote_id);
        self::assertSame(1, Artisan::call('airwallex:operations', ['--id' => '']));
        self::assertSame(1, Artisan::call('airwallex:operations', ['--after' => '']));
        config(['airwallex.organization_id' => 'different-org']);
        $this->app->forgetScopedInstances();
        self::assertSame(1, Artisan::call('airwallex:operations', ['--id' => $operation->id]));
        self::assertStringNotContainsString($operation->id, Artisan::output());
    }

    public function test_explicit_retry_release_audits_crashed_inflight_creation_without_claiming_noncreation(): void
    {
        $operation = $this->creation();
        $operation->update(['status' => 'in_flight']);
        $http = $this->fake([]);
        self::assertSame(1, Artisan::call('airwallex:allow-creation-retry', ['id' => $operation->id]));
        self::assertSame(0, Artisan::call('airwallex:allow-creation-retry', ['id' => $operation->id, '--actor' => 'operator-1', '--reason' => 'ticket-108']));
        self::assertSame('superseded', $operation->fresh()->status);
        self::assertNull($operation->fresh()->lock_key);
        self::assertSame('operator-1', $operation->fresh()->getAttribute('retry_release')['actor']);
        self::assertStringNotContainsString('ticket-108', Artisan::output());
        self::assertSame(0, SubscriptionSlot::count());
        self::assertCount(0, $http->requests);
        self::assertSame(0, Artisan::call('airwallex:allow-creation-retry', ['id' => $operation->id, '--actor' => 'operator-2', '--reason' => 'repeat']));
        self::assertSame('operator-1', $operation->fresh()->getAttribute('retry_release')['actor']);
    }

    public function test_prepared_intent_is_never_submitted_by_read_recovery(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'prepared', 'subscription.create', [], [], name: 'default');
        $http = $this->fake([]);
        self::assertSame(1, Artisan::call('airwallex:operations', ['--id' => $operation->id, '--reconcile' => true]));
        self::assertSame('prepared', $operation->fresh()->status);
        self::assertCount(0, $http->requests);
        self::assertSame(1, SubscriptionSlot::count());
    }

    public function test_unknown_creation_candidate_requires_canonical_marker_and_persists_identity_before_child_failure(): void
    {
        $operation = $this->creation();
        $remote = Billing::subscription(['metadata' => ['_laravel_operation_id' => $operation->id]]);
        $http = $this->fake([FakeHttpClient::json($remote), FakeHttpClient::json($remote), FakeHttpClient::json(['code' => 'unavailable'], 503), ...$this->observation($remote)]);
        self::assertSame(1, Artisan::call('airwallex:operations', ['--id' => $operation->id, '--remote-id' => 'sub-test', '--reconcile' => true]));
        self::assertSame('sub-test', $operation->fresh()->remote_id);
        self::assertSame('ambiguous', $operation->fresh()->status);
        self::assertSame(0, Subscription::count());
        self::assertSame(0, Artisan::call('airwallex:operations', ['--id' => $operation->id, '--reconcile' => true]));
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame(1, Subscription::count());
        self::assertSame(0, Artisan::call('airwallex:operations', ['--id' => $operation->id, '--reconcile' => true]));
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
        self::assertSame($operation->request_id, $operation->fresh()->request_id);
    }

    public function test_foreign_or_uncorrelated_candidates_cannot_bind_unknown_creation(): void
    {
        $operation = $this->creation();
        foreach ([Billing::subscription(), Billing::subscription(['billing_customer_id' => 'foreign', 'metadata' => ['_laravel_operation_id' => $operation->id]])] as $remote) {
            $this->fake([FakeHttpClient::json($remote)]);
            try {
                app(SubscriptionManager::class)->recoverCreation($operation, 'sub-test');
                self::fail('Unproved candidate was adopted.');
            } catch (OwnershipException) {
                self::assertNull($operation->fresh()->remote_id);
                self::assertSame(0, Subscription::count());
                self::assertSame(1, SubscriptionSlot::count());
            }
        }
    }

    public function test_missed_unpaid_recovery_and_cancellation_converge_without_financial_writes(): void
    {
        $subscription = Subscription::create(['customer_id' => $this->customer->id, 'environment' => 'sandbox', 'organization_id' => 'org-test', 'remote_id' => 'sub-test', 'name' => 'default', 'status' => 'ACTIVE', 'starts_at' => '2026-11-01T00:00:00Z', 'snapshot' => Billing::subscription()]);
        SubscriptionSlot::create(['customer_id' => $this->customer->id, 'name' => 'default', 'subscription_id' => $subscription->id]);
        $states = ['UNPAID', 'ACTIVE', 'CANCELLED'];
        $responses = [];
        foreach ($states as $index => $status) {
            $responses = [...$responses, ...$this->observation(Billing::subscription(['status' => $status, 'updated_at' => '2026-11-10T12:0'.($index + 1).':00Z']))];
        }
        $http = $this->fake($responses);
        foreach ($states as $status) {
            self::assertSame(0, Artisan::call('airwallex:reconcile', ['--type' => 'subscription', '--limit' => '1']));
            self::assertSame($status, $subscription->fresh()->status);
            self::assertSame($status === 'ACTIVE', $subscription->fresh()->valid());
        }
        self::assertSame(0, SubscriptionSlot::count());
        self::assertSame(0, Operation::count());
        self::assertCount(10, $http->requests);
        self::assertSame(0, Artisan::call('airwallex:reconcile', ['--type' => 'subscription', '--after' => $subscription->id]));
        self::assertCount(10, $http->requests);
    }

    public function test_customer_update_recovery_reads_exact_profile_without_resending_update(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'profile', 'customer.update', [], ['remote_id' => 'customer-test', 'profile' => ['name' => 'Confirmed']]);
        $operation->update(['status' => 'ambiguous']);
        $http = $this->fake([FakeHttpClient::json(['id' => 'customer-test', 'name' => 'Confirmed'])]);
        $recipe = require __DIR__.'/../examples/recover-operation.php';
        $recipe($operation->id);
        app(RecoveryManager::class)->operation($operation->fresh());
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame('customer-test', $operation->fresh()->remote_id);
        self::assertCount(2, $http->requests);
        self::assertSame('GET', $http->requests[1]->getMethod());
    }

    public function test_a_late_read_cannot_recreate_an_offboarded_known_subscription(): void
    {
        $remote = Billing::subscription(['status' => 'CANCELLED']);
        $subscription = Subscription::create(['customer_id' => $this->customer->id, 'environment' => 'sandbox', 'organization_id' => 'org-test', 'remote_id' => 'sub-test', 'name' => 'default', 'status' => 'CANCELLED', 'snapshot' => $remote]);
        $http = new InterleavingHttpClient([
            FakeHttpClient::login(),
            function () use ($subscription, $remote) {
                $subscription->delete();

                return FakeHttpClient::json($remote);
            },
            Billing::page([]), FakeHttpClient::json($remote),
        ]);
        $this->app->instance(ClientInterface::class, $http);
        $this->app->forgetInstance(Client::class);
        try {
            app(SubscriptionSynchronizer::class)->reconcile($subscription);
            self::fail('Known deleted history was recreated as an import.');
        } catch (ConflictException) {
            self::assertSame(0, Subscription::count());
            self::assertSame(0, SubscriptionSlot::count());
            self::assertSame(0, DomainEvent::count());
        }
    }
}
