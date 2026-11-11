<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Jojeringnax\AirwallexLaravel\Services\SweepManager;
use Jojeringnax\AirwallexLaravel\Support\SweepFamily;
use Jojeringnax\AirwallexLaravel\Tests\Support\TestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
class SweepWorkerRow extends Model
{
    protected $table = 'sweep_test_rows';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}

if (! isset($argv) || count($argv) !== 3) {
    throw new RuntimeException('Sweep worker requires disposable database and barrier.');
}
[$script, $database, $barrier] = $argv;
$app = new Application(dirname(__DIR__, 2));
Application::setInstance($app);
$app->instance('config', new Repository(['database' => ['default' => 'race'], 'airwallex' => ['sweep' => ['lease_seconds' => 300]]]));
$capsule = new Capsule($app);
$capsule->addConnection(TestDatabase::connection($database), 'race');
$capsule->getDatabaseManager()->setDefaultConnection('race');
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
$family = new SweepFamily('race', 'sandbox', 'billing', 'org-test', fn () => SweepWorkerRow::query()->where('owner', 'org-test'),
    function (Model $row) use ($barrier): bool {
        file_put_contents($barrier.'.attempts', $row->getKey()."\n", FILE_APPEND | LOCK_EX);
        usleep(500000);

        return true;
    });
fwrite(STDOUT, "READY\n");
$deadline = microtime(true) + 5;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Sweep barrier timeout.');
    }
    usleep(1000);
}
fwrite(STDOUT, json_encode((new SweepManager)->run($family, 1), JSON_THROW_ON_ERROR));
