<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Process\Process;

class ConcurrencyTest extends TestCase
{
    public function test_separate_processes_share_one_customer_and_name_reservation(): void
    {
        $this->race(false);
    }

    public function test_import_and_creation_compete_for_the_same_name_in_separate_processes(): void
    {
        $this->race(true);
    }

    public function test_committed_import_prevents_a_later_process_from_creating_under_the_same_name(): void
    {
        $this->race(true, true);
    }

    public function test_duplicate_commands_converge_on_the_same_financial_intent(): void
    {
        $this->race(false, sameCommand: true);
    }

    public function test_two_processes_cannot_execute_the_same_prepared_operation_twice(): void
    {
        $this->race(false, sameCommand: true, execute: true);
    }

    public function test_competing_claims_retain_one_ambiguous_write_when_confirmation_fails(): void
    {
        $this->race(false, sameCommand: true, execute: true, failConfirmation: true);
    }

    public function test_stale_process_cannot_restore_old_access_or_displace_a_replacement_generation(): void
    {
        $directory = sys_get_temp_dir().'/airwallex-stale-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        config(['database.connections.testing' => TestDatabase::connection($database)]);
        DB::purge('testing');
        $process = null;
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
            $customer = app(OperationManager::class)->reserveCustomer(Team::create(['id' => 'race']));
            $customer->update(['remote_id' => 'customer-test']);
            $remote = Billing::subscription();
            $subscription = Subscription::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'customer_id' => $customer->id, 'remote_id' => 'sub-test', 'name' => 'default', 'status' => 'ACTIVE', 'starts_at' => $remote['starts_at'], 'provider_created_at' => $remote['created_at'], 'provider_updated_at' => $remote['updated_at'], 'cancel_at_period_end' => false, 'snapshot' => $remote]);
            SubscriptionSlot::create(['customer_id' => $customer->id, 'name' => 'default', 'subscription_id' => $subscription->id]);
            $barrier = $directory.'/go';
            $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/race-worker.php', $database, $barrier, 'unused', 'stale'], dirname(__DIR__));
            $process->setTimeout(20);
            $process->start();
            $deadline = microtime(true) + 10;
            while (! str_contains($process->getOutput(), 'READY') && microtime(true) < $deadline) {
                usleep(1000);
            }
            self::assertStringContainsString('READY', $process->getOutput(), $process->getErrorOutput());
            $cancelled = [...$remote, 'status' => 'CANCELLED', 'updated_at' => '2026-11-10T13:00:00Z'];
            $http = new FakeHttpClient([FakeHttpClient::login(), FakeHttpClient::json($cancelled), Billing::page([]), FakeHttpClient::json($cancelled)]);
            $this->app->instance(ClientInterface::class, $http);
            $this->app->forgetInstance(Client::class);
            $subscription->reconcile();
            $replacement = Subscription::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'customer_id' => $customer->id, 'remote_id' => 'sub-new', 'name' => 'default', 'status' => 'ACTIVE', 'snapshot' => []]);
            SubscriptionSlot::create(['customer_id' => $customer->id, 'name' => 'default', 'subscription_id' => $replacement->id]);
            touch($barrier);
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode(str_replace("READY\n", '', $process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('CANCELLED', $result['status']);
            self::assertSame('CANCELLED', $subscription->fresh()->status);
            self::assertSame($replacement->id, SubscriptionSlot::sole()->subscription_id);
        } finally {
            $process?->stop();
            DB::disconnect('testing');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function race(bool $import, bool $delayCreate = false, bool $sameCommand = false, bool $execute = false, bool $failConfirmation = false): void
    {
        $directory = sys_get_temp_dir().'/airwallex-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        touch($database);
        $barrier = $directory.'/go';
        config(['database.connections.testing' => TestDatabase::connection($database)]);
        DB::purge('testing');
        $processes = [];
        $originalOperation = null;
        $originalSlot = null;
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            Schema::create('local_teams', function (Blueprint $table): void {
                $table->string('id')->primary();
            });
            $team = Team::create(['id' => 'race']);
            if ($import) {
                app(OperationManager::class)->reserveCustomer($team)->update(['remote_id' => 'customer-test']);
            }
            if ($execute) {
                $customer = app(OperationManager::class)->reserveCustomer($team);
                $originalOperation = app(OperationManager::class)->prepare($customer, 'command-a', 'create', ['price' => 'price'], [], name: 'default');
                $originalSlot = SubscriptionSlot::sole();
            }
            foreach (['command-a', $sameCommand ? 'command-a' : 'command-b'] as $index => $command) {
                $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__.'/Fixtures/race-worker.php', $database, $barrier, $command, $execute ? ($failConfirmation ? 'execute-unconfirmed' : 'execute') : (($import && $index === 1) ? 'import' : ($delayCreate ? 'delayed-create' : 'create'))], dirname(__DIR__));
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
            if ($execute) {
                $deadline = microtime(true) + 5;
                do {
                    $prepared = count(array_filter($processes, fn (Process $process) => str_contains($process->getOutput(), 'PREPARED')));
                    if ($prepared === 2) {
                        break;
                    }
                    usleep(1000);
                } while (microtime(true) < $deadline);
                self::assertSame(2, $prepared, implode('\n', array_map(fn (Process $process) => $process->getErrorOutput().$process->getOutput(), $processes)));
                touch($barrier.'.execute');
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim(str_replace(["READY\n", "PREPARED\n"], '', $process->getOutput())), true, flags: JSON_THROW_ON_ERROR);
            }
            $operation = Operation::first();
            $attempts = is_file($barrier.'.attempts') ? (file($barrier.'.attempts', FILE_IGNORE_NEW_LINES) ?: []) : [];
            $writes = is_file($barrier.'.writes') ? (file($barrier.'.writes', FILE_IGNORE_NEW_LINES) ?: []) : [];
            $diagnostics = json_encode(['results' => $results, 'status' => $operation?->status,
                'remote_id' => $operation?->remote_id, 'lock_held' => $operation?->lock_key !== null,
                'attempts' => count($attempts), 'writes' => count($writes)], JSON_THROW_ON_ERROR);
            self::assertSame($results[0]['customer'], $results[1]['customer']);
            self::assertSame(1, Customer::count());
            self::assertSame(1, Operation::count() + Subscription::count());
            self::assertSame(1, SubscriptionSlot::count());
            $successes = array_filter($results, fn (array $result) => isset($result['operation']) || isset($result['subscription']));
            if (! $execute) {
                self::assertGreaterThanOrEqual(1, count($successes), $diagnostics);
            }
            if ($sameCommand && ! $execute) {
                $customer = Customer::first();
                $recovered = app(OperationManager::class)->prepare($customer, 'command-a', 'create', ['price' => 'price'], [], name: 'default');
                self::assertSame(Operation::first()->id, $recovered->id);
                self::assertCount(1, array_unique(array_column($successes, 'operation')));
            } elseif (! $execute) {
                self::assertCount(1, $successes);
                self::assertSame(1, count(array_filter($results, fn (array $result) => $result['conflict'] ?? false)));
            }
            if ($execute) {
                self::assertSame(['ATTEMPT', 'ATTEMPT'], $attempts, $diagnostics);
                self::assertSame(['WRITE'], $writes, $diagnostics);
                self::assertNotNull($originalOperation);
                self::assertNotNull($originalSlot);
                self::assertNotNull($operation);
                $identity = ['id', 'environment', 'organization_id', 'customer_id', 'subscription_id', 'kind', 'action_key', 'request_id', 'intent_hash', 'intent'];
                $slotIdentity = ['id', 'customer_id', 'name', 'operation_id', 'subscription_id'];
                self::assertSame($originalOperation->only($identity), $operation->only($identity), $diagnostics);
                self::assertSame($originalSlot->only($slotIdentity), SubscriptionSlot::sole()->only($slotIdentity), $diagnostics);
                self::assertLessThanOrEqual(1, count($successes), $diagnostics);
                self::assertContains($operation->status, ['succeeded', 'ambiguous'], $diagnostics);
                if ($failConfirmation) {
                    self::assertSame('ambiguous', $operation->status, $diagnostics);
                }
                if ($operation->status === 'succeeded') {
                    self::assertCount(1, $successes, $diagnostics);
                    self::assertSame('sub-synthetic', $operation->remote_id);
                    self::assertNull($operation->lock_key);
                } else {
                    self::assertCount(0, $successes, $diagnostics);
                    self::assertSame($originalOperation->lock_key, $operation->lock_key, $diagnostics);
                    self::assertContains($operation->remote_id, [null, 'sub-synthetic'], $diagnostics);
                    try {
                        app(OperationManager::class)->execute($operation, function () use ($barrier): array {
                            file_put_contents($barrier.'.writes', "REPLAY\n", FILE_APPEND | LOCK_EX);

                            return ['id' => 'replacement-forbidden'];
                        }, fn (array $response) => $response['id']);
                        self::fail('An ambiguous original command must not execute again.');
                    } catch (AmbiguousOperationException $exception) {
                        self::assertSame($originalOperation->id, $exception->operationId);
                    }
                    self::assertSame(['WRITE'], file($barrier.'.writes', FILE_IGNORE_NEW_LINES));
                    self::assertSame('ambiguous', $operation->fresh()->status);
                    self::assertSame($originalOperation->lock_key, $operation->fresh()->lock_key);
                    self::assertSame($originalSlot->only($slotIdentity), SubscriptionSlot::sole()->only($slotIdentity));
                    self::assertSame($originalOperation->only($identity), $operation->fresh()->only($identity));
                }
            }
            if ($delayCreate) {
                self::assertArrayHasKey('subscription', $results[1]);
            }
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
