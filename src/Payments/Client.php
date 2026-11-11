<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\Airwallex\Client as SdkClient;

/** A separate container binding prevents Billing's organization client being reused. */
final readonly class Client
{
    public function __construct(public SdkClient $sdk) {}

    public function __debugInfo(): array
    {
        return ['client' => 'Payments'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Payments clients cannot be serialized.');
    }
}
