<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Receipts;

class ProcessReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $receiptId, public readonly string $environment, public readonly string $accountId) {}

    public function handle(Context $context): void
    {
        if ($context->environment !== $this->environment || $context->accountId !== $this->accountId) {
            throw new OwnershipException('Queued Payments receipt belongs to another configured account.');
        }
        app(Receipts::class)->process($this->receiptId);
    }
}
