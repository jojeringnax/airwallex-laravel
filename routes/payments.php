<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Payments\WebhookController;

$enabled = config('airwallex.payments.webhook.enabled', false);
if (! is_bool($enabled)) {
    throw new ConfigurationException('Payments webhook route enablement must be boolean.');
}
if ($enabled) {
    $path = config('airwallex.payments.webhook.path');
    $middleware = config('airwallex.payments.webhook.middleware');
    if (! is_string($path) || trim($path, '/') === '' || ! is_array($middleware) || trim($path, '/') === trim((string) config('airwallex.webhook.path'), '/')) {
        throw new ConfigurationException('Payments webhook requires a distinct configured route and middleware.');
    }
    foreach ($middleware as $entry) {
        if (! is_string($entry) || $entry === '') {
            throw new ConfigurationException('Payments webhook middleware entries must be configured names.');
        }
    }
    Route::post($path, WebhookController::class)->middleware($middleware)->name('airwallex.payments.webhook');
}
