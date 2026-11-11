<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;

final class QueueRouting
{
    /** @return array{connection: ?string, queue: ?string} */
    public static function for(string $family): array
    {
        if (! in_array($family, ['webhook', 'events'], true)) {
            throw new ConfigurationException('Unsupported billing queue family.');
        }
        $connection = config('airwallex.'.$family.'.queue_connection');
        $queue = config('airwallex.'.$family.'.queue');
        foreach ([$connection, $queue] as $value) {
            if ($value !== null && (! is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value))) {
                throw new ConfigurationException('Billing queue connection/name must be a bounded configured name or null.');
            }
        }
        if ($connection !== null) {
            $connections = config('queue.connections', []);
            if (! is_array($connections) || ! isset($connections[$connection]) || ! is_array($connections[$connection])
                || ! is_string($connections[$connection]['driver'] ?? null) || $connections[$connection]['driver'] === '') {
                throw new ConfigurationException('Billing queue connection is not configured.');
            }
        }

        return ['connection' => $connection, 'queue' => $queue];
    }
}
