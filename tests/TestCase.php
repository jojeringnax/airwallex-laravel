<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\AirwallexServiceProvider;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Client\ClientInterface;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AIRWALLEX_TEST_DATABASE') === 'mysql') {
            Schema::dropAllTables();
        }
        // A new worker scope must also remain unable to reach the network.
        $this->app->bind(ClientInterface::class, fn () => new FakeHttpClient);
    }

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [AirwallexServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', TestDatabase::connection());
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('airwallex.environment', 'sandbox');
        $app['config']->set('airwallex.organization_id', 'org-test');
        $app['config']->set('airwallex.client_id', 'client-synthetic');
        $app['config']->set('airwallex.api_key', 'key-synthetic');
        $app['config']->set('airwallex.legal_entity_id', 'entity-test');
        $app['config']->set('airwallex.linked_payment_account_id', 'collector-test');
        $app['config']->set('airwallex.currency', 'USD');
    }
}
