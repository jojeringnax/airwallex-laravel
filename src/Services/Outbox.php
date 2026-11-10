<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Jobs\DispatchDomainEvent;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Throwable;

class Outbox
{
    public function __construct(private readonly BillingContext $context) {}

    /** @param array<string,mixed> $payload */
    public function record(string $name, Model $resource, array $payload): DomainEvent
    {
        $this->context->assertModel($resource);
        OutboxDispatcher::eventClass($name);
        $allowed = ['remote_id', 'mode', 'previous_status', 'status', 'payment_status', 'subscription_id', 'receipt_id', 'provider_event_id', 'event_name', 'resource_id', 'outcome', 'reason', 'operation_id'];
        foreach ($payload as $key => $value) {
            if (! in_array($key, $allowed, true) || (! is_string($value) && $value !== null)) {
                throw new EvidenceException('Domain event payload must contain only safe observation identifiers and classifications.');
            }
        }
        $event = ModelRegistry::make('domain_event', [...$this->context->attributes(), 'event_name' => $name, 'resource_type' => $resource->getTable(), 'resource_id' => $resource->getKey(), 'payload' => $payload]);
        $event->save();
        $kick = function () use ($event): void {
            try {
                $job = new DispatchDomainEvent($event->id, $this->context->environment, $this->context->organizationId);
                $queue = config('airwallex.events.queue');
                if (is_string($queue) && $queue !== '') {
                    $job->onQueue($queue);
                }
                app(Dispatcher::class)->dispatch($job);
            } catch (Throwable) {
                // The committed row remains eligible for scheduled delivery.
            }
        };
        if (DB::transactionLevel() > 0) {
            try {
                DB::afterCommit($kick);
            } catch (Throwable) {
                // Some minimal database bootstraps have no transaction callback
                // manager. Keep the outbox durable for scheduled delivery.
            }
        } else {
            $kick();
        }

        return $event;
    }
}
