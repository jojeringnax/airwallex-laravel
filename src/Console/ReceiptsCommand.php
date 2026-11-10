<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Services\ReceiptManager;
use Throwable;

class ReceiptsCommand extends BillingCommand
{
    protected $signature = 'airwallex:receipts {--id= : One local receipt ID} {--replay : Explicitly reset one retained terminal receipt for read processing} {--limit=100} {--after=}';

    protected $description = 'Dispatch durable pending, failed or expired processing receipts';

    public function handle(ReceiptManager $receipts): int
    {
        try {
            $limit = $this->batchLimit();
            $id = $this->identifierOption('id');
            if ($this->option('replay') && (! is_string($id) || $id === '')) {
                throw new ConflictException('Explicit replay requires one local receipt ID.');
            }
            $query = $this->scoped('webhook_receipt');
            if (is_string($id) && $id !== '') {
                $query->whereKey($id);
            } else {
                $query->where(function ($query): void {
                    $query->whereIn('status', ['pending', 'failed'])->orWhere(function ($query): void {
                        $query->where('status', 'processing')->where('lease_until', '<=', now()->utc());
                    });
                });
            }
            $query->limit($limit);
            $rows = $query->get();
            if ($id !== null && $rows->isEmpty()) {
                throw new ConflictException('Receipt was not found in the configured context.');
            }
            $failed = false;
            foreach ($rows as $receipt) {
                if (! $receipt instanceof WebhookReceipt) {
                    throw new ConflictException('Configured receipt model is invalid.');
                }
                try {
                    if ($this->option('replay')) {
                        $receipt = $receipts->requestReplay($receipt);
                    }
                    $sent = $receipts->dispatch($receipt);
                    $receipt->refresh();
                    $failed = $failed || (! $sent && ! in_array($receipt->status, ['processed', 'ignored'], true));
                    $this->reportRow(['id' => $receipt->id, 'status' => $receipt->status, 'reason' => $receipt->last_error, 'outcome' => $sent ? 'dispatched' : 'retained', 'after' => $receipt->id]);
                } catch (Throwable $error) {
                    $failed = true;
                    $this->reportRow(['id' => $receipt->id, 'outcome' => $this->classification($error), 'after' => $receipt->id]);
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
