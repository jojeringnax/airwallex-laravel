<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Events\SubscriptionReconciled;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Jobs\DispatchDomainEvent;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\Outbox;
use Jojeringnax\AirwallexLaravel\Services\OutboxDispatcher;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Mockery;
use RuntimeException;

class OutboxTest extends TestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $this->customer = app(OperationManager::class)->reserveCustomer(Team::create(['id' => 'team']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(array $overrides = []): DomainEvent
    {
        return DomainEvent::create(array_replace([...app(BillingContext::class)->attributes(), 'resource_type' => 'airwallex_subscriptions', 'resource_id' => 'local-subscription', 'event_name' => 'SubscriptionReconciled', 'payload' => ['remote_id' => 'remote-subscription', 'previous_status' => 'UNPAID', 'status' => 'ACTIVE']], $overrides));
    }

    public function test_delivery_starts_after_commit_with_stable_safe_correlation(): void
    {
        $delivered = [];
        Event::listen(SubscriptionReconciled::class, function (SubscriptionReconciled $event) use (&$delivered): void {
            $delivered[] = $event;
            self::assertSame(0, DB::transactionLevel());
            self::assertSame(1, DomainEvent::count());
        });
        DB::beginTransaction();
        $row = app(Outbox::class)->record('SubscriptionReconciled', $this->customer, ['remote_id' => 'sub-test', 'previous_status' => 'UNPAID', 'status' => 'ACTIVE']);
        self::assertSame([], $delivered);
        self::assertNull($row->fresh()->dispatched_at);
        DB::commit();
        self::assertCount(1, $delivered);
        self::assertSame($row->id, $delivered[0]->id);
        self::assertSame($this->customer->id, $delivered[0]->resourceId);
        self::assertSame('org-test', $delivered[0]->organizationId);
        self::assertSame('sandbox', $delivered[0]->environment);
        self::assertSame('ACTIVE', $delivered[0]->payload['status']);
        self::assertNotNull($row->fresh()->dispatched_at);
        self::assertFalse(app(OutboxDispatcher::class)->dispatch($row->id));
        self::assertCount(1, $delivered);
    }

    public function test_projection_rollback_discards_outbox_and_delivery(): void
    {
        $count = 0;
        Event::listen(SubscriptionReconciled::class, function () use (&$count): void {
            $count++;
        });
        try {
            DB::transaction(function (): void {
                app(Outbox::class)->record('SubscriptionReconciled', $this->customer, ['status' => 'ACTIVE']);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(0, DomainEvent::count());
        self::assertSame(0, $count);
    }

    public function test_queue_dispatch_failure_leaves_a_recoverable_committed_row(): void
    {
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('SYNTHETIC-QUEUE-FAILURE'));
        $this->app->instance(BusDispatcher::class, $bus);
        $row = DB::transaction(fn () => app(Outbox::class)->record('SubscriptionReconciled', $this->customer, ['status' => 'ACTIVE']));
        self::assertNull($row->fresh()->dispatched_at);
        self::assertSame(0, $row->fresh()->attempts);
        $delivered = [];
        Event::listen(SubscriptionReconciled::class, function ($event) use (&$delivered): void {
            $delivered[] = $event->id;
        });
        self::assertTrue(app(OutboxDispatcher::class)->dispatch($row->id));
        self::assertSame([$row->id], $delivered);
        self::assertSame(1, $row->fresh()->attempts);
    }

    public function test_listener_failure_retries_the_same_stable_event_id(): void
    {
        $row = $this->row();
        $delivered = [];
        Event::listen(SubscriptionReconciled::class, function ($event) use (&$delivered): void {
            $delivered[] = $event->id;
            if (count($delivered) === 1) {
                throw new RuntimeException('Listener failed');
            }
        });
        try {
            app(OutboxDispatcher::class)->dispatch($row->id);
            self::fail('Expected listener failure');
        } catch (RuntimeException) {
        }
        self::assertNull($row->fresh()->dispatched_at);
        self::assertNull($row->fresh()->lease_token);
        self::assertSame(1, $row->fresh()->attempts);
        self::assertTrue(app(OutboxDispatcher::class)->dispatch($row->id));
        self::assertSame([$row->id, $row->id], $delivered);
        self::assertSame(2, $row->fresh()->attempts);
        self::assertNotNull($row->fresh()->dispatched_at);
    }

    public function test_a_stale_dispatcher_cannot_acknowledge_the_new_lease_and_expiry_allows_redelivery(): void
    {
        $row = $this->row();
        $delivered = [];
        Event::listen(SubscriptionReconciled::class, function ($event) use (&$delivered, $row): void {
            $delivered[] = $event->id;
            if (count($delivered) === 1) {
                DomainEvent::whereKey($row->id)->update(['lease_token' => 'replacement-lease', 'lease_until' => now()->addSeconds(30)]);
            }
        });
        self::assertFalse(app(OutboxDispatcher::class)->dispatch($row->id));
        self::assertNull($row->fresh()->dispatched_at);
        self::assertSame('replacement-lease', $row->fresh()->lease_token);
        self::assertFalse(app(OutboxDispatcher::class)->dispatch($row->id));
        Carbon::setTestNow(now()->addSeconds(31));
        self::assertTrue(app(OutboxDispatcher::class)->dispatch($row->id));
        self::assertSame([$row->id, $row->id], $delivered);
    }

    public function test_foreign_rows_and_jobs_cannot_cross_the_configured_context(): void
    {
        $row = $this->row(['organization_id' => 'foreign-org']);
        self::assertFalse(app(OutboxDispatcher::class)->dispatch($row->id));
        self::assertSame(0, $row->fresh()->attempts);
        $this->app->bind(OutboxDispatcher::class, static fn () => throw new RuntimeException('Must not resolve dispatcher'));
        $this->expectException(OwnershipException::class);
        (new DispatchDomainEvent($row->id, 'sandbox', 'foreign-org'))->handle(app(BillingContext::class));
    }

    public function test_outbox_rejects_profile_or_secret_payload_fields_before_persistence(): void
    {
        try {
            app(Outbox::class)->record('SubscriptionReconciled', $this->customer, ['client_secret' => 'SYNTHETIC-SECRET']);
            self::fail('Expected unsafe event field rejection');
        } catch (EvidenceException) {
        }
        self::assertSame(0, DomainEvent::count());
    }

    public function test_one_delivery_id_cannot_be_reassigned_to_another_observation(): void
    {
        $changes = ['event_name' => 'WebhookProcessed', 'resource_type' => 'airwallex_checkouts', 'resource_id' => 'another-resource', 'payload' => ['status' => 'CANCELLED']];
        foreach ($changes as $field => $replacement) {
            $row = $this->row()->fresh();
            $original = $row->getAttributes();
            try {
                $row->setAttribute($field, $replacement)->save();
                self::fail('Expected immutable observation rejection');
            } catch (OwnershipException) {
            }
            self::assertSame($original, $row->fresh()->getAttributes());
            $row->refresh()->update(['attempts' => 2, 'lease_token' => 'delivery-lease', 'lease_until' => now()->addSeconds(30)]);
            self::assertSame(2, $row->fresh()->attempts);
        }
    }

    public function test_guard_stack_restores_outer_guard_after_failure_and_clears_at_end(): void
    {
        $guard = app(CommitGuard::class);
        $calls = [];
        $guard->during(function () use (&$calls): void {
            $calls[] = 'outer';
        }, function () use ($guard, &$calls): void {
            try {
                $guard->during(function () use (&$calls): void {
                    $calls[] = 'inner';
                    throw new RuntimeException('inner failure');
                }, fn () => $guard->assertMayCommit());
            } catch (RuntimeException) {
            }
            $guard->assertMayCommit();
        });
        $guard->assertMayCommit();
        self::assertSame(['outer', 'inner', 'outer'], $calls);
    }
}
