<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\AirwallexServiceProvider;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Facades\Airwallex;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;

class FoundationTest extends TestCase
{
    public function test_real_package_registers_lazily_and_publishes_configuration_and_migrations(): void
    {
        $http = $this->http([]);
        $this->assertTrue($this->app->providerIsLoaded(AirwallexServiceProvider::class));
        $this->assertSame('sandbox', config('airwallex.environment'));
        $this->assertInstanceOf(Client::class, $this->app->make(Client::class));
        $this->assertSame($this->app->make(Client::class), Airwallex::getFacadeRoot());
        $this->assertSame([], $http->requests);
        $this->assertContains(realpath(__DIR__.'/../config/airwallex.php'), array_map('realpath', array_keys(ServiceProvider::pathsToPublish(AirwallexServiceProvider::class, 'airwallex-config'))));
        $this->assertNotEmpty(ServiceProvider::pathsToPublish(AirwallexServiceProvider::class, 'airwallex-migrations'));
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([AirwallexServiceProvider::class], $composer['extra']['laravel']['providers']);
        $this->assertSame('^0.1.3', $composer['require']['jojeringnax/airwallex-php-sdk']);
    }

    public function test_context_and_package_boot_do_not_require_credentials(): void
    {
        config(['airwallex.client_id' => null, 'airwallex.api_key' => null]);
        $http = $this->http([]);
        $context = $this->app->make(BillingContext::class);
        $this->assertSame(['environment' => 'sandbox', 'organization_id' => 'org-test'], $context->attributes());
        $this->assertSame([], $http->requests);
        $this->expectException(ConfigurationException::class);
        $this->app->make(Client::class);
    }

