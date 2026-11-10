<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

/** @internal Only safe observation fields cross the receipt completion boundary. */
final readonly class ReceiptOutcome
{
    /** @param list<array{customerId:string,remoteId:string,status:string,paymentStatus:?string,subscriptionId:?string}> $observations */
    public function __construct(public string $status = 'processed', public ?string $reason = null, public array $observations = []) {}
}
