<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\Services\OutboxDispatcher;
use Throwable;

class EventsCommand extends BillingCommand
{
    protected $signature = 'airwallex:events {--limit=100} {--after=}';

    protected $description = 'Dispatch committed pending domain events with stable delivery IDs';

    public function handle(OutboxDispatcher $events): int
    {
        try {
            $rows = $this->scoped('domain_event')->whereNull('dispatched_at')
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()->utc()))
                ->limit($this->batchLimit())->get();
            $failed = false;
            foreach ($rows as $row) {
                try {
                    $sent = $events->dispatch($row->id);
                    $failed = $failed || ! $sent;
                    $this->reportRow(['id' => $row->id, 'outcome' => $sent ? 'dispatched' : 'retained', 'after' => $row->id]);
                } catch (Throwable $error) {
                    $failed = true;
                    $this->reportRow(['id' => $row->id, 'outcome' => $this->classification($error), 'after' => $row->id]);
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
