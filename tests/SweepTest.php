<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\SweepManager;
use Jojeringnax\AirwallexLaravel\Services\SweepRegistry;
use Jojeringnax\AirwallexLaravel\Support\SweepFamily;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

class SweepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11T09:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('sweep_test_rows', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('owner');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function family(array &$seen, string $owner = 'org-test'): SweepFamily
    {
        return new SweepFamily('test', 'sandbox', 'billing', $owner,
            fn () => SweepRow::query()->where('owner', $owner),
            function (Model $row) use (&$seen): bool {
                $seen[] = $row->getKey();
                if ($row->getKey() === '01') {
                    throw new OwnershipException('Synthetic private provider diagnostic');
                }

                return true;
            });
    }

    public function test_progress_wraps_and_failed_first_row_cannot_starve_later_rows_after_restart(): void
    {
        foreach (['01', '02', '03', '04', '05'] as $id) {
            SweepRow::create(['id' => $id, 'owner' => 'org-test']);
        }
        SweepRow::create(['id' => 'foreign', 'owner' => 'other-org']);
        $seen = [];
        $family = $this->family($seen);
        $first = (new SweepManager)->run($family, 2);
        self::assertSame(['01', '02'], $seen);
        self::assertSame('ownership', $first['last_error']);
        self::assertSame('05', $first['upper_id']);
        // Progress survives a new service/worker. Later inserts wait for the next sweep.
        SweepRow::create(['id' => '00', 'owner' => 'org-test']);
        SweepRow::create(['id' => '06', 'owner' => 'org-test']);
        SweepRow::findOrFail('03')->delete();
        $second = (new SweepManager)->run($family, 2);
        self::assertSame(['01', '02', '04', '05'], $seen);
        self::assertSame('paused', $second['outcome']);
        $completed = (new SweepManager)->run($family, 2);
        self::assertSame('sweep_completed', $completed['outcome']);
        self::assertSame(1, $completed['completed_sweeps']);
        self::assertNotNull($completed['last_completed_at']);
        self::assertSame(4, $completed['sweep_attempted']);
        self::assertSame(1, $completed['sweep_failures']);
        $next = (new SweepManager)->run($family, 2);
        self::assertSame(['01', '02', '04', '05', '00', '01'], $seen);
        self::assertSame('06', $next['upper_id']);
        self::assertSame('ownership', $next['last_error']);
        $foreignSeen = [];
        $foreign = (new SweepManager)->run($this->family($foreignSeen, 'other-org'), 2);
        self::assertSame(['foreign'], $foreignSeen);
        self::assertNotSame($next['progress_id'], $foreign['progress_id']);
    }

    public function test_checkpoint_precedes_reporting_failure_and_empty_sweeps_complete(): void
    {
        SweepRow::create(['id' => '02', 'owner' => 'org-test']);
        SweepRow::create(['id' => '03', 'owner' => 'org-test']);
        $seen = [];
        $family = $this->family($seen);
        try {
            (new SweepManager)->run($family, 2, report: fn () => throw new RuntimeException('Synthetic output interruption'));
            self::fail('Reporting interruption did not propagate.');
        } catch (RuntimeException) {
            self::assertSame('02', DB::table('airwallex_sweeps')->value('cursor'));
        }
        (new SweepManager)->run($family, 2);
        self::assertSame(['02', '03'], $seen);
        SweepRow::query()->delete();
        $empty = (new SweepManager)->run($family, 2);
        self::assertSame('sweep_completed', $empty['outcome']);
        self::assertSame(0, $empty['attempted']);
    }

    public function test_overlapping_worker_is_busy_and_expired_worker_cannot_commit_progress(): void
    {
        SweepRow::create(['id' => '02', 'owner' => 'org-test']);
        $seen = [];
        $normal = $this->family($seen);
        $overlap = null;
        $family = new SweepFamily('test', 'sandbox', 'billing', 'org-test', $normal->query,
            function () use ($normal, &$overlap): bool {
                $overlap = (new SweepManager)->run($normal, 1);
                DB::table('airwallex_sweeps')->where('id', $normal->key())->update(['lease_token' => 'replacement-worker', 'lease_until' => now()->addMinute()]);

                return true;
            });
        try {
            (new SweepManager)->run($family, 1);
            self::fail('Stale worker advanced its cursor.');
        } catch (ConflictException) {
            self::assertSame('busy', $overlap['outcome']);
            self::assertNull(DB::table('airwallex_sweeps')->value('cursor'));
            self::assertSame('replacement-worker', DB::table('airwallex_sweeps')->value('lease_token'));
        }
        Carbon::setTestNow(now()->addMinutes(2));
        self::assertSame(1, (new SweepManager)->run($normal, 1)['attempted']);
        self::assertSame(['02'], $seen);
    }

    public function test_time_budget_stops_scheduling_more_work_and_can_resume(): void
    {
        foreach (['02', '03'] as $id) {
            SweepRow::create(['id' => $id, 'owner' => 'org-test']);
        }
        $seen = [];
        $normal = $this->family($seen);
        $family = new SweepFamily('test', 'sandbox', 'billing', 'org-test', $normal->query,
            function (Model $row) use (&$seen): bool {
                $seen[] = $row->getKey();
                usleep(1_050_000);

                return true;
            });
        self::assertSame(1, (new SweepManager)->run($family, 10, 1)['attempted']);
        (new SweepManager)->run($normal, 10, 1);
        self::assertSame(['02', '03'], $seen);
    }

    public function test_default_families_skip_unattempted_intents_and_never_replay_uncertain_writes(): void
    {
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $team = Team::create(['id' => 'sweep-team']);
        $customer = app(OperationManager::class)->reserveCustomer($team);
        $prepared = app(OperationManager::class)->prepare($customer, 'prepared', 'customer.create', [], []);
        // Separate target mutex for an old attempted operation with no remotely known ID.
        $ambiguous = Operation::create([...app(BillingContext::class)->attributes(), 'customer_id' => $customer->id,
            'kind' => 'customer.create', 'action_key' => 'ambiguous', 'intent_hash' => hash('sha256', 'synthetic'),
            'intent' => [], 'request_id' => '424e5900-2027-4d96-9b2b-5e8bb73f94ab', 'status' => 'ambiguous']);
        $http = new FakeHttpClient;
        $this->app->instance(ClientInterface::class, $http);
        self::assertSame(['customer', 'subscription', 'checkout', 'operation', 'receipt', 'event'], array_map(fn (SweepFamily $family) => $family->name, app(SweepRegistry::class)->families()));
        self::assertSame(1, Artisan::call('airwallex:sweep', ['--limit' => '2']));
        self::assertSame([], $http->requests);
        self::assertSame('prepared', $prepared->fresh()->status);
        self::assertSame('ambiguous', $ambiguous->fresh()->status);
        self::assertStringNotContainsString($prepared->id, Artisan::output());
        self::assertStringNotContainsString($ambiguous->request_id, Artisan::output());
        self::assertSame(6, DB::table('airwallex_sweeps')->count());
        self::assertSame(1, Artisan::call('airwallex:sweep', ['--limit' => '0']));
        self::assertSame(1, Artisan::call('airwallex:sweep', ['--seconds' => '0']));
    }

    public function test_two_real_scheduler_processes_coordinate_one_family_lease(): void
    {
        $directory = sys_get_temp_dir().'/airwallex-sweep-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        config(['database.connections.testing' => TestDatabase::connection($database)]);
        DB::purge('testing');
        $processes = [];
        try {
            Schema::dropAllTables();
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            Schema::create('sweep_test_rows', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('owner');
            });
            SweepRow::create(['id' => '02', 'owner' => 'org-test']);
            foreach ([1, 2] as $index) {
                $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/sweep-worker.php', $database, $directory.'/go'], dirname(__DIR__));
                $process->setTimeout(10);
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
            touch($directory.'/go');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $result = json_decode(trim(str_replace("READY\n", '', $process->getOutput())), true, flags: JSON_THROW_ON_ERROR);
                $outcomes[] = $result['outcome'];
            }
            sort($outcomes);
            self::assertSame(['busy', 'paused'], $outcomes);
            self::assertSame(['02'], file($directory.'/go.attempts', FILE_IGNORE_NEW_LINES));
            self::assertSame('02', DB::table('airwallex_sweeps')->value('cursor'));
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
}

class SweepRow extends Model
{
    protected $table = 'sweep_test_rows';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
