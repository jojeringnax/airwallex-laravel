<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\Airwallex\RetryPolicy;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Context::class, fn () => new Context($this->setting('environment'), $this->setting('account_id')));
        $registryClass = 'Jojeringnax\\AirwallexLaravel\\Services\\SweepRegistry';
        if (class_exists($registryClass)) {
            $this->app->extend($registryClass, function ($registry) {
                $account = config('airwallex.payments.account_id');
                if (is_string($account) && $account !== '') {
                    foreach (app(Recovery::class)->sweepFamilies() as $family) {
                        $registry->register($family);
                    }
                }

                return $registry;
            });
        }
        $this->app->scoped(Client::class, function (Application $app): Client {
            $context = $app->make(Context::class);
            $attempts = config('airwallex.read_retry_attempts');
            $cache = config('airwallex.payments.cache_store');
            if (! is_int($attempts) || ($cache !== null && (! is_string($cache) || $cache === ''))) {
                throw new ConfigurationException('Payments read attempts/cache selection is invalid.');
            }

            return new Client(SdkResponseShape::client([
                'environment' => Environment::from($context->environment),
                'credentials' => new Credentials($this->setting('client_id'), $this->setting('api_key')),
                'httpClient' => $app->make(ClientInterface::class),
                'requestFactory' => $app->make(RequestFactoryInterface::class),
                'streamFactory' => $app->make(StreamFactoryInterface::class),
                'loginAccountId' => $context->accountId,
                'tokenCache' => $app->make(CacheFactory::class)->store($cache),
                'retryPolicy' => new RetryPolicy(maxAttempts: $attempts),
            ]));
        });
    }

    private function setting(string $key): string
    {
        $value = config('airwallex.payments.'.$key);
        if (! is_string($value) || $value === '') {
            throw new ConfigurationException('Payments context and credentials must be explicitly configured.');
        }

        return $value;
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/payments.php');
        if ($this->app->runningInConsole()) {
            $this->commands([RecoveryCommand::class]);
        }
    }
}
