<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;

class Outbox
{
    public function __construct(private readonly BillingContext $context) {}

    /** @param array<string,mixed> $payload */
    public function record(string $name, Model $resource, array $payload): DomainEvent
    {
        $this->context->assertModel($resource);
        $event = ModelRegistry::make('domain_event', [...$this->context->attributes(), 'event_name' => $name, 'resource_type' => $resource->getTable(), 'resource_id' => $resource->getKey(), 'payload' => $payload]);
        $event->save();

        return $event;
    }
}
