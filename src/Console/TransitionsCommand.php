<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionTransition;
use Throwable;

class TransitionsCommand extends BillingCommand
{
    protected $signature = 'airwallex:transitions {--limit=100 : Maximum already-authorized due plans}';

    protected $description = 'Execute or recover durably authorized due subscription transitions';

    public function handle(): int
    {
        try {
            $query = ModelRegistry::query('subscription_transition');
            $query->where(app(BillingContext::class)->attributes())
                ->whereIn('status', ['scheduled', 'executing', 'ambiguous'])->where('due_index_at', '<=', now()->utc())
                ->orderBy('attempts')->orderBy('id')->limit($this->batchLimit());
            $rows = $query->get();
            $failed = false;
            foreach ($rows as $row) {
                if (! $row instanceof SubscriptionTransition) {
                    throw new ConflictException('Configured transition model is unsupported.');
                }
                try {
                    $result = $row->run();
                    $this->reportRow(['id' => $row->id, 'outcome' => $result->status]);
                    $failed = $failed || in_array($result->status, ['blocked', 'ambiguous'], true);
                } catch (Throwable $error) {
                    $failed = true;
                    $this->reportRow(['id' => $row->id, 'outcome' => $this->classification($error)]);
                }
                // An unresolved early row must not permanently starve later authorized plans.
                $row->increment('attempts');
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
