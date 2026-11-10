<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ProtocolException;
use Jojeringnax\Airwallex\Exception\WebhookVerificationException;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Jobs\ProcessWebhookReceipt;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Services\BillingWebhookProcessor;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\ReceiptManager;
use Jojeringnax\AirwallexLaravel\Services\ReceiptOutcome;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

class WebhookReceiptTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('airwallex.webhook.secret', 'synthetic-webhook-secret');
        $app['config']->set('airwallex.webhook.path', 'billing-notify');
        $app['config']->set('airwallex.webhook.middleware', [ReceiptTestMiddleware::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('receipt_commits', function (Blueprint $table): void {
            $table->string('receipt_id');
        });
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_real_sdk_verifies_exact_body_before_encrypted_durable_acceptance(): void
    {
        $body = " {\n".substr($this->body(), 1, -1).',"precision":5.00000000000000001,"profile":"SYNTHETIC-PROFILE"} ';
        $receipt = $this->accept($body);
        self::assertSame('pending', $receipt->status);
        self::assertSame($body, $receipt->body);
        self::assertSame(hash('sha256', $body), $receipt->digest);
        self::assertSame('event-test', $receipt->event_id);
        self::assertSame('subscription.active', $receipt->event_name);
        self::assertSame('sub-test', $receipt->resource_id);
        self::assertStringNotContainsString('SYNTHETIC-PROFILE', $receipt->getRawOriginal('body'));
        self::assertStringNotContainsString('SYNTHETIC-PROFILE', $receipt->toJson());
        self::assertFalse($this->app->resolved(Client::class));
        Bus::assertNothingDispatched();
    }

    #[DataProvider('timestampBoundaries')]
    public function test_timestamp_tolerance_is_native_milliseconds_in_both_directions(int $offset, bool $valid): void
    {
        $body = $this->body();
        $timestamp = (string) (now()->getTimestampMs() + $offset);
        if (! $valid) {
            $this->expectException(WebhookVerificationException::class);
        }
        try {
            $receipt = app(ReceiptManager::class)->accept($body, $timestamp, $this->signature($body, $timestamp));
            self::assertSame('pending', $receipt->status);
        } finally {
            self::assertSame($valid ? 1 : 0, WebhookReceipt::count());
        }
    }

    public static function timestampBoundaries(): array
    {
        return [[-300000, true], [300000, true], [-300001, false], [300001, false]];
    }

    #[DataProvider('malformedHeaders')]
    public function test_malformed_signature_headers_never_persist_work(string $timestamp, string $signature): void
    {
        $this->expectException(WebhookVerificationException::class);
        try {
            app(ReceiptManager::class)->accept($this->body(), $timestamp, $signature);
        } finally {
            self::assertSame(0, WebhookReceipt::count());
            Bus::assertNothingDispatched();
        }
    }

    public static function malformedHeaders(): array
    {
        return [['', ''], ['not-a-timestamp', str_repeat('a', 64)], ['123 ', str_repeat('a', 64)], ['123', str_repeat('a', 63)], ['123', 'sha256='.str_repeat('a', 64)]];
    }

    public function test_leading_timestamp_zeroes_are_signed_without_normalization(): void
    {
        $body = $this->body();
        $timestamp = '000'.now()->getTimestampMs();
        self::assertSame('pending', app(ReceiptManager::class)->accept($body, $timestamp, strtoupper($this->signature($body, $timestamp)))->status);
    }

    public function test_raw_whitespace_tampering_fails_before_json_decode(): void
    {
        $timestamp = (string) now()->getTimestampMs();
        $this->expectException(WebhookVerificationException::class);
        app(ReceiptManager::class)->accept('{ invalid JSON ', $timestamp, $this->signature($this->body(), $timestamp));
    }

    public function test_signed_invalid_json_is_rejected_by_sdk_decoder(): void
    {
        $this->expectException(ProtocolException::class);
        try {
            $this->accept('{ invalid JSON ');
        } finally {
            self::assertSame(0, WebhookReceipt::count());
        }
    }

    #[DataProvider('envelopeClassifications')]
    public function test_authenticated_envelopes_are_retained_with_explicit_classification(array $changes, string $status, string $reason): void
    {
        $receipt = $this->accept($this->body($changes));
        self::assertSame($status, $receipt->status);
        self::assertSame($reason, $receipt->last_error);
        self::assertNotNull($receipt->body);
        self::assertFalse(app(ReceiptManager::class)->dispatch($receipt));
        Bus::assertNothingDispatched();
    }

    public static function envelopeClassifications(): array
    {
        return [
            [['org_id' => 'foreign'], 'quarantined', 'foreign_context'],
            [['org_id' => null, 'account_id' => 'org-test'], 'quarantined', 'foreign_context'],
            [['account_id' => 'org-test'], 'quarantined', 'foreign_context'],
            [['version' => 'future'], 'quarantined', 'unsupported_version'],
            [['name' => 'payment_source.updated'], 'ignored', 'unsupported_event'],
            [['data' => ['id' => 'sub-test', 'object' => null]], 'quarantined', 'malformed_resource'],
            [['created_at' => null], 'quarantined', 'malformed_envelope'],
        ];
    }

    public function test_missing_event_identity_uses_raw_digest_and_duplicate_acceptance_converges(): void
    {
        $body = $this->body(['id' => null]);
        $first = $this->accept($body);
        $duplicate = $this->accept($body);
        self::assertSame('invalid/'.hash('sha256', $body), $first->event_id);
        self::assertSame($first->id, $duplicate->id);
        self::assertSame('quarantined', $duplicate->status);
        self::assertSame(1, WebhookReceipt::count());
    }

    public function test_duplicate_and_conflicting_id_deliveries_preserve_original_body_and_fence_worker(): void
    {
        $first = $this->accept($this->body());
        self::assertSame($first->id, $this->accept($this->body())->id);
        $this->processor(function (WebhookReceipt $receipt): ReceiptOutcome {
            $conflict = $this->accept($this->body(['created_at' => '2026-11-10T12:00:01Z']));
            self::assertSame('quarantined', $conflict->status);
            app(CommitGuard::class)->assertMayCommit();

            return new ReceiptOutcome;
        });
        $result = app(ReceiptManager::class)->process($first->id);
        self::assertSame('quarantined', $result->status);
        self::assertSame('event_digest_conflict', $result->last_error);
        self::assertSame($this->body(), $result->body);
        self::assertNull($result->lease_token);
        self::assertSame(1, $result->attempts);
        self::assertSame(0, DB::table('receipt_commits')->count());
        $this->expectException(ConflictException::class);
        app(ReceiptManager::class)->requestReplay($result);
    }

    #[DataProvider('concurrentBodies')]
    public function test_separate_receivers_converge_on_one_receipt_with_native_sqlite_uniqueness(bool $conflicting): void
    {
        $directory = sys_get_temp_dir().'/airwallex-receipt-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        $barrier = $directory.'/go';
        $worker = $directory.'/worker.php';
        file_put_contents($worker, <<<'PHP'
<?php
declare(strict_types=1);
[$script, $root, $database, $barrier, $body, $timestamp, $signature] = $argv;
require $root.'/vendor/autoload.php';
$app = new Illuminate\Foundation\Application($root);
Illuminate\Foundation\Application::setInstance($app);
$app->instance('config', new Illuminate\Config\Repository(['airwallex' => ['webhook' => ['secret' => 'synthetic-webhook-secret', 'tolerance_milliseconds' => 300000, 'version' => '2026-08-21']]]));
$capsule = new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000], 'race');
$capsule->getDatabaseManager()->setDefaultConnection('race');
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
Illuminate\Database\Eloquent\Model::encryptUsing(new Illuminate\Encryption\Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
Illuminate\Support\Carbon::setTestNow('2026-11-10T12:00:00Z');
$manager = new Jojeringnax\AirwallexLaravel\Services\ReceiptManager(new Jojeringnax\AirwallexLaravel\BillingContext('sandbox', 'org-test'));
fwrite(STDOUT, "READY\n");
$deadline = microtime(true) + 10;
while (!file_exists($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Receipt race barrier timeout.'); }
    usleep(1000);
}
try {
    $receipt = $manager->accept($body, $timestamp, $signature);
    fwrite(STDOUT, json_encode(['receipt' => $receipt->id], JSON_THROW_ON_ERROR));
} catch (Jojeringnax\AirwallexLaravel\Exception\EvidenceException) {
    fwrite(STDOUT, json_encode(['retryable' => true], JSON_THROW_ON_ERROR));
}
PHP);
        config(['database.connections.testing.database' => $database, 'database.connections.testing.busy_timeout' => 10000]);
        DB::purge('testing');
        $processes = [];
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            $bodies = [$this->body(), $this->body($conflicting ? ['created_at' => '2026-11-10T12:00:01Z'] : [])];
            $timestamp = (string) now()->getTimestampMs();
            foreach ($bodies as $body) {
                $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', $worker, dirname(__DIR__), $database, $barrier, $body, $timestamp, $this->signature($body, $timestamp)], dirname(__DIR__));
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 5;
            do {
                $ready = count(array_filter($processes, fn (Process $process) => str_contains($process->getOutput(), 'READY')));
                if ($ready === 2) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $ready, implode('\n', array_map(fn (Process $process) => $process->getErrorOutput(), $processes)));
            touch($barrier);
            $ids = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $result = json_decode(trim(str_replace("READY\n", '', $process->getOutput())), true, flags: JSON_THROW_ON_ERROR);
                if (isset($result['receipt'])) {
                    $ids[] = $result['receipt'];
                } else {
                    self::assertTrue($result['retryable']);
                }
            }
            foreach ($bodies as $body) {
                $ids[] = $this->accept($body)->id;
            }
            self::assertCount(1, array_unique($ids));
            self::assertSame(1, WebhookReceipt::count());
            self::assertSame($conflicting ? 'quarantined' : 'pending', WebhookReceipt::first()->status);
            self::assertContains(WebhookReceipt::first()->body, $bodies);
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            DB::disconnect('testing');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function concurrentBodies(): array
    {
        return [[false], [true]];
    }

    public function test_dispatch_failure_retains_work_for_bounded_recovery(): void
    {
        $receipt = $this->accept($this->body());
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('SYNTHETIC-QUEUE-SECRET'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        self::assertFalse(app(ReceiptManager::class)->dispatch($receipt));
        self::assertSame('pending', $receipt->fresh()->status);
        self::assertSame('dispatch_failed', $receipt->fresh()->last_error);
        Bus::fake();
        self::assertSame(1, app(ReceiptManager::class)->replay(1));
        Bus::assertDispatched(ProcessWebhookReceipt::class, fn ($job) => $job->receiptId === $receipt->id && $job->environment === 'sandbox' && $job->organizationId === 'org-test');
    }

    public function test_http_receiver_uses_configured_route_middleware_and_acknowledges_after_commit(): void
    {
        $body = $this->body();
        Bus::assertNothingDispatched();
        $this->postRaw($body)->assertOk()->assertHeader('X-Receipt-Middleware', 'yes');
        self::assertSame(1, WebhookReceipt::count());
        Bus::assertDispatched(ProcessWebhookReceipt::class, fn ($job) => WebhookReceipt::whereKey($job->receiptId)->exists());
        $this->get('/billing-notify')->assertStatus(405);
    }

    public function test_http_receiver_preserves_raw_bytes_and_accepts_the_approved_wrapped_form(): void
    {
        $resource = ['id' => 'sub-test', 'billing_customer_id' => 'customer-test', 'status' => 'ACTIVE'];
        $body = "\n ".$this->body(['data' => ['object' => $resource, 'unknown' => 'retained']])."\n";
        $this->postRaw($body)->assertOk();
        self::assertSame($body, WebhookReceipt::first()->body);
        self::assertSame('pending', WebhookReceipt::first()->status);
        self::assertSame('sub-test', WebhookReceipt::first()->resource_id);
    }

    public function test_http_rejects_multiple_headers_tampered_bodies_and_signed_invalid_json(): void
    {
        $body = $this->body();
        $timestamp = (string) now()->getTimestampMs();
        $signature = $this->signature($body, $timestamp);
        $this->call('POST', '/billing-notify', [], [], [], ['HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => [$signature, $signature]], $body)->assertStatus(400);
        $this->call('POST', '/billing-notify', [], [], [], ['HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => $signature], $body.' ')->assertStatus(400);
        $this->postRaw('{ invalid JSON ')->assertStatus(400);
        self::assertSame(0, WebhookReceipt::count());
        Bus::assertNothingDispatched();
    }

    public function test_dispatched_job_contains_context_and_id_without_body_or_secret(): void
    {
        config(['airwallex.webhook.queue' => 'billing-receipts']);
        $receipt = $this->accept($this->body(['profile' => 'SYNTHETIC-PROFILE']));
        self::assertTrue(app(ReceiptManager::class)->dispatch($receipt));
        Bus::assertDispatched(ProcessWebhookReceipt::class, function (ProcessWebhookReceipt $job) use ($receipt): bool {
            self::assertSame($receipt->id, $job->receiptId);
            self::assertSame('billing-receipts', $job->queue);
            self::assertStringNotContainsString('synthetic-webhook-secret', serialize($job));
            self::assertStringNotContainsString('SYNTHETIC-PROFILE', serialize($job));

            return true;
        });
    }

    public function test_http_queue_failure_still_acknowledges_durable_receipt(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('SYNTHETIC-QUEUE-SECRET'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $this->postRaw($this->body())->assertOk();
        self::assertSame('pending', WebhookReceipt::first()->status);
    }

    public function test_storage_failure_is_safe_retryable_http_failure_without_dispatch(): void
    {
        config(['airwallex.models.webhook_receipt' => FailingReceipt::class]);
        $this->postRaw($this->body())->assertStatus(503)->assertDontSee('SYNTHETIC-STORAGE-SECRET');
        self::assertSame(0, WebhookReceipt::count());
        Bus::assertNothingDispatched();
    }

    public function test_missing_headers_and_invalid_receiver_configuration_cannot_enqueue(): void
    {
        $this->post('/billing-notify')->assertStatus(400);
        foreach (['secret' => null, 'tolerance_milliseconds' => -1, 'version' => 'future'] as $key => $value) {
            $previous = config('airwallex.webhook.'.$key);
            config(['airwallex.webhook.'.$key => $value]);
            $this->postRaw($this->body())->assertStatus(503);
            config(['airwallex.webhook.'.$key => $previous]);
        }
        self::assertSame(0, WebhookReceipt::count());
        Bus::assertNothingDispatched();
    }

    public function test_worker_fences_finalization_and_records_outcome_in_the_same_transaction(): void
    {
        $receipt = $this->accept($this->body());
        $this->processor(function (WebhookReceipt $claimed): ReceiptOutcome {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame('processing', $claimed->fresh()->status);
            self::assertSame(1, $claimed->attempts);

            return new ReceiptOutcome;
        }, function (WebhookReceipt $finished): void {
            self::assertGreaterThan(0, DB::transactionLevel());
            self::assertSame('processed', $finished->status);
            DB::table('receipt_commits')->insert(['receipt_id' => $finished->id]);
        });
        $manager = app(ReceiptManager::class);
        self::assertSame('processed', $manager->process($receipt->id)->status);
        self::assertSame('processed', $manager->process($receipt->id)->status);
        self::assertSame(1, $receipt->fresh()->attempts);
        self::assertNull($receipt->fresh()->lease_token);
        self::assertSame(1, DB::table('receipt_commits')->count());
    }

    public function test_record_outcome_failure_rolls_back_final_status_and_is_retryable(): void
    {
        $receipt = $this->accept($this->body());
        $this->processor(fn () => new ReceiptOutcome, function (WebhookReceipt $finished): void {
            DB::table('receipt_commits')->insert(['receipt_id' => $finished->id]);
            throw new RuntimeException('SYNTHETIC-COMMIT-SECRET');
        });
        $failed = app(ReceiptManager::class)->process($receipt->id);
        self::assertSame('failed', $failed->status);
        self::assertSame('processing_failed', $failed->last_error);
        self::assertSame(0, DB::table('receipt_commits')->count());
        $this->processor(fn () => new ReceiptOutcome);
        self::assertSame('processed', app(ReceiptManager::class)->process($receipt->id)->status);
        self::assertSame(2, $receipt->fresh()->attempts);
        self::assertSame(1, DB::table('receipt_commits')->count());
    }

    public function test_expired_worker_cannot_finalize_after_a_replacement_worker_commits(): void
    {
        $receipt = $this->accept($this->body());
        $this->processor(function () use ($receipt): ReceiptOutcome {
            Carbon::setTestNow(now()->addSeconds(301));
            $this->processor(fn () => new ReceiptOutcome);
            self::assertSame('processed', app(ReceiptManager::class)->process($receipt->id)->status);
            app(CommitGuard::class)->assertMayCommit();

            return new ReceiptOutcome;
        });
        $result = app(ReceiptManager::class)->process($receipt->id);
        self::assertSame('processed', $result->status);
        self::assertSame(2, $result->attempts);
        self::assertSame(1, DB::table('receipt_commits')->count());
    }

    public function test_explicit_replay_uses_retained_verified_evidence_without_new_signature_freshness(): void
    {
        $receipt = $this->accept($this->body());
        $this->processor(fn () => new ReceiptOutcome);
        app(ReceiptManager::class)->process($receipt->id);
        Carbon::setTestNow(now()->addDay());
        config(['airwallex.webhook.secret' => 'rotated-synthetic-secret']);
        $replay = app(ReceiptManager::class)->requestReplay($receipt);
        self::assertSame('pending', $replay->status);
        self::assertSame(1, $replay->attempts);
        self::assertSame($receipt->digest, $replay->digest);
        self::assertTrue(app(ReceiptManager::class)->dispatch($replay));
    }

    public function test_active_lease_and_purged_body_cannot_be_explicitly_replayed(): void
    {
        $receipt = $this->accept($this->body());
        $receipt->update(['status' => 'processing', 'lease_token' => 'held', 'lease_until' => now()->addMinute()]);
        try {
            app(ReceiptManager::class)->requestReplay($receipt);
            self::fail('Active processing lease was stolen.');
        } catch (ConflictException) {
            self::assertSame('held', $receipt->fresh()->lease_token);
        }
        $receipt->update(['status' => 'processed', 'lease_token' => null, 'lease_until' => null, 'body' => null]);
        $this->expectException(ConflictException::class);
        app(ReceiptManager::class)->requestReplay($receipt);
    }

    public function test_prune_is_bounded_terminal_only_and_preserves_deduplication_identity(): void
    {
        foreach (['processed', 'ignored', 'quarantined', 'pending', 'processing', 'failed'] as $index => $status) {
            $receipt = $this->accept($this->body(['id' => 'event-'.$index]));
            $receipt->forceFill(['status' => $status, 'updated_at' => now()->subDays(8)])->save();
        }
        self::assertSame(2, app(ReceiptManager::class)->prune(2));
        self::assertSame(1, app(ReceiptManager::class)->prune(2));
        self::assertSame(3, WebhookReceipt::whereNotNull('body')->count());
        self::assertSame(6, WebhookReceipt::count());
        $duplicate = $this->accept($this->body(['id' => 'event-0']));
        self::assertNull($duplicate->body);
        self::assertSame('processed', $duplicate->status);
    }

    public function test_job_context_mismatch_is_rejected_before_resolving_processing_services(): void
    {
        $this->app->bind(ReceiptManager::class, fn () => throw new RuntimeException('Processor must not be resolved.'));
        $this->expectException(OwnershipException::class);
        try {
            (new ProcessWebhookReceipt('receipt-test', 'production', 'org-test'))->handle();
        } finally {
            self::assertFalse($this->app->resolved(Client::class));
        }
    }

    public function test_same_event_id_isolated_by_context_cannot_be_dispatched_or_pruned_across_owners(): void
    {
        $local = $this->accept($this->body());
        $foreignManager = new ReceiptManager(new BillingContext('production', 'org-other'));
        $body = $this->body(['org_id' => 'org-other']);
        $timestamp = (string) now()->getTimestampMs();
        $foreign = $foreignManager->accept($body, $timestamp, $this->signature($body, $timestamp));
        self::assertNotSame($local->id, $foreign->id);
        self::assertSame(2, WebhookReceipt::count());
        foreach ([$local, $foreign] as $receipt) {
            $receipt->forceFill(['status' => 'processed', 'updated_at' => now()->subDays(8)])->save();
        }
        self::assertSame(1, app(ReceiptManager::class)->prune());
        self::assertNotNull($foreign->fresh()->body);
        $this->expectException(OwnershipException::class);
        app(ReceiptManager::class)->dispatch($foreign);
    }

    private function body(array $changes = []): string
    {
        return json_encode(array_replace(['id' => 'event-test', 'name' => 'subscription.active', 'org_id' => 'org-test', 'version' => '2026-08-21', 'created_at' => '2026-11-10T12:00:00Z', 'data' => ['id' => 'sub-test', 'billing_customer_id' => 'customer-test', 'status' => 'ACTIVE']], $changes), JSON_THROW_ON_ERROR);
    }

    private function signature(string $body, string $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.$body, 'synthetic-webhook-secret');
    }

    private function accept(string $body): WebhookReceipt
    {
        $timestamp = (string) now()->getTimestampMs();

        return app(ReceiptManager::class)->accept($body, $timestamp, $this->signature($body, $timestamp));
    }

    private function postRaw(string $body)
    {
        $timestamp = (string) now()->getTimestampMs();

        return $this->call('POST', '/billing-notify', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => $this->signature($body, $timestamp)], $body);
    }

    private function processor(Closure $work, ?Closure $record = null): void
    {
        $this->app->instance(BillingWebhookProcessor::class, new ReceiptTestProcessor($work, $record));
    }
}

class ReceiptTestProcessor extends BillingWebhookProcessor
{
    public function __construct(private readonly Closure $work, private readonly ?Closure $record = null) {}

    public function process(WebhookReceipt $receipt): ReceiptOutcome
    {
        return ($this->work)($receipt);
    }

    public function recordOutcome(WebhookReceipt $receipt, ReceiptOutcome $outcome): void
    {
        if ($this->record !== null) {
            ($this->record)($receipt);
        } else {
            DB::table('receipt_commits')->insert(['receipt_id' => $receipt->id]);
        }
    }
}

class ReceiptTestMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Receipt-Middleware', 'yes');

        return $response;
    }
}

class FailingReceipt extends WebhookReceipt
{
    protected static function booted(): void
    {
        parent::booted();
        static::creating(fn () => throw new RuntimeException('SYNTHETIC-STORAGE-SECRET'));
    }
}
