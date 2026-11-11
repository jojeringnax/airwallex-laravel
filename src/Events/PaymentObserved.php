<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Events;

/** Stable delivery ID; application financial identity deduplication remains separate. */
final readonly class PaymentObserved
{
    public function __construct(public string $id, public string $environment, public string $accountId, public string $resourceType, public string $resourceId, public array $payload) {}
}
