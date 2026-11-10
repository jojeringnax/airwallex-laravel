<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Http\WebhookController;

$enabled = config('airwallex.webhook.enabled');
if (! is_bool($enabled)) {
    throw new ConfigurationException('Webhook route enablement must be boolean.');
}
if ($enabled) {
    $path = config('airwallex.webhook.path');
    $middleware = config('airwallex.webhook.middleware');
    if (! is_string($path) || trim($path, '/') === '' || ! is_array($middleware)) {
        throw new ConfigurationException('Webhook route path and middleware must be explicitly configured.');
    }
    foreach ($middleware as $entry) {
        if (! is_string($entry) || $entry === '') {
            throw new ConfigurationException('Webhook middleware must contain configured names or classes.');
        }
    }
    Route::post($path, WebhookController::class)->middleware($middleware)->name('airwallex.webhook');
}
