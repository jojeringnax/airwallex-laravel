<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionTransition;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Process\Process;

class SubscriptionTransitionConcurrencyTest extends TestCase
{
    #[DataProvider('competitors')]
    public function test_separate_execution_and_editing_processes_preserve_one_original_command(string $competitor): void
    {
        $directory = sys_get_temp_dir().'/airwallex-transition-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        config(['database.connections.testing' => TestDatabase::connection($database)]);
        DB::purge('testing');
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $processes = [];
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
            $team = Team::create(['id' => 'race']);
            app(OperationManager::class)->reserveCustomer($team)->update(['remote_id' => 'customer-test']);
            $remote = Billing::subscription(['current_period_starts_at' => '2026-11-01T00:00:00Z', 'current_period_ends_at' => '2026-12-01T00:00:00Z']);
            $observation = [FakeHttpClient::json($remote), Billing::page([Billing::item(['quantity' => '1'])]), FakeHttpClient::json($remote)];
            $this->app->instance(ClientInterface::class, new FakeHttpClient([FakeHttpClient::login(), ...$observation, ...$observation]));
            $this->app->forgetInstance(Client::class);
            $subscription = $team->importSubscription('membership', 'sub-test');
            $plan = $subscription->scheduleTransition('original-plan', '2026-12-01T00:00:00Z', [['id' => 'item-test', 'quantity' => 2]], ['default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'], '2026-12-01T00:10:00Z');
            $barrier = $directory.'/go';
            foreach ($competitor === 'none' ? ['run'] : ['run', $competitor] as $mode) {
                $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/Fixtures/transition-worker.php', $database, $barrier, $plan->id, $mode], dirname(__DIR__));
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = count(array_filter($processes, fn (Process $process) => str_contains($process->getOutput(), 'READY')));
                if ($ready === count($processes)) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            self::assertSame(count($processes), $ready);
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(str_replace("READY\n", '', $process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('outcome', $results[array_key_last($results)]);
            }
            $writes = is_file($barrier.'.writes') ? file($barrier.'.writes', FILE_IGNORE_NEW_LINES) : [];
            self::assertLessThanOrEqual(1, count($writes));
            if ($competitor === 'none') {
                self::assertCount(1, $writes, json_encode($results, JSON_THROW_ON_ERROR));
            }
            if ($writes === []) {
                self::assertNotSame('completed', $plan->fresh()->status);
                self::assertSame(0, Operation::whereIn('status', ['in_flight', 'ambiguous', 'acknowledged', 'succeeded'])->count());
            }
            self::assertLessThanOrEqual(1, Operation::count());
            self::assertLessThanOrEqual(1, SubscriptionTransition::whereNotNull('active_key')->count());
            self::assertSame($subscription->id, SubscriptionSlot::sole()->subscription_id);
            $operation = Operation::first();
            if ($operation !== null) {
                self::assertSame('transition/'.$plan->id, $operation->action_key);
                self::assertSame($subscription->id, $operation->subscription_id);
                self::assertSame($subscription->remote_id, $operation->intent['remote_id']);
            }
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            Carbon::setTestNow();
            DB::disconnect('testing');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function competitors(): array
    {
        return [['none'], ['run'], ['cancel'], ['replace']];
    }
}
