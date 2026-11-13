<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\AirwallexLaravel\Contracts\SubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class AirwallexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/airwallex.php', 'airwallex');
        $this->app->register(Payments\PaymentsServiceProvider::class);
        $this->app->scoped(CommitGuard::class);
        $this->app->scoped(Services\SweepRegistry::class);
        $this->app->scoped(BillingContext::class, fn (Application $app) => new BillingContext(
            $this->stringConfig($app, 'environment'),
            $this->stringConfig($app, 'organization_id'),
        ));
        if (! $this->app->bound(ClientInterface::class)) {
            $this->app->scoped(ClientInterface::class, function (Application $app): ClientInterface {
                $timeout = $app['config']->get('airwallex.http_timeout');
                $connectTimeout = $app['config']->get('airwallex.http_connect_timeout');
                if (! is_int($timeout) || $timeout < 1 || ! is_int($connectTimeout) || $connectTimeout < 1) {
                    throw new ConfigurationException('Airwallex HTTP timeouts must be positive integers.');
                }

                return new HttpClient(['timeout' => $timeout, 'connect_timeout' => $connectTimeout, 'verify' => true]);
            });
        }
        foreach ([RequestFactoryInterface::class, StreamFactoryInterface::class] as $factory) {
            if (! $this->app->bound($factory)) {
                $this->app->bind($factory, fn () => new HttpFactory);
            }
        }
        $this->app->bindIf(SubscriptionAccessPolicy::class, DefaultSubscriptionAccessPolicy::class);
        $this->app->scoped(Client::class, function (Application $app): Client {
            $context = $app->make(BillingContext::class);
            $attempts = $app['config']->get('airwallex.read_retry_attempts');
            if (! is_int($attempts)) {
                throw new ConfigurationException('Airwallex read retry attempts must be an integer.');
            }
            $store = $app['config']->get('airwallex.cache_store');
            if ($store !== null && (! is_string($store) || $store === '')) {
                throw new ConfigurationException('Airwallex cache store must be a configured store name or null.');
            }

            return Support\SdkResponseShape::client([
                'environment' => Environment::from($context->environment),
                'credentials' => new Credentials($this->stringConfig($app, 'client_id'), $this->stringConfig($app, 'api_key')),
                'httpClient' => $app->make(ClientInterface::class),
                'requestFactory' => $app->make(RequestFactoryInterface::class),
                'streamFactory' => $app->make(StreamFactoryInterface::class),
                'tokenCache' => $app->make(CacheFactory::class)->store($store),
                'retryPolicy' => new RetryPolicy(maxAttempts: $attempts),
            ]);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/airwallex.php');
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\ReconcileCommand::class,
                Console\OperationsCommand::class,
                Console\CreationRetryCommand::class,
                Console\ReceiptsCommand::class,
                Console\EventsCommand::class,
                Console\PruneReceiptsCommand::class,
                Console\TransitionsCommand::class,
                Console\SweepCommand::class,
            ]);
            $this->publishes([__DIR__.'/../config/airwallex.php' => config_path('airwallex.php')], 'airwallex-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'airwallex-migrations');
        }
    }

    private function stringConfig(Application $app, string $key): string
    {
        $value = $app['config']->get('airwallex.'.$key);
        if (! is_string($value) || $value === '') {
            throw new ConfigurationException('Airwallex '.$key.' must be explicitly configured.');
        }

        return $value;
    }
}
