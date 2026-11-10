<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Jojeringnax\AirwallexLaravel\Contracts\SubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Models\Subscription;

class DefaultSubscriptionAccessPolicy implements SubscriptionAccessPolicy
{
    public function allows(Subscription $subscription): bool
    {
        if (! in_array($subscription->status, ['ACTIVE', 'IN_TRIAL'], true)) {
            return false;
        }
        $now = now()->format('Y-m-d\TH:i:s.uP');
        try {
            if (! $this->within($now, $subscription->starts_at, $subscription->ends_at)) {
                return false;
            }
            if ($subscription->status === 'IN_TRIAL') {
                if (! $this->within($now, $subscription->trial_starts_at, $subscription->trial_ends_at, true)
                    || Timestamp::compare($subscription->trial_starts_at, $subscription->starts_at) < 0
                    || ($subscription->ends_at !== null && Timestamp::compare($subscription->trial_ends_at, $subscription->ends_at) > 0)) {
                    return false;
                }
            }
            if ($subscription->cancel_at_period_end) {
                $end = $subscription->scheduled_ends_at;
                if (! is_string($end) || Timestamp::compare($now, $end) >= 0) {
                    return false;
                }
            }

            return true;
        } catch (EvidenceException) {
            return false;
        }
    }

    private function within(string $now, mixed $start, mixed $end, bool $endRequired = false): bool
    {
        if (! is_string($start) || ($endRequired && ! is_string($end))) {
            return false;
        }
        if ($end !== null && (! is_string($end) || Timestamp::compare($start, $end) >= 0)) {
            return false;
        }

        return Timestamp::compare($now, $start) >= 0 && ($end === null || Timestamp::compare($now, $end) < 0);
    }
}
