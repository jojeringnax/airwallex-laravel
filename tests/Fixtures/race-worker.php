<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (! isset($argv) || ! in_array(count($argv), [4, 5], true)) {
    throw new RuntimeException('Race worker requires database, barrier and action arguments.');
}
[$script, $database, $barrier, $command] = $argv;
$app = new Application(dirname(__DIR__, 2));
Application::setInstance($app);
$app->instance('config', new Repository(['database' => ['default' => 'race'], 'airwallex' => ['models' => []]]));
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true, 'busy_timeout' => 10000], 'race');
$capsule->getDatabaseManager()->setDefaultConnection('race');
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
$context = new BillingContext('sandbox', 'org-test');
$app->instance(BillingContext::class, $context);
$manager = new OperationManager($context);
$billable = Team::query()->findOrFail('race');
fwrite(STDOUT, "READY\n");
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Race barrier timeout.');
    }
    usleep(1000);
}
if (($argv[4] ?? '') === 'delayed-create') {
    usleep(100000);
}
$customer = $manager->reserveCustomer($billable);
try {
    if (($argv[4] ?? 'create') === 'import') {
        $factory = new HttpFactory;
        $remote = Billing::subscription();
        $http = new FakeHttpClient([FakeHttpClient::login(), FakeHttpClient::json($remote), Billing::page([]), FakeHttpClient::json($remote)]);
        $app->instance(Client::class, new Client(Environment::Sandbox, new Credentials('synthetic-client', 'synthetic-key'), $http, $factory, $factory));
        $subscription = $app->make(SubscriptionSynchronizer::class)->import($customer, 'default', 'sub-test');
        fwrite(STDOUT, json_encode(['customer' => $customer->id, 'subscription' => $subscription->id], JSON_THROW_ON_ERROR));
        exit;
    }
    $operation = $manager->prepare($customer, $command, 'create', ['price' => 'price'], [], name: 'default');
    fwrite(STDOUT, json_encode(['customer' => $customer->id, 'operation' => $operation->id], JSON_THROW_ON_ERROR));
} catch (ConflictException) {
    fwrite(STDOUT, json_encode(['customer' => $customer->id, 'conflict' => true], JSON_THROW_ON_ERROR));
}
