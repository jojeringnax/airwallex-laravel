<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\BillingException;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\CreditNoteHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (! isset($argv) || count($argv) !== 4) {
    throw new RuntimeException('Credit worker requires disposable database, barrier and action.');
}
[$script, $database, $barrier, $action] = $argv;
$app = new Application(dirname(__DIR__, 2));
Application::setInstance($app);
$app->instance('config', new Repository(['database' => ['default' => 'race'], 'airwallex' => ['models' => [], 'legal_entity_id' => 'entity-test', 'linked_payment_account_id' => 'collector-test']]));
$capsule = new Capsule($app);
$capsule->addConnection(TestDatabase::connection($database), 'race');
$capsule->getDatabaseManager()->setDefaultConnection('race');
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
$context = new BillingContext('sandbox', 'org-test');
$app->instance(BillingContext::class, $context);
$http = new CreditNoteHttpClient;
$http->afterWrite = static function () use ($barrier): void {
    file_put_contents($barrier.'.writes', "WRITE\n", FILE_APPEND | LOCK_EX);
    throw new RuntimeException('Synthetic lost original response.');
};
$factory = new HttpFactory;
$app->instance(Client::class, new Client(Environment::Sandbox, new Credentials('synthetic-client', 'synthetic-key'), $http, $factory, $factory));
$customer = Customer::query()->where('remote_id', 'customer-test')->firstOrFail();
$invoice = new Invoice('invoice-test', 'customer-test', 'sub-historical', $context, $customer->id, null, $http->invoice);
fwrite(STDOUT, "READY\n");
$deadline = microtime(true) + 10;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Credit worker barrier timeout.');
    }
    usleep(1000);
}
try {
    $invoice->createCreditNote($action, 'BEFORE_PAYMENT', 'GOODWILL_GESTURE');
    throw new RuntimeException('A synthetic lost response cannot confirm creation.');
} catch (BillingException|QueryException) {
    fwrite(STDOUT, "RETAINED\n");
}
