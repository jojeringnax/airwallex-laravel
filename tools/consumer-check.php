<?php

declare(strict_types=1);

namespace AirwallexCandidateQualification;

use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\AirwallexServiceProvider;
use Jojeringnax\AirwallexLaravel\Contracts\SubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    $options = getopt('', ['consumer:', 'mode:', 'evidence:']);
    foreach (['consumer', 'mode', 'evidence'] as $required) {
        check(isset($options[$required]) && is_string($options[$required]) && $options[$required] !== '', 'Consumer, mode and evidence arguments are required.');
    }
    $consumer = realpath($options['consumer']);
    check($consumer !== false && getcwd() === $consumer, 'Run this helper in the explicit isolated consumer directory.');
    require $consumer.'/vendor/autoload.php';
    require __DIR__.'/consumer-fixtures.php';
    $app = require $consumer.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    check($app->configurationIsCached(), 'Native consumer configuration must be cached.');
    check(($app->getLoadedProviders()[AirwallexServiceProvider::class] ?? false) === true, 'Provider auto-discovery failed.');
    check($app->bound(SubscriptionAccessPolicy::class), 'Policy binding is missing.');
    check(! $app->resolved(Client::class), 'SDK must remain lazy during native boot without credentials.');
    check(config('airwallex.environment') === 'sandbox', 'Consumer must default to sandbox.');
    $report = ['status' => 'passed', 'mode' => $options['mode'], 'runtime' => ['php' => PHP_VERSION, 'php_int_size' => PHP_INT_SIZE, 'sqlite' => $app->make('db')->selectOne('select sqlite_version() as version')->version], 'checks' => ['native_config_cache', 'provider_discovery', 'policy_binding', 'lazy_sdk']];
    $contracts = [];
    foreach (glob($consumer.'/vendor/jojeringnax/airwallex-php-sdk/resources/contracts/billing/*.json') as $path) {
        $contract = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        check(($contract['version'] ?? null) === '2026-08-21', 'Installed SDK contract baseline changed; provider review is required.');
        $contracts[basename($path)] = $contract['version'];
    }
    check(count($contracts) >= 7, 'Installed SDK Billing contract inventory is incomplete.');
    $report['sdk'] = ['version' => InstalledVersions::getPrettyVersion('jojeringnax/airwallex-php-sdk'), 'reference' => InstalledVersions::getReference('jojeringnax/airwallex-php-sdk'), 'billing_contract_versions' => $contracts];
    if (is_file($consumer.'/vendor/jojeringnax/airwallex-laravel/routes/airwallex.php')) {
        $routes = $app->make('router')->getRoutes();
        $routes->refreshNameLookups();
        check($routes->getByName('airwallex.webhook') !== null, 'Installed webhook route was not registered.');
        $commands = $app->make(Kernel::class)->all();
        foreach (['airwallex:reconcile', 'airwallex:operations', 'airwallex:receipts', 'airwallex:events', 'airwallex:prune-receipts'] as $command) {
            check(isset($commands[$command]), 'Installed recovery command was not registered.');
        }
        check(! $app->resolved(Client::class), 'Route/command registration eagerly resolved SDK credentials.');
        $report['checks'][] = 'installed_route_and_recovery_commands';
    }
    $tables = ['customers', 'subscriptions', 'subscription_items', 'subscription_slots', 'operations', 'checkouts', 'webhook_receipts', 'domain_events'];
    if (in_array($options['mode'], ['migrated', 'rolled-back', 'recipes'], true)) {
        foreach ($tables as $table) {
            check(Schema::hasTable('airwallex_'.$table) === ($options['mode'] !== 'rolled-back'), 'Package migration table state mismatch: '.$table);
        }
        $report['checks'][] = 'package_table_state';
    }
    if (in_array($options['mode'], ['migrated', 'recipes'], true)) {
        foreach (['revision', 'scheduled_ends_at'] as $column) {
            check(Schema::hasColumn('airwallex_subscriptions', $column), 'Required subscription column missing.');
        }
        check(Schema::hasColumn('airwallex_checkouts', 'revision'), 'Checkout observation column missing.');
        check((int) $app->make('db')->selectOne('PRAGMA foreign_keys')->foreign_keys === 1, 'SQLite foreign keys must be enabled.');
        $report['checks'][] = 'projection_columns_and_foreign_keys';
    }
    if ($options['mode'] === 'recipes') {
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        config(['airwallex.organization_id' => 'org-synthetic', 'airwallex.client_id' => 'client-synthetic', 'airwallex.api_key' => 'key-synthetic', 'airwallex.legal_entity_id' => 'entity-synthetic', 'airwallex.linked_payment_account_id' => 'collector-synthetic', 'airwallex.currency' => 'USD', 'airwallex.models.customer' => CandidateCustomer::class, 'airwallex.models.subscription' => CandidateSubscription::class]);
        Relation::enforceMorphMap(['candidate_team' => Team::class]);
        Schema::create('candidate_teams', static function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        $team = Team::create(['id' => '15e4d1c9-1493-4b1d-aafe-e8553fb10d59']);
        $linked = Team::create(['id' => '01M73000000000000000000000']);
        $package = $consumer.'/vendor/jojeringnax/airwallex-laravel';
        $recipes = [];
        foreach (glob($package.'/examples/*.php') as $path) {
            $recipe = require $path;
            check(is_callable($recipe), 'Installed recipe is not executable: '.basename($path));
            $recipes[basename($path)] = $recipe;
        }
        $supported = ['customer.php', 'create-customer.php', 'import-subscription.php', 'subscription.php', 'checkout.php', 'setup.php', 'invoices.php', 'change-items.php', 'replace-source.php', 'schedule-ending.php', 'cancel-subscription.php', 'recover-operation.php'];
        check(array_diff(array_keys($recipes), $supported) === [], 'A shipped recipe needs a deterministic execution handler.');
        $executed = [];
        $bind = static function (array $plan) use ($app): DenyNetworkClient {
            $client = new DenyNetworkClient($plan);
            $app->make('cache')->flush();
            $app->bind(ClientInterface::class, static fn () => $client);
            $app->forgetInstance(Client::class);

            return $client;
        };
        $done = static function (string $recipe, DenyNetworkClient $http) use (&$executed): void {
            $http->assertConsumed();
            $executed[$recipe] = ['executed' => true, 'synthetic_sdk_requests' => $http->requests];
            echo json_encode(['recipe' => $recipe, 'status' => 'passed'], JSON_THROW_ON_ERROR).PHP_EOL;
        };
        if (isset($recipes['create-customer.php'])) {
            $http = $bind([Fixtures::request('POST', 'billing/billing_customers/create', ['id' => 'customer-synthetic', 'name' => 'Synthetic candidate'])]);
            $customer = $recipes['create-customer.php']($team, 'customer-command', ['name' => 'Synthetic candidate']);
            check($customer instanceof CandidateCustomer && $customer->remote_id === 'customer-synthetic', 'Customer creation recipe/configured subclass failed.');
            $done('create-customer.php', $http);
        } else {
            app(OperationManager::class)->reserveCustomer($team)->update(['remote_id' => 'customer-synthetic']);
        }
        $http = $bind([Fixtures::request('GET', 'billing/billing_customers/customer-linked', ['id' => 'customer-linked', 'name' => 'Synthetic linked'])]);
        $customer = $recipes['customer.php']($linked, 'customer-linked');
        check($customer instanceof CandidateCustomer && $customer->billable_type === 'candidate_team', 'Customer link recipe/morph map failed.');
        $done('customer.php', $http);

        $import = Fixtures::subscription('sub-import', 'customer-synthetic');
        $http = $bind(Fixtures::observation($import, [Fixtures::item('item-import', 'sub-import', '1.000000000000000000001')]));
        $imported = $recipes['import-subscription.php']($team, 'imported', 'sub-import');
        check($imported instanceof CandidateSubscription && $imported->items()->first()->quantity === '1.000000000000000000001', 'Import recipe lost exact quantity/configured subclass.');
        $done('import-subscription.php', $http);

        $current = Fixtures::subscription('sub-direct', 'customer-synthetic');
        $item = Fixtures::item('item-direct', 'sub-direct');
        $http = $bind([
            Fixtures::request('GET', 'billing/payment_sources/source-synthetic', Fixtures::source('source-synthetic', 'customer-synthetic')),
            Fixtures::request('POST', 'billing/subscriptions/create', $current, static function (RequestInterface $request): void {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                check($body['items'][0]['quantity'] === 2 && $body['payment_source_id'] === 'source-synthetic', 'Subscription recipe request changed.');
            }),
            ...Fixtures::observation($current, [$item]),
        ]);
        $subscription = $recipes['subscription.php']($team, 'subscription-command', 'price-synthetic', 'source-synthetic');
        check($subscription instanceof CandidateSubscription && $team->subscribed('default'), 'Subscription recipe did not establish canonical local eligibility.');
        $done('subscription.php', $http);

        $checkout = Fixtures::checkout('checkout-synthetic', 'customer-linked', 'SUBSCRIPTION');
        $http = $bind([Fixtures::request('POST', 'billing/billing_checkouts/create', $checkout), Fixtures::request('GET', 'billing/billing_checkouts/checkout-synthetic', $checkout)]);
        $session = $recipes['checkout.php']($linked, 'checkout-command', 'price-synthetic', ['success_url' => 'https://example.test/success', 'back_url' => 'https://example.test/back']);
        check($session instanceof Checkout && ! $linked->subscribed('default'), 'Hosted creation must not grant independent access.');
        $done('checkout.php', $http);

        $setup = Fixtures::checkout('setup-synthetic', 'customer-linked', 'SETUP');
        $http = $bind([Fixtures::request('POST', 'billing/billing_checkouts/create', $setup), Fixtures::request('GET', 'billing/billing_checkouts/setup-synthetic', $setup)]);
        $session = $recipes['setup.php']($linked, 'setup-command', ['success_url' => 'https://example.test/success', 'back_url' => 'https://example.test/back']);
        check($session->mode === 'SETUP' && ! $linked->subscribed('default'), 'Setup recipe changed eligibility.');
        $done('setup.php', $http);

        foreach (['replace-source.php', 'change-items.php', 'schedule-ending.php', 'cancel-subscription.php'] as $name) {
            if (! isset($recipes[$name])) {
                continue;
            }
            $next = $current;
            $next['updated_at'] = match ($name) {
                'replace-source.php' => '2026-11-10T12:01:00Z',
                'change-items.php' => '2026-11-10T12:02:00Z',
                'schedule-ending.php' => '2026-11-10T12:03:00Z',
                default => '2026-11-10T12:04:00Z',
            };
            $nextItem = $item;
            $plan = Fixtures::observation($current, [$item]);
            if ($name === 'replace-source.php' || $name === 'change-items.php') {
                $next['payment_source_id'] = 'source-replacement';
                $plan[] = Fixtures::request('GET', 'billing/payment_sources/source-replacement', Fixtures::source('source-replacement', 'customer-synthetic'));
            }
            if ($name === 'change-items.php') {
                $nextItem = Fixtures::item('item-direct', 'sub-direct', '5.000', $next['updated_at']);
            }
            if ($name === 'schedule-ending.php') {
                $next['cancel_at_period_end'] = true;
            }
            if ($name === 'cancel-subscription.php') {
                $next['status'] = 'CANCELLED';
            }
            $plan[] = Fixtures::request('POST', 'billing/subscriptions/sub-direct/'.($name === 'cancel-subscription.php' ? 'cancel' : 'update'), $next);
            $http = $bind([...$plan, ...Fixtures::observation($next, [$nextItem])]);
            $subscription = match ($name) {
                'replace-source.php' => $recipes[$name]($subscription, 'replace-command', 'source-replacement'),
                'change-items.php' => $recipes[$name]($subscription, 'items-command', [['id' => 'item-direct', 'quantity' => 5]], ['default_proration_mode' => 'NONE']),
                'schedule-ending.php' => $recipes[$name]($subscription, 'schedule-command'),
                default => $recipes[$name]($subscription, 'cancel-command', 'NONE'),
            };
            check($subscription->status === $next['status'], 'Financial recipe did not observe its canonical lifecycle.');
            if ($name === 'change-items.php') {
                check($subscription->items()->first()->quantity === '5.000', 'Item recipe lost exact observed quantity.');
            }
            if ($name === 'cancel-subscription.php') {
                check(! $team->subscribed('default'), 'Confirmed cancellation must deny access.');
            }
            $done($name, $http);
            $current = $next;
            $item = $nextItem;
        }
        if (isset($recipes['invoices.php'])) {
            $invoice = Fixtures::invoice('customer-synthetic', 'sub-direct');
            $http = $bind([Fixtures::request('GET', 'billing/invoices/invoice-synthetic', $invoice), Fixtures::request('GET', 'billing/invoices/invoice-synthetic', $invoice)]);
            $urls = $recipes['invoices.php']($team, 'invoice-synthetic');
            check($urls === ['pdf_url' => $invoice['pdf_url'], 'hosted_url' => $invoice['hosted_url']], 'Installed document-link recipe failed.');
            $snapshot = $team->findInvoice('invoice-synthetic');
            check($snapshot->toArray()['total_amount'] === '9007199254740993.000000000000000001', 'Installed invoice lost exact monetary text.');
            check(! str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), 'https://'), 'Invoice JSON leaked document links.');
            $done('invoices.php', $http);
        }
        if (isset($recipes['recover-operation.php'])) {
            // A succeeded creation is inspectable without any HTTP, let alone another POST.
            $client = new DenyNetworkClient([]);
            $app->bind(ClientInterface::class, static fn () => $client);
            $app->forgetInstance(Client::class);
            $operation = Operation::where('action_key', 'subscription-command')->firstOrFail();
            $recovered = $recipes['recover-operation.php']($operation->id);
            check($recovered->status === 'succeeded' && $client->requests === 0, 'Read recovery repeated an already-confirmed financial command.');
            $operation = app(OperationManager::class)->prepare($team->customerMapping(), 'recovery-command', 'customer.update', ['name' => 'Synthetic recovered'], ['remote_id' => 'customer-synthetic', 'profile' => ['name' => 'Synthetic recovered']]);
            $operation->update(['status' => 'ambiguous']);
            $requestId = $operation->request_id;
            $http = $bind([Fixtures::request('GET', 'billing/billing_customers/customer-synthetic', ['id' => 'customer-synthetic', 'name' => 'Synthetic recovered'])]);
            $recovered = $recipes['recover-operation.php']($operation->id);
            check($recovered->id === $operation->id && $recovered->request_id === $requestId && $recovered->status === 'succeeded' && $recovered->remote_id === 'customer-synthetic', 'Canonical recovery did not preserve the ambiguous operation identity.');
            $done('recover-operation.php', $http);
            $executed['recover-operation.php']['known_success_without_http'] = true;
            $executed['recover-operation.php']['ambiguous_update_canonical_get_without_mutation'] = true;
        }
        check(array_diff(array_keys($recipes), array_keys($executed)) === [], 'Not every shipped recipe executed.');
        $report['recipes'] = $executed;
        $report['checks'][] = 'installed_closures_executed_with_real_sdk_deny_network_transport';
        Carbon::setTestNow();
    } else {
        check(in_array($options['mode'], ['boot', 'migrated', 'rolled-back'], true), 'Unsupported consumer check mode.');
    }
    file_put_contents($options['evidence'], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    echo json_encode(['status' => 'passed', 'mode' => $options['mode'], 'recipes_executed' => count($report['recipes'] ?? [])], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'classification' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