    public function test_default_environment_is_sandbox_without_environment_variables(): void
    {
        $original = getenv('AIRWALLEX_ENVIRONMENT');
        putenv('AIRWALLEX_ENVIRONMENT');
        unset($_ENV['AIRWALLEX_ENVIRONMENT'], $_SERVER['AIRWALLEX_ENVIRONMENT']);
        try {
            $configuration = require __DIR__.'/../config/airwallex.php';
            $this->assertSame('sandbox', $configuration['environment']);
        } finally {
            if ($original !== false) {
                putenv('AIRWALLEX_ENVIRONMENT='.$original);
            }
        }
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_context_fails_without_http(string $environment, string $organization): void
    {
        $http = $this->http([]);
        config(['airwallex.environment' => $environment, 'airwallex.organization_id' => $organization]);
        try {
            $this->app->make(Client::class);
            $this->fail('Invalid context was accepted.');
        } catch (ConfigurationException) {
            $this->assertSame([], $http->requests);
        }
    }

    public static function invalidContexts(): array
    {
        return [['live', 'org-test'], ['', 'org-test'], ['sandbox', ''], ['sandbox', "org\nother"]];
    }

    public function test_context_rejects_foreign_records_and_retains_opaque_identity(): void
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['environment' => 'sandbox', 'organization_id' => 'org-test']);
        $context = $this->app->make(BillingContext::class);
        $context->assertModel($model);
        $this->assertSame('org-test', $context->organizationId);
        $model->setAttribute('organization_id', 'ORG-test');
        $this->expectException(OwnershipException::class);
        $context->assertModel($model);
    }

    public function test_sdk_performs_login_reuse_and_cache_reuse_across_worker_scopes(): void
    {
        $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + 1800);
        $writes = [];
        $this->app['events']->listen(KeyWritten::class, function (KeyWritten $event) use (&$writes): void {
            $writes[] = $event;
        });
        $http = $this->http([
            FakeHttpClient::login($expiry),
            FakeHttpClient::json(['id' => 'customer-one']),
            FakeHttpClient::json(['id' => 'customer-one']),
            FakeHttpClient::json(['id' => 'customer-one']),
        ]);
        $client = $this->app->make(Client::class);
        $client->billing()->customers()->retrieve('customer-one');
        $client->billing()->customers()->retrieve('customer-one');
        $this->app->forgetScopedInstances();
        $replacement = $this->app->make(Client::class);
        $this->assertNotSame($client, $replacement);
        $replacement->billing()->customers()->retrieve('customer-one');
        $this->assertCount(4, $http->requests);
        $this->assertSame('/api/v1/authentication/login', $http->requests[0]->getUri()->getPath());
        $this->assertSame('2026-08-21', $http->requests[1]->getHeaderLine('x-api-version'));
        $this->assertSame('Bearer token-synthetic', $http->requests[3]->getHeaderLine('Authorization'));
        $this->assertSame('', $http->requests[0]->getHeaderLine('x-login-as'));
        $this->assertSame('', $http->requests[1]->getHeaderLine('x-on-behalf-of'));
        $this->assertCount(1, $writes);
        $this->assertSame($expiry, $writes[0]->value['expires_at']);
        $this->assertEqualsWithDelta(strtotime($expiry) - time(), $writes[0]->seconds, 2);
    }

    public function test_configured_file_cache_shares_sdk_tokens_across_worker_scopes(): void
    {
        $directory = sys_get_temp_dir().'/airwallex-cache-test-'.bin2hex(random_bytes(8));
        config([
            'cache.stores.airwallex-test' => ['driver' => 'file', 'path' => $directory],
            'airwallex.cache_store' => 'airwallex-test',
        ]);
        $http = $this->http([
            FakeHttpClient::login(),
            FakeHttpClient::json(['id' => 'customer-one']),
            FakeHttpClient::json(['id' => 'customer-one']),
        ]);
        try {
            $this->app->make(Client::class)->billing()->customers()->retrieve('customer-one');
            $this->app->forgetScopedInstances();
            $this->app->make(Client::class)->billing()->customers()->retrieve('customer-one');
            $this->assertCount(3, $http->requests);
        } finally {
            $this->app['files']->deleteDirectory($directory);
        }
    }

    public function test_credential_rotation_and_environment_changes_do_not_share_sdk_tokens(): void
    {
        $http = $this->http([
            FakeHttpClient::login(token: 'first'), FakeHttpClient::json(['id' => 'customer-one']),
            FakeHttpClient::login(token: 'rotated'), FakeHttpClient::json(['id' => 'customer-one']),
            FakeHttpClient::login(token: 'production'), FakeHttpClient::json(['id' => 'customer-one']),
        ]);
        $firstContext = $this->app->make(BillingContext::class);
        $this->app->make(Client::class)->billing()->customers()->retrieve('customer-one');
        config(['airwallex.api_key' => 'key-rotated']);
        $this->app->forgetScopedInstances();
        $this->assertSame($firstContext->attributes(), $this->app->make(BillingContext::class)->attributes());
        $this->app->make(Client::class)->billing()->customers()->retrieve('customer-one');
        config(['airwallex.environment' => 'production']);
        $this->app->forgetScopedInstances();
        $this->app->make(Client::class)->billing()->customers()->retrieve('customer-one');
        $this->assertCount(6, $http->requests);
        $this->assertSame('key-rotated', $http->requests[2]->getHeaderLine('x-api-key'));
        $this->assertSame('api.sandbox.airwallex.com', $http->requests[3]->getUri()->getHost());
        $this->assertSame('api.airwallex.com', $http->requests[5]->getUri()->getHost());
        $this->assertSame('Bearer production', $http->requests[5]->getHeaderLine('Authorization'));
    }

    public function test_configuration_can_be_cached_without_serializing_clients_or_closures(): void
    {
        $path = $this->app->getCachedConfigPath();
        $configPath = config_path('airwallex.php');
        $previous = is_file($configPath) ? file_get_contents($configPath) : null;
        try {
            copy(__DIR__.'/../config/airwallex.php', $configPath);
            $this->artisan('config:cache')->assertSuccessful();
            $cached = require $path;
            $this->assertIsArray($cached['airwallex']);
            $this->assertArrayHasKey('models', $cached['airwallex']);
            $this->assertSame('2026-08-21', $cached['airwallex']['webhook']['version']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if ($previous === null) {
                unlink($configPath);
            } else {
                file_put_contents($configPath, $previous);
            }
        }
    }

    private function http(array $responses): FakeHttpClient
    {
        $http = new FakeHttpClient($responses);
        $this->app->bind(ClientInterface::class, fn () => $http);

        return $http;
    }
}
