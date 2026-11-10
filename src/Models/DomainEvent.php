<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $event_name
 * @property string $resource_type
 * @property string $resource_id
 * @property array<string,mixed> $payload
 * @property int $attempts
 * @property ?string $lease_token
 * @property ?CarbonImmutable $lease_until
 * @property ?CarbonImmutable $dispatched_at
 */
class DomainEvent extends BillingModel
{
    protected $table = 'airwallex_domain_events';

    protected $casts = ['payload' => 'array', 'attempts' => 'integer', 'dispatched_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'event_name', 'resource_type', 'resource_id', 'payload'];
    }
}
