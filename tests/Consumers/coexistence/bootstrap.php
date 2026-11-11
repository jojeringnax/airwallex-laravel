<?php

declare(strict_types=1);

// Optional real-Cashier qualification. No consumer/model code is loaded from a sibling application.
$packageAutoload = getenv('AIRWALLEX_PACKAGE_AUTOLOAD');
$loader = require is_string($packageAutoload) && $packageAutoload !== ''
    ? $packageAutoload : dirname(__DIR__, 3).'/vendor/autoload.php';
if (! is_string($packageAutoload) || $packageAutoload === '') {
    $loader->addPsr4('Jojeringnax\\AirwallexLaravel\\', dirname(__DIR__, 3).'/src/', true);
}
$loader->addPsr4('Jojeringnax\\AirwallexLaravel\\Tests\\', dirname(__DIR__, 2).'/', true);
$fixture = getenv('AIRWALLEX_CASHIER_AUTOLOAD');
if (! is_string($fixture) || ! is_file($fixture)) {
    throw new RuntimeException('An explicit isolated Cashier autoloader is required.');
}
$cashier = require $fixture;
$cashier->unregister();
$cashier->register(false);
