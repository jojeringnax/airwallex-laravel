<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Receipts;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (! isset($argv) || count($argv) !== 5) {
    throw new RuntimeException('Receipt worker requires disposable database, barrier, body fixture and timestamp.');
}
[$script, $database, $barrier, $bodyFile, $timestamp] = $argv;
$app = new Application(dirname(__DIR__, 2));
Application::setInstance($app);
$app->instance('config', new Repository(['database' => ['default' => 'race'], 'airwallex' => ['payments' => ['webhook' => ['secret' => 'synthetic-payment-secret', 'tolerance_milliseconds' => 300000, 'version' => '2026-08-21']]]]));
$capsule = new Capsule($app);
$capsule->addConnection(TestDatabase::connection($database), 'race');
$capsule->getDatabaseManager()->setDefaultConnection('race');
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
$body = file_get_contents($bodyFile);
if ($body === false) {
    throw new RuntimeException('Synthetic receipt fixture is missing.');
}
fwrite(STDOUT, "READY\n");
$deadline = microtime(true) + 5;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Receipt barrier timeout.');
    }
    usleep(1000);
}
try {
    $receipt = (new Receipts(new Context('sandbox', 'account-payments')))->accept($body, $timestamp, hash_hmac('sha256', $timestamp.$body, 'synthetic-payment-secret'));
    fwrite(STDOUT, json_encode(['outcome' => 'accepted', 'id' => $receipt->id], JSON_THROW_ON_ERROR));
} catch (EvidenceException) {
    // A transient storage conflict corresponds to the receiver's retryable 503.
    fwrite(STDOUT, json_encode(['outcome' => 'retryable_storage'], JSON_THROW_ON_ERROR));
}
