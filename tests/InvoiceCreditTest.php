<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\RecoveryManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\CreditNoteHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

class InvoiceCreditTest extends TestCase
{
    private Invoice $invoice;

    private CreditNoteHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $customer = app(OperationManager::class)->reserveCustomer(Team::create(['id' => 'credit-team']));
        $customer->update(['remote_id' => 'customer-test']);
        $this->http = new CreditNoteHttpClient;
        $this->app->bind(ClientInterface::class, fn () => $this->http);
        $this->app->forgetInstance(Client::class);
        $this->invoice = new Invoice('invoice-test', 'customer-test', 'sub-historical', app(BillingContext::class), $customer->id, null, $this->http->invoice);
    }

    public function test_partial_original_invoice_credit_has_three_durable_stages_and_keeps_a_paid_remainder(): void
    {
        $recipe = $this->recipes();
        $create = $recipe['create_credit']($this->invoice, 'free/create', 'BEFORE_PAYMENT', 'GOODWILL_GESTURE');
        $items = $recipe['add_credit_items']($this->invoice, 'free/items', 'free/create', [['invoice_line_item_id' => 'original-line', 'amount' => '15.00']]);
        $final = $recipe['finalize_credit']($this->invoice, 'free/final', 'free/create', '15.00');
        foreach ([$create, $items, $final] as $operation) {
            self::assertSame('succeeded', $operation->status);
            self::assertSame('credit-test', $operation->remote_id);
            self::assertNull($operation->lock_key);
        }
        self::assertSame('15.00', $this->http->invoice['amount_due']); // Synthetic provider state, not observed invoice effects.
        self::assertSame('original-line', $items->observation['lines']['credit-line-0']['invoice_line_item_id']);
        $posts = $this->posts();
        self::assertCount(3, $posts);
        self::assertSame('{}', (string) $posts[2]->getBody());
        self::assertStringContainsString('"amount":15.00', (string) $posts[1]->getBody());
        self::assertStringContainsString($items->id, (string) $posts[1]->getBody());
        $requests = count($this->http->requests);
        $this->create();
        $this->add();
        $this->invoice->finalizeCreditNote('free/final', 'free/create', '15.00');
        self::assertCount($requests, $this->http->requests);
        self::assertStringNotContainsString('original-line', json_encode($items, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('original-line', DB::table('airwallex_operations')->where('id', $items->id)->value('intent'));
        self::assertSame('FINALIZED', $this->invoice->creditNote('free/create')['status']);
    }

    public function test_consumer_selects_after_payment_refund_allocation_without_inventing_refund_success(): void
    {
        $this->http->invoice['payment_status'] = 'PAID';
        $this->http->invoice['amount_due'] = '0';
        $this->create('AFTER_PAYMENT');
        $this->add();
        $final = $this->invoice->finalizeCreditNote('free/final', 'free/create', '15.00', ['refund_amount' => '10.00', 'out_of_band_amount' => '5.00']);
        self::assertSame('succeeded', $final->status);
        self::assertSame('CREATED', $this->invoice->creditNote('free/create')['refund_status']);
        self::assertStringNotContainsString('request_id', (string) $this->posts()[2]->getBody());
        self::assertCount(3, $this->posts());
    }

    public function test_lost_create_response_requires_original_metadata_and_recovery_never_creates_a_replacement(): void
    {
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic lost response.');
        };
        try {
            $this->create();
            self::fail('A lost response proved creation.');
        } catch (AmbiguousOperationException) {
            $operation = Operation::firstOrFail();
            self::assertNull($operation->remote_id);
            self::assertNotNull($operation->lock_key);
        }
        $this->http->afterWrite = null;
        $metadata = $this->http->note['metadata'];
        $this->http->note['metadata'] = [];
        try {
            app(RecoveryManager::class)->operation($operation, 'credit-test');
            self::fail('Uncorrelated creation was adopted.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', $operation->fresh()->status);
        }
        $this->http->note['metadata'] = $metadata;
        self::assertSame('succeeded', app(RecoveryManager::class)->operation($operation, 'credit-test')->status);
        self::assertCount(1, $this->posts());
    }

    public function test_lost_line_response_recovers_by_direct_indexed_line_correlation_and_not_total(): void
    {
        $this->create();
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic lost response.');
        };
        try {
            $this->add();
            self::fail('A lost line response proved completion.');
        } catch (AmbiguousOperationException) {
            $operation = Operation::where('kind', 'credit_note.add_items')->firstOrFail();
        }
        $this->http->afterWrite = null;
        $metadata = $this->http->lines['credit-line-0']['metadata'];
        $this->http->lines['credit-line-0']['metadata'] = [];
        try {
            app(RecoveryManager::class)->operation($operation);
            self::fail('Matching amount without original identity was adopted.');
        } catch (AmbiguousOperationException) {
            self::assertNotNull($operation->fresh()->lock_key);
            self::assertNull($operation->fresh()->observation);
        }
        $this->http->lines['credit-line-0']['metadata'] = $metadata;
        self::assertSame('succeeded', app(RecoveryManager::class)->operation($operation)->status);
        self::assertCount(2, $this->posts());
    }

    public function test_uncertain_finalization_recovers_the_original_note_without_replaying_after_payment_state_changes(): void
    {
        $this->create();
        $this->add();
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic lost finalization response.');
        };
        try {
            $this->invoice->finalizeCreditNote('free/final', 'free/create', '15.00');
            self::fail('A lost finalization response proved completion.');
        } catch (AmbiguousOperationException) {
            $operation = Operation::where('kind', 'credit_note.finalize')->firstOrFail();
        }
        $this->http->afterWrite = null;
        $this->http->invoice['payment_status'] = 'PAID';
        self::assertSame('succeeded', app(RecoveryManager::class)->operation($operation)->status);
        self::assertCount(3, $this->posts());
    }

    public function test_external_or_changed_draft_lines_and_changed_action_keys_are_refused(): void
    {
        $this->create();
        $this->add();
        $this->http->lines['credit-line-0']['amount'] = '14.00';
        try {
            $this->invoice->finalizeCreditNote('free/final', 'free/create', '15.00');
            self::fail('Changed draft lines reached finalization.');
        } catch (EvidenceException) {
            self::assertCount(2, $this->posts());
        }
        $this->expectException(ConflictException::class);
        $this->invoice->createCreditNote('free/create', 'AFTER_PAYMENT', 'GOODWILL_GESTURE');
    }

    public function test_uncertain_stage_holds_the_invoice_financial_mutex_against_a_new_action(): void
    {
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic timeout.');
        };
        try {
            $this->create();
        } catch (AmbiguousOperationException) {
        }
        $this->http->afterWrite = null;
        try {
            $this->invoice->createCreditNote('replacement', 'BEFORE_PAYMENT', 'GOODWILL_GESTURE');
            self::fail('A new financial action bypassed an uncertain original.');
        } catch (ConflictException) {
            self::assertCount(1, $this->posts());
            self::assertSame(1, Operation::count());
        }
    }

    public function test_duplicate_noncanonical_line_indexes_cannot_prove_a_multi_line_action(): void
    {
        $this->create();
        $this->http->afterWrite = static function ($request, CreditNoteHttpClient $http): void {
            $http->lines['credit-line-1']['metadata']['_laravel_credit_index'] = '00';
        };
        try {
            $this->invoice->addCreditNoteItems('free/items', 'free/create', [
                ['invoice_line_item_id' => 'original-line', 'amount' => '15.00'],
                ['invoice_line_item_id' => 'original-line', 'amount' => '15.00'],
            ]);
            self::fail('Two indexes mapped to one original input.');
        } catch (AmbiguousOperationException) {
            $operation = Operation::where('kind', 'credit_note.add_items')->firstOrFail();
            self::assertSame('ambiguous', $operation->status);
            self::assertNull($operation->observation);
            self::assertNotNull($operation->lock_key);
        }
    }

    public function test_concurrent_original_creation_association_cannot_replace_the_first_committed_identity(): void
    {
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic lost response.');
        };
        try {
            $this->create();
        } catch (AmbiguousOperationException) {
        }
        $this->http->afterWrite = null;
        $operation = Operation::firstOrFail();
        $this->http->beforeRequest = static function ($request, CreditNoteHttpClient $http) use ($operation): void {
            if (str_ends_with($request->getUri()->getPath(), '/credit_notes/credit-test')) {
                // A competing read recovery wins the association while this worker is in HTTP.
                Operation::whereKey($operation->id)->update(['remote_id' => 'first-correlated-credit']);
                $http->beforeRequest = null;
            }
        };
        try {
            app(RecoveryManager::class)->operation($operation, 'credit-test');
            self::fail('A stale recovery replaced an already associated original.');
        } catch (AmbiguousOperationException) {
            self::assertSame('first-correlated-credit', $operation->fresh()->remote_id);
            self::assertSame('ambiguous', $operation->fresh()->status);
            self::assertNotNull($operation->fresh()->lock_key);
        }
    }

    public function test_expired_worker_fence_retains_unknown_id_and_the_original_hold(): void
    {
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic lost response.');
        };
        try {
            $this->create();
        } catch (AmbiguousOperationException) {
        }
        $this->http->afterWrite = null;
        $operation = Operation::firstOrFail();
        $fenced = false;
        $this->http->beforeRequest = static function ($request) use (&$fenced): void {
            if (str_ends_with($request->getUri()->getPath(), '/credit_notes/credit-test')) {
                $fenced = true;
            }
        };
        try {
            app(CommitGuard::class)->during(static function () use (&$fenced): void {
                if ($fenced) {
                    throw new RuntimeException('Synthetic expired worker lease.');
                }
            }, fn () => app(RecoveryManager::class)->operation($operation, 'credit-test'));
            self::fail('An expired worker released uncertainty.');
        } catch (AmbiguousOperationException) {
            self::assertNull($operation->fresh()->remote_id);
            self::assertNotNull($operation->fresh()->lock_key);
        }
    }

    public function test_offboarding_during_the_final_read_cannot_confirm_an_old_mapping(): void
    {
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic lost response.');
        };
        try {
            $this->create();
        } catch (AmbiguousOperationException) {
        }
        $this->http->afterWrite = null;
        $operation = Operation::firstOrFail();
        $this->http->beforeRequest = static function ($request, CreditNoteHttpClient $http) use ($operation): void {
            if (str_ends_with($request->getUri()->getPath(), '/credit_notes/credit-test')) {
                DB::table('airwallex_customers')->where('id', $operation->customer_id)->update(['remote_id' => 'another-customer']);
                $http->beforeRequest = null;
            }
        };
        try {
            app(RecoveryManager::class)->operation($operation, 'credit-test');
            self::fail('A changed owner mapping released the credit action.');
        } catch (AmbiguousOperationException) {
            self::assertNull($operation->fresh()->remote_id);
            self::assertNotNull($operation->fresh()->lock_key);
        }
    }

    public function test_offboarding_in_finalization_preflight_prevents_the_financial_write(): void
    {
        $root = $this->create();
        $this->add();
        $this->http->beforeRequest = static function ($request, CreditNoteHttpClient $http) use ($root): void {
            if (str_ends_with($request->getUri()->getPath(), '/line_items')) {
                DB::table('airwallex_customers')->where('id', $root->customer_id)->update(['remote_id' => 'another-customer']);
                $http->beforeRequest = null;
            }
        };
        try {
            $this->invoice->finalizeCreditNote('free/final', 'free/create', '15.00');
            self::fail('Finalization reached the old owner after offboarding.');
        } catch (OwnershipException) {
            self::assertCount(2, $this->posts());
        }
    }

    public function test_public_credit_observation_rechecks_mapping_after_its_last_read(): void
    {
        $root = $this->create();
        $this->http->beforeRequest = static function ($request, CreditNoteHttpClient $http) use ($root): void {
            if (str_ends_with($request->getUri()->getPath(), '/credit_notes/credit-test')) {
                DB::table('airwallex_customers')->where('id', $root->customer_id)->update(['remote_id' => 'another-customer']);
                $http->beforeRequest = null;
            }
        };
        $this->expectException(OwnershipException::class);
        $this->invoice->creditNote('free/create');
    }

    public function test_exact_credit_amount_is_preserved_without_currency_scale_rounding(): void
    {
        $this->create();
        $amount = '15.000000000000000001';
        $this->http->creditAmount = $amount;
        $items = $this->invoice->addCreditNoteItems('precise/items', 'free/create', [['invoice_line_item_id' => 'original-line', 'amount' => $amount]]);
        self::assertSame($amount, $items->observation['lines']['credit-line-0']['amount']);
        self::assertStringContainsString('"amount":'.$amount, (string) $this->posts()[1]->getBody());
        self::assertSame('succeeded', $this->invoice->finalizeCreditNote('precise/final', 'free/create', $amount)->status);
    }

    public function test_weak_callers_cannot_coerce_a_float_into_the_authorized_total(): void
    {
        $this->create();
        $this->add();
        $recipe = $this->recipes();
        try {
            array_map($recipe['finalize_credit'], [$this->invoice], ['free/final'], ['free/create'], [15.0]);
            self::fail('A weak caller coerced an inexact total.');
        } catch (EvidenceException) {
            self::assertCount(2, $this->posts());
        }
    }

    public function test_incomplete_line_pagination_cannot_publish_partial_proof(): void
    {
        $this->create();
        $this->http->afterWrite = static function (): void {
            throw new RuntimeException('Synthetic lost response.');
        };
        try {
            $this->add();
        } catch (AmbiguousOperationException) {
        }
        $this->http->afterWrite = null;
        $operation = Operation::where('kind', 'credit_note.add_items')->firstOrFail();
        $this->http->beforeRequest = static function ($request, CreditNoteHttpClient $http) {
            if (str_ends_with($request->getUri()->getPath(), '/line_items')) {
                return $request->getUri()->getQuery() === ''
                    ? CreditNoteHttpClient::json(['items' => array_values($http->lines), 'page_after' => 'second-page'])
                    : CreditNoteHttpClient::json(['code' => 'unavailable'], 503);
            }

            return null;
        };
        try {
            app(RecoveryManager::class)->operation($operation);
            self::fail('An incomplete line list proved the credit.');
        } catch (AmbiguousOperationException) {
            self::assertNull($operation->fresh()->observation);
            self::assertNotNull($operation->fresh()->lock_key);
            self::assertCount(2, $this->posts());
        }
    }

    public function test_prepared_restart_checks_a_changed_invoice_before_its_first_write(): void
    {
        $this->app->instance(OperationManager::class, new class(app(BillingContext::class)) extends OperationManager
        {
            public function execute(Operation $operation, \Closure $write, \Closure $confirm): mixed
            {
                throw new RuntimeException('Synthetic restart before first dispatch.');
            }
        });
        try {
            $this->create();
        } catch (RuntimeException) {
        }
        self::assertSame('prepared', Operation::firstOrFail()->status);
        $this->app->instance(OperationManager::class, new OperationManager(app(BillingContext::class)));
        $this->http->invoice['amount_due'] = '29.00';
        try {
            $this->create();
            self::fail('A prepared action bypassed a changed financial baseline.');
        } catch (EvidenceException) {
            self::assertSame('failed', Operation::firstOrFail()->status);
            self::assertCount(0, $this->posts());
        }
    }

    #[DataProvider('processCommands')]
    public function test_real_processes_retain_one_original_invoice_write(array $commands): void
    {
        $directory = sys_get_temp_dir().'/airwallex-credit-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        $barrier = $directory.'/go';
        config(['database.connections.testing' => TestDatabase::connection($database)]);
        DB::purge('testing');
        $processes = [];
        try {
            if (getenv('AIRWALLEX_TEST_DATABASE') === 'mysql') {
                Schema::dropAllTables();
            }
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
            app(OperationManager::class)->reserveCustomer(Team::create(['id' => 'credit-race']))->update(['remote_id' => 'customer-test']);
            foreach ($commands as $command) {
                $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/credit-worker.php', $database, $barrier, $command], dirname(__DIR__));
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = count(array_filter($processes, static fn (Process $process) => str_contains($process->getOutput(), 'READY')));
                if ($ready === count($commands)) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            self::assertSame(count($commands), $ready, implode('\n', array_map(static fn (Process $process) => $process->getErrorOutput().$process->getOutput(), $processes)));
            touch($barrier);
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertStringContainsString('RETAINED', $process->getOutput());
            }
            self::assertSame(["WRITE\n"], file($barrier.'.writes'));
            self::assertSame(1, Operation::count());
            self::assertSame('ambiguous', Operation::firstOrFail()->status);
            self::assertNull(Operation::firstOrFail()->remote_id);
            self::assertNotNull(Operation::firstOrFail()->lock_key);
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

    public static function processCommands(): iterable
    {
        yield 'single-worker control' => [['credit-a']];
        yield 'same command' => [['credit-a', 'credit-a']];
        yield 'competing command' => [['credit-a', 'credit-b']];
    }

    #[DataProvider('unsupportedItems')]
    public function test_unsupported_or_imprecise_credit_forms_never_reach_a_line_write(array $item): void
    {
        $this->create();
        try {
            $this->invoice->addCreditNoteItems('free/items', 'free/create', [$item]);
            self::fail('Unsupported credit form reached the provider.');
        } catch (ConflictException|EvidenceException) {
            self::assertCount(1, $this->posts());
        }
    }

    public static function unsupportedItems(): iterable
    {
        yield 'unassigned' => [['amount' => '15.00']];
        yield 'float' => [['invoice_line_item_id' => 'original-line', 'amount' => 15.0]];
        yield 'zero' => [['invoice_line_item_id' => 'original-line', 'amount' => '0.00']];
        yield 'quantity' => [['invoice_line_item_id' => 'original-line', 'quantity' => 1]];
        yield 'tax included' => [['invoice_line_item_id' => 'original-line', 'amount' => '15.00', 'tax_included' => true]];
        yield 'reserved metadata' => [['invoice_line_item_id' => 'original-line', 'amount' => '15.00', 'metadata' => ['_laravel_credit_index' => 'fake']]];
    }

    private function create(string $type = 'BEFORE_PAYMENT'): Operation
    {
        return $this->invoice->createCreditNote('free/create', $type, 'GOODWILL_GESTURE');
    }

    private function recipes(): array
    {
        return (require dirname((new \ReflectionClass(Invoice::class))->getFileName(), 2).'/examples/free-period.php')();
    }

    private function add(): Operation
    {
        return $this->invoice->addCreditNoteItems('free/items', 'free/create', [['invoice_line_item_id' => 'original-line', 'amount' => '15.00']]);
    }

    private function posts(): array
    {
        return array_values(array_filter($this->http->requests, static fn ($request) => $request->getMethod() === 'POST' && ! str_ends_with($request->getUri()->getPath(), '/login')));
    }
}
