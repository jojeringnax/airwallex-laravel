<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Services\ReceiptManager;

class ProcessWebhookReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $receiptId, public readonly string $environment, public readonly string $organizationId) {}

    public function handle(): void
    {
        if (config('airwallex.environment') !== $this->environment || config('airwallex.organization_id') !== $this->organizationId) {
            throw new OwnershipException('Queued receipt belongs to a different configured billing context.');
        }

        app(ReceiptManager::class)->process($this->receiptId);
    }
}
