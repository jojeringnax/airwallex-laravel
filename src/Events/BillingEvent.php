<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Events;

/** Provider observations, never an application access decision. Deduplicate by id. */
abstract readonly class BillingEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $id,
        public string $environment,
        public string $organizationId,
        public string $resourceType,
        public string $resourceId,
        public array $payload,
    ) {}
}
