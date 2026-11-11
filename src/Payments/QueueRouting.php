<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;

/** Separate Payments configuration; no receiver context/credentials are selected by a job. */
final class QueueRouting
{
    /** @return array{connection:?string,queue:?string} */
    public static function for(string $family): array
    {
        if (! in_array($family, ['webhook', 'events'], true)) {
            throw new ConfigurationException('Unsupported Payments queue family.');
        }
        $connection = config('airwallex.payments.'.$family.'.queue_connection');
        $queue = config('airwallex.payments.'.$family.'.queue');
        foreach ([$connection, $queue] as $value) {
            if ($value !== null && (! is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value))) {
                throw new ConfigurationException('Payments queue routing must use bounded configured names or null.');
            }
        }
        if ($connection !== null) {
            $connections = config('queue.connections', []);
            if (! is_array($connections) || ! is_array($connections[$connection] ?? null) || ! is_string($connections[$connection]['driver'] ?? null) || $connections[$connection]['driver'] === '') {
                throw new ConfigurationException('Payments queue connection is not configured.');
            }
        }

        return ['connection' => $connection, 'queue' => $queue];
    }
}
