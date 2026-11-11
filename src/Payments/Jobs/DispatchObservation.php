<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\ObservationDispatcher;

class DispatchObservation implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $observationId, public readonly string $environment, public readonly string $accountId) {}

    public function handle(Context $context): void
    {
        if ($context->environment !== $this->environment || $context->accountId !== $this->accountId) {
            throw new OwnershipException('Queued Payments observation belongs to another configured account.');
        }
        app(ObservationDispatcher::class)->dispatch($this->observationId);
    }
}
