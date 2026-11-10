<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Symfony\Component\Process\Process;

class ConcurrencyTest extends TestCase
{
    public function test_separate_processes_share_one_customer_and_name_reservation(): void
    {
        $directory = sys_get_temp_dir().'/airwallex-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        $barrier = $directory.'/go';
        config(['database.connections.testing.database' => $database, 'database.connections.testing.busy_timeout' => 10000]);
        DB::purge('testing');
        $processes = [];
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            Schema::create('local_teams', function (Blueprint $table): void {
                $table->string('id')->primary();
            });
            Team::create(['id' => 'race']);
            foreach (['command-a', 'command-b'] as $command) {
                $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/Fixtures/race-worker.php', $database, $barrier, $command], dirname(__DIR__));
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
            self::assertSame(2, $ready, implode('\n', array_map(fn (Process $process) => $process->getErrorOutput().$process->getOutput(), $processes)));
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim(str_replace("READY\n", '', $process->getOutput())), true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertSame($results[0]['customer'], $results[1]['customer']);
            self::assertSame(1, Customer::count());
            self::assertSame(1, Operation::count());
            self::assertSame(1, SubscriptionSlot::count());
            self::assertSame(1, count(array_filter($results, fn (array $result) => isset($result['operation']))));
            self::assertSame(1, count(array_filter($results, fn (array $result) => $result['conflict'] ?? false)));
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
