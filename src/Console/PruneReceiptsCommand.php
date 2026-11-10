<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\Services\ReceiptManager;
use Throwable;

class PruneReceiptsCommand extends BillingCommand
{
    protected $signature = 'airwallex:prune-receipts {--limit=100}';

    protected $description = 'Purge retained terminal receipt bodies while preserving deduplication identity';

    public function handle(ReceiptManager $receipts): int
    {
        try {
            $this->reportRow(['outcome' => 'pruned', 'count' => $receipts->prune($this->batchLimit())]);

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
