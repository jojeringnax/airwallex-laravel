<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Services\OutboxDispatcher;

class DispatchDomainEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $eventId, public string $environment, public string $organizationId) {}

    public function handle(BillingContext $context): void
    {
        if ($this->environment !== $context->environment || $this->organizationId !== $context->organizationId) {
            throw new OwnershipException('Domain event job belongs to a different billing context.');
        }
        app(OutboxDispatcher::class)->dispatch($this->eventId);
    }
}
