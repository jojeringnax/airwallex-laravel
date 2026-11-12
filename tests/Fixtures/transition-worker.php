<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Credentials;
use Jojeringnax\Airwallex\Environment;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\BillingException;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionTransition;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (! isset($argv) || count($argv) !== 5) {
    throw new RuntimeException('Transition worker requires database, barrier, identity and mode arguments.');
}
[$script, $database, $barrier, $id, $mode] = $argv;
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
$app->instance(BillingContext::class, new BillingContext('sandbox', 'org-test'));
Carbon::setTestNow('2026-12-01T00:01:00Z');
$http = new class($barrier) implements ClientInterface
{
    public function __construct(private string $barrier) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_ends_with($path, '/authentication/login')) {
            return FakeHttpClient::login();
        }
        $written = is_file($this->barrier.'.writes');
        $remote = Billing::subscription(['current_period_starts_at' => '2026-12-01T00:00:00Z', 'current_period_ends_at' => '2027-01-01T00:00:00Z', 'updated_at' => $written ? '2026-12-01T00:01:01Z' : '2026-12-01T00:01:00Z']);
        if ($request->getMethod() === 'GET' && str_ends_with($path, '/sub-test')) {
            return FakeHttpClient::json($remote);
        }
        if ($request->getMethod() === 'GET' && str_ends_with($path, '/sub-test/items')) {
            return Billing::page([Billing::item(['quantity' => $written ? '2' : '1', 'updated_at' => $remote['updated_at']])]);
        }
        if ($request->getMethod() === 'POST' && str_ends_with($path, '/sub-test/update')) {
            file_put_contents($this->barrier.'.writes', "WRITE\n", FILE_APPEND | LOCK_EX);

            return FakeHttpClient::json([...$remote, 'updated_at' => '2026-12-01T00:01:01Z']);
        }
        throw new RuntimeException('Unexpected deterministic transition request.');
    }
};
$factory = new HttpFactory;
$app->instance(Client::class, new Client(Environment::Sandbox, new Credentials('synthetic-client', 'synthetic-key'), $http, $factory, $factory));
fwrite(STDOUT, "READY\n");
$deadline = microtime(true) + 10;
while (! is_file($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Transition worker barrier timed out.');
    }
    usleep(1000);
}
try {
    $plan = SubscriptionTransition::query()->findOrFail($id);
    $result = match ($mode) {
        'run' => $plan->run(),
        'cancel' => $plan->cancel(),
        'replace' => $plan->replace('replacement', '2026-12-02T00:00:00Z', [['id' => 'item-test', 'quantity' => 3]], ['default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'], '2026-12-02T00:10:00Z'),
        default => throw new RuntimeException('Unknown transition worker mode.'),
    };
    fwrite(STDOUT, json_encode(['outcome' => $result->status], JSON_THROW_ON_ERROR));
} catch (BillingException $error) {
    fwrite(STDOUT, json_encode(['outcome' => 'held', 'reason' => $error->getMessage()], JSON_THROW_ON_ERROR));
} catch (QueryException) {
    fwrite(STDOUT, json_encode(['outcome' => 'local_conflict'], JSON_THROW_ON_ERROR));
}
