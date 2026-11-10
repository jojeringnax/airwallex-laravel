<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Events\SubscriptionReconciled;
use Jojeringnax\AirwallexLaravel\Jobs\ProcessWebhookReceipt;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/** Real Laravel queue/command journeys; all provider responses are synthetic. */
class QueueRecoveryTest extends TestCase
{
    private string $directory;

    private Customer $customer;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->directory = sys_get_temp_dir().'/airwallex-queue-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->directory.'/database.sqlite');
        config([
            'database.connections.testing' => TestDatabase::connection($this->directory.'/database.sqlite'),
            'queue.default' => 'database',
            'queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 60, 'after_commit' => false],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs'],
            'airwallex.webhook.secret' => 'synthetic-queue-secret',
            'airwallex.webhook.queue' => 'billing-receipts',
            'airwallex.events.queue' => 'billing-events',
        ]);
        DB::purge('testing');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $this->team = Team::create(['id' => 'queue-team']);
        $this->customer = app(OperationManager::class)->reserveCustomer($this->team);
        $this->customer->update(['remote_id' => 'customer-test']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('testing');
        $this->app['files']->deleteDirectory($this->directory);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cache(string $driver): void
    {
        config([
            'cache.stores.billing-queue-test' => ['driver' => $driver, 'path' => $this->directory.'/cache'],
            'airwallex.cache_store' => 'billing-queue-test',
        ]);
    }

    private function fake(array $responses): FakeHttpClient
    {
        app('cache')->store(config('airwallex.cache_store'))->flush();
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        // Laravel forgets scoped transport instances between daemon jobs.
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }

    private function observation(array $remote): array
    {
        return [FakeHttpClient::json($remote), Billing::page([Billing::item(['quantity' => '5.00000000000000001'])]), FakeHttpClient::json($remote)];
    }

    private function work(string $queue, int $limit = 10): void
    {
        // Daemon mode runs Laravel's normal scope reset before each actual job.
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--max-jobs' => $limit, '--sleep' => 0, '--tries' => 1, '--backoff' => 0, '--timeout' => 0, '--memory' => 256]));
    }

    private function knownSubscription(): Subscription
    {
        $this->fake($this->observation(Billing::subscription(['status' => 'UNPAID'])));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        if (config('queue.default') === 'database') {
            $this->work('billing-events');
        }

        return $subscription;
    }

    private function body(string $eventId, string $owner = 'org-test'): string
    {
        return json_encode(['id' => $eventId, 'name' => 'subscription.active', 'org_id' => $owner, 'version' => '2026-08-21', 'created_at' => '2026-11-10T12:00:00Z', 'data' => ['object' => Billing::subscription(['metadata' => ['profile' => 'SYNTHETIC-PROFILE-PRIVATE']])]], JSON_THROW_ON_ERROR);
    }

    private function acceptHttp(string $eventId): WebhookReceipt
    {
        $body = $this->body($eventId);
        $timestamp = (string) now()->getTimestampMs();
        $this->call('POST', '/airwallex/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.$body, 'synthetic-queue-secret')], $body)->assertOk();

        return WebhookReceipt::where('event_id', $eventId)->firstOrFail();
    }

    private function retained(string $eventId, string $status, bool $old = true, string $owner = 'org-test'): WebhookReceipt
    {
        $body = $this->body($eventId, $owner);

        return WebhookReceipt::create(['environment' => 'sandbox', 'organization_id' => $owner, 'event_id' => $eventId, 'digest' => hash('sha256', $body), 'event_name' => 'subscription.active', 'version' => '2026-08-21', 'resource_id' => 'sub-test', 'body' => $body, 'status' => $status, 'created_at' => $old ? now()->subDays(8) : now(), 'updated_at' => $old ? now()->subDays(8) : now()]);
    }

    #[DataProvider('runtimeStores')]
    public function test_signed_http_to_canonical_projection_and_listener_across_qualified_stores(string $queue, string $cache): void
    {
        config(['queue.default' => $queue]);
        $this->cache($cache);
        $subscription = $this->knownSubscription();
        $delivered = [];
        Event::listen(SubscriptionReconciled::class, function (SubscriptionReconciled $event) use (&$delivered, $subscription): void {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame('ACTIVE', $subscription->fresh()->status);
            $delivered[] = $event->id;
        });
        $current = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake($this->observation($current));
        $receipt = $this->acceptHttp('queued-active');
        if ($queue === 'database') {
            self::assertSame('pending', $receipt->status);
            self::assertSame([], $http->requests);
            self::assertCount(1, DB::table('jobs')->where('queue', 'billing-receipts')->get());
            $payload = DB::table('jobs')->value('payload');
            self::assertStringContainsString(ProcessWebhookReceipt::class, json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['displayName']);
            self::assertStringNotContainsString('SYNTHETIC-PROFILE-PRIVATE', $payload);
            self::assertStringNotContainsString('synthetic-queue-secret', $payload);
            $this->work('billing-receipts', 1);
            self::assertSame('processed', $receipt->fresh()->status);
            self::assertSame([], $delivered);
            self::assertSame(2, DB::table('jobs')->where('queue', 'billing-events')->count());
            // Stop after the receipt job, discard scoped services, then start an event worker.
            $this->app->forgetScopedInstances();
            $this->work('billing-events');
        }
        self::assertSame('processed', $receipt->fresh()->status);
        self::assertSame('ACTIVE', $subscription->fresh()->status);
        self::assertSame('5.00000000000000001', $subscription->items()->first()->quantity);
        self::assertTrue($this->team->subscribed('default'));
        self::assertCount(1, $delivered);
        self::assertNotNull(DomainEvent::findOrFail($delivered[0])->dispatched_at);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
        $count = count($http->requests);
        $this->acceptHttp('queued-active');
        self::assertSame($count, count($http->requests));
        self::assertSame(0, DB::table('jobs')->count());
        self::assertCount(1, $delivered);
    }

    public static function runtimeStores(): array
    {
        return [['sync', 'array'], ['sync', 'file'], ['database', 'array'], ['database', 'file']];
    }

    public function test_receipts_command_recovers_database_queue_outage_in_bounded_context_scoped_batches(): void
    {
        $this->cache('file');
        $subscription = $this->knownSubscription();
        Schema::rename('jobs', 'unavailable_jobs');
        try {
            $first = $this->acceptHttp('outage-first');
            $second = $this->acceptHttp('outage-second');
        } finally {
            Schema::rename('unavailable_jobs', 'jobs');
        }
        $foreign = $this->retained('foreign-pending', 'pending', owner: 'foreign-org');
        self::assertSame('pending', $first->status);
        self::assertSame('dispatch_failed', $first->last_error);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, Artisan::call('airwallex:receipts', ['--limit' => '1']));
        self::assertStringContainsString($first->id, Artisan::output());
        self::assertStringNotContainsString($second->id, Artisan::output());
        self::assertStringNotContainsString($foreign->id, Artisan::output());
        self::assertStringNotContainsString('SYNTHETIC-PROFILE-PRIVATE', Artisan::output());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(0, Artisan::call('airwallex:receipts', ['--limit' => '1', '--after' => $first->id]));
        self::assertStringContainsString($second->id, Artisan::output());
        self::assertSame(2, DB::table('jobs')->count());
        $current = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($current), ...$this->observation($current)]);
        $this->work('billing-receipts', 2);
        self::assertSame('processed', $first->fresh()->status);
        self::assertSame('processed', $second->fresh()->status);
        self::assertSame('ACTIVE', $subscription->fresh()->status);
        self::assertSame('pending', $foreign->fresh()->status);
        self::assertSame(0, $foreign->fresh()->attempts);
        self::assertSame(1, Artisan::call('airwallex:receipts', ['--id' => $foreign->id]));
        self::assertStringNotContainsString($foreign->id, Artisan::output());
        self::assertSame(1, Artisan::call('airwallex:receipts', ['--limit' => '0']));
        $this->work('billing-events');
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(1, Subscription::count());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function test_events_command_recovers_a_failed_database_job_with_the_same_listener_id(): void
    {
        $this->cache('array');
        $this->knownSubscription();
        $delivered = [];
        Event::listen(SubscriptionReconciled::class, function (SubscriptionReconciled $event) use (&$delivered): void {
            $delivered[] = $event->id;
            if (count($delivered) === 1) {
                throw new RuntimeException('Synthetic listener failure');
            }
        });
        $this->fake($this->observation(Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z'])));
        $this->acceptHttp('listener-retry');
        $this->work('billing-receipts', 1);
        $this->work('billing-events', 1);
        self::assertCount(1, $delivered);
        $row = DomainEvent::findOrFail($delivered[0]);
        self::assertNull($row->dispatched_at);
        self::assertSame(1, $row->attempts);
        self::assertSame(1, DB::table('failed_jobs')->count());
        $foreign = DomainEvent::create(['environment' => 'sandbox', 'organization_id' => 'foreign-org', 'resource_type' => 'airwallex_subscriptions', 'resource_id' => 'foreign-sub', 'event_name' => 'SubscriptionReconciled', 'payload' => ['status' => 'ACTIVE']]);
        $busy = DomainEvent::create([...app(BillingContext::class)->attributes(), 'resource_type' => 'airwallex_subscriptions', 'resource_id' => 'busy-sub', 'event_name' => 'SubscriptionReconciled', 'payload' => ['status' => 'ACTIVE'], 'lease_token' => 'another-worker', 'lease_until' => now()->addMinutes(1)]);
        self::assertSame(0, Artisan::call('airwallex:events', ['--limit' => '1']));
        self::assertStringContainsString($row->id, Artisan::output());
        self::assertStringNotContainsString($foreign->id, Artisan::output());
        self::assertStringNotContainsString($busy->id, Artisan::output());
        self::assertSame([$row->id, $row->id], $delivered);
        self::assertNotNull($row->fresh()->dispatched_at);
        self::assertSame(2, $row->fresh()->attempts);
        self::assertSame(0, Artisan::call('airwallex:events', ['--limit' => '1', '--after' => $row->id]));
        self::assertSame(0, $foreign->fresh()->attempts);
        self::assertSame('another-worker', $busy->fresh()->lease_token);
        self::assertSame(1, Artisan::call('airwallex:events', ['--limit' => '0']));
        $this->work('billing-events');
        self::assertSame([$row->id, $row->id], $delivered);
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function test_prune_command_is_bounded_and_retains_pending_failed_foreign_and_fresh_bodies(): void
    {
        $terminal = [$this->retained('old-processed', 'processed'), $this->retained('old-ignored', 'ignored'), $this->retained('old-quarantined', 'quarantined')];
        $retained = [$this->retained('old-pending', 'pending'), $this->retained('old-failed', 'failed'), $this->retained('old-processing', 'processing'), $this->retained('fresh-processed', 'processed', false), $this->retained('foreign-terminal', 'processed', owner: 'foreign-org')];
        self::assertSame(0, Artisan::call('airwallex:prune-receipts', ['--limit' => '2']));
        self::assertSame(2, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['count']);
        self::assertSame(2, WebhookReceipt::whereNull('body')->count());
        self::assertSame(0, Artisan::call('airwallex:prune-receipts', ['--limit' => '2']));
        self::assertSame(1, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['count']);
        foreach ($terminal as $receipt) {
            self::assertNull($receipt->fresh()->body);
            self::assertSame($receipt->digest, $receipt->fresh()->digest);
            self::assertSame($receipt->event_id, $receipt->fresh()->event_id);
        }
        foreach ($retained as $receipt) {
            self::assertSame($receipt->body, $receipt->fresh()->body);
        }
        self::assertSame(0, Artisan::call('airwallex:prune-receipts', ['--limit' => '2']));
        self::assertSame(0, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['count']);
        self::assertSame(1, Artisan::call('airwallex:prune-receipts', ['--limit' => '0']));
    }

    public function test_database_worker_rejects_foreign_scalar_job_context_before_provider_access(): void
    {
        $receipt = $this->retained('wrong-context-job', 'pending', false);
        $http = $this->fake([]);
        app(Dispatcher::class)->dispatch((new ProcessWebhookReceipt($receipt->id, 'sandbox', 'foreign-org'))->onQueue('billing-receipts'));
        $this->work('billing-receipts', 1);
        self::assertSame('pending', $receipt->fresh()->status);
        self::assertSame(0, $receipt->fresh()->attempts);
        self::assertSame([], $http->requests);
        self::assertSame(1, DB::table('failed_jobs')->count());
        self::assertSame(0, DB::table('jobs')->count());
    }
}
