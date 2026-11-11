<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Events\PaymentObserved;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Payments\Client;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Jobs\ProcessReceipt;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\MethodObservation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Receipt;
use Jojeringnax\AirwallexLaravel\Payments\Models\Refund;
use Jojeringnax\AirwallexLaravel\Payments\Receipts;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

class PaymentsWebhookTest extends TestCase
{
    private string $directory;

    private Customer $customer;

    private Intent $intent;

    private array $state;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('airwallex.payments.webhook.enabled', true);
        $app['config']->set('airwallex.payments.account_id', 'account-payments');
        $app['config']->set('airwallex.payments.client_id', 'payments-client');
        $app['config']->set('airwallex.payments.api_key', 'payments-key');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11T09:00:00Z');
        $this->directory = sys_get_temp_dir().'/airwallex-payment-receipts-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->directory.'/db.sqlite');
        config(['database.connections.testing' => TestDatabase::connection($this->directory.'/db.sqlite'), 'queue.default' => 'sync',
            'queue.connections.payment-proof' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 300, 'after_commit' => false],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs'],
            'airwallex.payments.webhook.secret' => 'synthetic-payment-secret', 'airwallex.payments.webhook.queue_connection' => 'payment-proof', 'airwallex.payments.webhook.queue' => 'payment-receipts',
            'airwallex.payments.events.queue_connection' => 'payment-proof', 'airwallex.payments.events.queue' => 'payment-events']);
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
        $this->customer = Customer::create([...app(Context::class)->attributes(), 'billable_type' => 'webhook_owner', 'billable_id' => 'one', 'merchant_customer_id' => 'merchant-one', 'remote_id' => 'cus-one']);
        $operation = Operation::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'kind' => 'intent.create', 'action_key' => 'seed', 'target_id' => null, 'remote_id' => 'int-one',
            'request_id' => 'cf15d1ce-cdab-4ac7-a5cb-3948385103cc', 'intent_hash' => hash('sha256', 'synthetic'), 'intent' => [], 'status' => 'succeeded']);
        $this->state = ['id' => 'int-one', 'customer_id' => 'cus-one', 'merchant_order_id' => 'order-one', 'amount' => '10.00', 'currency' => 'USD', 'status' => 'REQUIRES_PAYMENT_METHOD', 'captured_amount' => '0.00',
            'request_id' => $operation->request_id, 'updated_at' => '2026-11-11T08:00:00Z', 'client_secret' => 'SYNTHETIC-PRIVATE-BOOTSTRAP'];
        $this->intent = Intent::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'operation_id' => $operation->id, 'remote_id' => 'int-one', 'merchant_order_id' => 'order-one',
            'amount' => '10.00', 'currency' => 'USD', 'status' => 'REQUIRES_PAYMENT_METHOD', 'captured_amount' => '0.00', 'snapshot' => $this->state]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('testing');
        $this->app['files']->deleteDirectory($this->directory);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function transport(?Closure $handler = null): object
    {
        app('cache')->flush();
        $http = new class($handler ?? fn () => FakeHttpClient::json($this->state)) implements ClientInterface
        {
            public array $requests = [];

            public function __construct(private Closure $handler) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                if (str_ends_with($request->getUri()->getPath(), '/authentication/login')) {
                    return FakeHttpClient::login();
                }

                return ($this->handler)($request);
            }
        };
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }

    private function body(string $id, array $overrides = []): string
    {
        return json_encode(array_replace(['id' => $id, 'name' => 'payment_intent.succeeded', 'account_id' => 'account-payments', 'created_at' => '2026-11-11T09:00:00Z', 'version' => '2026-08-21',
            'data' => ['object' => ['id' => 'int-one', 'status' => 'SUCCEEDED', 'customer_id' => 'cus-one']]], $overrides), JSON_THROW_ON_ERROR);
    }

    private function postDelivery(string $body): Receipt
    {
        $timestamp = (string) now()->getTimestampMs();
        $this->call('POST', '/airwallex/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.$body, 'synthetic-payment-secret')], $body)->assertOk();

        return Receipt::query()->where('digest', hash('sha256', $body))->firstOrFail();
    }

    private function work(string $queue): void
    {
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'payment-proof', '--queue' => $queue, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 0, '--memory' => 256]));
    }

    public function test_signed_event_is_only_locator_and_real_workers_deliver_canonical_observations_once_per_identity(): void
    {
        $http = $this->transport();
        $this->state = [...$this->state, 'status' => 'PENDING', 'updated_at' => '2026-11-11T09:00:01Z'];
        $observed = [];
        Event::listen(PaymentObserved::class, function (PaymentObserved $event) use (&$observed): void {
            if ($event->resourceType === 'airwallex_payment_intents') {
                $observed[] = [$event->id, $event->payload];
            }
        });
        $body = $this->body('event-pending');
        $receipt = $this->postDelivery($body);
        self::assertSame('pending', $receipt->status);
        self::assertSame([], $http->requests);
        self::assertSame('sync', config('queue.default'));
        self::assertSame(1, DB::table('jobs')->where('queue', 'payment-receipts')->count());
        $this->work('payment-receipts');
        self::assertSame('processed', $receipt->fresh()->status);
        self::assertSame('PENDING', $this->intent->fresh()->status);
        self::assertFalse($this->intent->fresh()->paid());
        $this->app->forgetScopedInstances();
        $this->work('payment-events');
        self::assertCount(1, $observed);
        $count = count($http->requests);
        $this->postDelivery($body);
        self::assertSame($count, count($http->requests));
        self::assertSame(0, DB::table('jobs')->count());
        $this->state = [...$this->state, 'status' => 'SUCCEEDED', 'captured_amount' => '10.00', 'updated_at' => '2026-11-11T09:00:02Z'];
        $this->postDelivery($this->body('event-paid', ['name' => 'payment_intent.updated']));
        $this->work('payment-receipts');
        $this->work('payment-events');
        self::assertTrue($this->intent->fresh()->paid());
        self::assertCount(2, $observed);
        self::assertNotSame($observed[0][0], $observed[1][0]);
        self::assertSame('payment_intent/int-one', $observed[1][1]['financial_identity']);
        self::assertStringNotContainsString('PRIVATE', json_encode($observed, JSON_THROW_ON_ERROR));
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_foreign_owner_version_alias_and_malformed_wrapper_are_durably_quarantined(): void
    {
        $http = $this->transport();
        foreach ([['account_id' => 'foreign'], ['version' => 'future'], ['accountId' => 'account-payments'], ['org_id' => 'org-test'], ['data' => (object) []], ['data' => ['id' => 'int-one']]] as $index => $override) {
            $receipt = $this->postDelivery($this->body('invalid-'.$index, $override));
            self::assertSame('quarantined', $receipt->status);
            self::assertNotNull($receipt->body);
        }
        self::assertSame([], $http->requests);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, WebhookReceipt::count());
    }

    public function test_conflicting_duplicate_invalidates_active_receipt_before_projection_commit_and_keeps_body_private(): void
    {
        $original = $this->body('conflict', ['private' => 'SYNTHETIC-PRIVATE-PROFILE']);
        $receipt = $this->postDelivery($original);
        $conflict = $this->body('conflict', ['private' => 'different']);
        $this->state = [...$this->state, 'status' => 'SUCCEEDED', 'captured_amount' => '10.00', 'updated_at' => '2026-11-11T09:00:02Z'];
        $this->transport(function () use ($conflict): ResponseInterface {
            $timestamp = (string) now()->getTimestampMs();
            app(Receipts::class)->accept($conflict, $timestamp, hash_hmac('sha256', $timestamp.$conflict, 'synthetic-payment-secret'));

            return FakeHttpClient::json($this->state);
        });
        $this->work('payment-receipts');
        self::assertSame('quarantined', $receipt->fresh()->status);
        self::assertSame('event_digest_conflict', $receipt->fresh()->last_error);
        self::assertSame('REQUIRES_PAYMENT_METHOD', $this->intent->fresh()->status);
        self::assertStringNotContainsString('PRIVATE-PROFILE', $receipt->fresh()->toJson());
        self::assertStringNotContainsString('PRIVATE-PROFILE', DB::table('airwallex_payment_receipts')->value('body'));
        self::assertSame(1, Artisan::call('airwallex:payments-recover', ['--family' => 'receipt', '--id' => $receipt->id, '--replay' => true]));
    }

    public function test_enqueue_outage_and_foreign_account_job_preserve_committed_receipts_for_recovery(): void
    {
        Schema::rename('jobs', 'offline_jobs');
        try {
            $receipt = $this->postDelivery($this->body('outage'));
        } finally {
            Schema::rename('offline_jobs', 'jobs');
        }
        self::assertSame('pending', $receipt->status);
        self::assertSame('dispatch_failed', $receipt->last_error);
        $http = $this->transport();
        app(Dispatcher::class)->dispatch((new ProcessReceipt($receipt->id, 'sandbox', 'foreign'))->onConnection('payment-proof')->onQueue('payment-receipts'));
        $this->work('payment-receipts');
        self::assertSame('pending', $receipt->fresh()->status);
        self::assertSame([], $http->requests);
        self::assertSame(1, DB::table('failed_jobs')->count());
        self::assertSame(0, Artisan::call('airwallex:payments-recover', ['--family' => 'receipt']));
        $this->work('payment-receipts');
        self::assertSame('processed', $receipt->fresh()->status);
        $this->app->forgetScopedInstances();
        $this->work('payment-events');
    }

    public function test_listener_failure_retries_same_observation_identity_and_expired_receipt_lease_recovers(): void
    {
        $this->transport();
        $receipt = $this->postDelivery($this->body('listener'));
        $receipt->update(['status' => 'processing', 'lease_token' => 'dead-worker', 'lease_until' => now()->subSecond()]);
        $delivered = [];
        Event::listen(PaymentObserved::class, function (PaymentObserved $event) use (&$delivered): void {
            if ($event->resourceType === 'airwallex_payment_intents') {
                $delivered[] = $event->id;
                if (count($delivered) === 1) {
                    throw new RuntimeException('Synthetic listener interruption');
                }
            }
        });
        $this->work('payment-receipts');
        self::assertSame('processed', $receipt->fresh()->status);
        $this->work('payment-events');
        self::assertCount(1, $delivered);
        self::assertNull(Observation::findOrFail($delivered[0])->getAttribute('dispatched_at'));
        self::assertSame(0, Artisan::call('airwallex:payments-recover', ['--family' => 'event']));
        self::assertSame([$delivered[0], $delivered[0]], $delivered);
        self::assertNotNull(Observation::findOrFail($delivered[0])->getAttribute('dispatched_at'));
    }

    public function test_known_method_notification_clears_default_without_inventing_consent_or_billing_source_state(): void
    {
        MethodObservation::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'remote_id' => 'mtd-one', 'status' => 'CREATED', 'method_type' => 'card', 'provider_updated_at' => '2026-11-11T08:00:00Z']);
        $instrument = Instrument::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'remote_id' => 'cst-one', 'method_id' => 'mtd-one', 'status' => 'VERIFIED', 'method_status' => 'CREATED', 'method_type' => 'card', 'snapshot' => []]);
        DB::table('airwallex_payment_instrument_defaults')->insert(['customer_id' => $this->customer->id, 'instrument_id' => $instrument->id]);
        $http = $this->transport(fn () => FakeHttpClient::json(['id' => 'mtd-one', 'customer_id' => 'cus-one', 'status' => 'DISABLED', 'type' => 'card', 'updated_at' => '2026-11-11T09:00:01Z']));
        $receipt = $this->postDelivery($this->body('method-disabled', ['name' => 'payment_method.disabled', 'data' => ['object' => ['id' => 'mtd-one']]]));
        $this->work('payment-receipts');
        self::assertSame('processed', $receipt->fresh()->status);
        self::assertSame('VERIFIED', $instrument->fresh()->status);
        self::assertSame('DISABLED', $instrument->fresh()->method_status);
        self::assertNull(DB::table('airwallex_payment_instrument_defaults')->value('instrument_id'));
        self::assertSame('/api/v1/pa/payment_methods/mtd-one', $http->requests[1]->getUri()->getPath());
        self::assertCount(2, $http->requests);
    }

    public function test_unknown_creation_requires_an_attempted_record_and_canonical_original_request_without_write_replay(): void
    {
        $operation = Operation::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'kind' => 'intent.create', 'action_key' => 'uncertain',
            'request_id' => 'a318321b-350c-4ac7-ae98-04d202638b32', 'intent_hash' => hash('sha256', 'uncertain'), 'intent' => ['amount' => '10.00'], 'status' => 'ambiguous', 'lock_key' => 'customer/'.$this->customer->id]);
        $intent = Intent::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'operation_id' => $operation->id, 'merchant_order_id' => 'order-new', 'amount' => '10.00', 'currency' => 'USD']);
        $this->state = [...$this->state, 'id' => 'int-new', 'merchant_order_id' => 'order-new', 'request_id' => $operation->request_id];
        $http = $this->transport();
        $receipt = $this->postDelivery($this->body('unknown-recovery', ['data' => ['object' => ['id' => 'int-new', 'request_id' => $operation->request_id]]]));
        $this->work('payment-receipts');
        self::assertSame('processed', $receipt->fresh()->status);
        self::assertSame('recorded_creation_recovered', $receipt->fresh()->last_error);
        self::assertSame('int-new', $intent->fresh()->remote_id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertNull($operation->fresh()->getAttribute('lock_key'));
        self::assertSame(['POST', 'GET'], array_map(fn ($request) => $request->getMethod(), $http->requests));
        $prepared = Operation::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'kind' => 'intent.create', 'action_key' => 'unattempted',
            'request_id' => 'c9993b66-20be-44a7-ac1c-cf75bd2e0579', 'intent_hash' => hash('sha256', 'unattempted'), 'intent' => [], 'status' => 'prepared']);
        $unknown = $this->postDelivery($this->body('unknown-prepared', ['data' => ['object' => ['id' => 'int-forged', 'request_id' => $prepared->request_id]]]));
        $this->work('payment-receipts');
        self::assertSame('ignored', $unknown->fresh()->status);
        self::assertSame(1, Artisan::call('airwallex:payments-recover', ['--family' => 'operation', '--id' => $prepared->id]));
        self::assertCount(2, $http->requests);
        self::assertSame('prepared', $prepared->fresh()->status);
    }

    public function test_refund_received_is_pending_and_accepted_settled_observations_keep_one_financial_identity(): void
    {
        $this->state = [...$this->state, 'status' => 'SUCCEEDED', 'captured_amount' => '10.00', 'updated_at' => '2026-11-11T09:00:01Z'];
        $operation = Operation::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'kind' => 'refund.create', 'action_key' => 'refund-command', 'remote_id' => 'rfd-one',
            'request_id' => '2f3b38b6-c279-44ed-bce7-9250caf9b44f', 'intent_hash' => hash('sha256', 'refund'), 'intent' => ['amount' => '5.00', 'payment_intent_id' => 'int-one'], 'status' => 'ambiguous', 'lock_key' => 'customer/'.$this->customer->id]);
        $refund = Refund::create([...app(Context::class)->attributes(), 'customer_id' => $this->customer->id, 'intent_id' => $this->intent->id, 'operation_id' => $operation->id, 'remote_id' => 'rfd-one', 'amount' => '5.00', 'currency' => 'USD']);
        $state = ['id' => 'rfd-one', 'payment_intent_id' => 'int-one', 'amount' => '5.00', 'currency' => 'USD', 'request_id' => $operation->request_id, 'status' => 'RECEIVED', 'updated_at' => '2026-11-11T09:00:02Z'];
        $http = $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/refunds/') ? $state : $this->state));
        $first = $this->postDelivery($this->body('refund-received', ['name' => 'refund.settled', 'data' => ['object' => ['id' => 'rfd-one']]]));
        $this->work('payment-receipts');
        self::assertSame('processed', $first->fresh()->status);
        self::assertSame('RECEIVED', $refund->fresh()->status);
        self::assertFalse($refund->fresh()->completed());
        self::assertSame('succeeded', $operation->fresh()->status); // Known request accepted, refund outcome remains pending.
        foreach (['ACCEPTED', 'SETTLED'] as $index => $status) {
            $state = [...$state, 'status' => $status, 'updated_at' => '2026-11-11T09:00:0'.($index + 3).'Z'];
            $this->transport(fn (RequestInterface $request) => FakeHttpClient::json(str_contains($request->getUri()->getPath(), '/refunds/') ? $state : $this->state));
            $receipt = $this->postDelivery($this->body('refund-'.$status, ['name' => 'refund.received', 'data' => ['object' => ['id' => 'rfd-one']]]));
            $this->work('payment-receipts');
            self::assertSame('processed', $receipt->fresh()->status);
            self::assertTrue($refund->fresh()->completed());
        }
        $rows = Observation::where('resource_type', 'airwallex_payment_refunds')->get();
        self::assertCount(3, $rows);
        self::assertSame(['refund/rfd-one'], $rows->map(fn ($row) => $row->getAttribute('payload')['financial_identity'])->unique()->values()->all());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function test_terminal_body_retention_preserves_deduplication_and_blocks_purged_replay(): void
    {
        $this->transport();
        $receipt = $this->postDelivery($this->body('old-terminal'));
        $this->work('payment-receipts');
        Receipt::whereKey($receipt->id)->update(['updated_at' => now()->subDays(8)]);
        $pending = $this->postDelivery($this->body('pending-retained'));
        Receipt::whereKey($pending->id)->update(['updated_at' => now()->subDays(8)]);
        self::assertSame(0, Artisan::call('airwallex:payments-recover', ['--family' => 'prune', '--limit' => '1']));
        self::assertNull($receipt->fresh()->body);
        self::assertNotNull($pending->fresh()->body);
        self::assertSame($receipt->digest, $receipt->fresh()->digest);
        self::assertSame(1, Artisan::call('airwallex:payments-recover', ['--family' => 'receipt', '--id' => $receipt->id, '--replay' => true]));
    }

    public function test_optional_fair_sweeps_advance_each_account_family_or_report_missing_foundation(): void
    {
        if (! class_exists('Jojeringnax\\AirwallexLaravel\\Services\\SweepManager')) {
            self::assertSame(1, Artisan::call('airwallex:payments-recover', ['--family' => 'receipt', '--sweep' => true]));

            return;
        }
        Schema::rename('jobs', 'offline_jobs');
        try {
            foreach (range(1, 5) as $index) {
                $this->postDelivery($this->body('sweep-'.$index));
            }
        } finally {
            Schema::rename('offline_jobs', 'jobs');
        }
        foreach (range(1, 3) as $run) {
            self::assertSame(0, Artisan::call('airwallex:payments-recover', ['--family' => 'receipt', '--sweep' => true, '--limit' => '2']));
        }
        self::assertSame(5, DB::table('jobs')->where('queue', 'payment-receipts')->count());
        $progress = DB::table('airwallex_sweeps')->where('owner_type', 'payments')->where('owner_id', 'account-payments')->where('family', 'payments_receipt')->first();
        self::assertNotNull($progress->last_completed_at);
        self::assertSame(5, $progress->attempted);
    }

    public function test_two_real_receivers_converge_on_one_account_scoped_receipt(): void
    {
        $body = $this->body('receiver-race');
        $fixture = $this->directory.'/body.json';
        file_put_contents($fixture, $body);
        $timestamp = (string) (int) floor(microtime(true) * 1000);
        $processes = [];
        try {
            foreach ([1, 2] as $index) {
                $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/Fixtures/payments-receipt-worker.php', $this->directory.'/db.sqlite', $this->directory.'/go', $fixture, $timestamp], dirname(__DIR__));
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
            self::assertSame(2, $ready);
            touch($this->directory.'/go');
            $ids = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
                $result = json_decode(trim(str_replace("READY\n", '', $process->getOutput())), true, flags: JSON_THROW_ON_ERROR);
                if ($result['outcome'] === 'accepted') {
                    $ids[] = $result['id'];
                }
            }
            self::assertNotEmpty($ids);
            self::assertCount(1, array_unique($ids));
            self::assertSame(1, Receipt::count());
            self::assertSame('account-payments', Receipt::first()->getAttribute('account_id'));
            // A fresh retry after concurrent storage pressure reuses the original row.
            $retried = $this->postDelivery($body);
            self::assertSame($ids[0], $retried->id);
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
        }
    }
}
