<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionTransition;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Timestamp;
use Throwable;

class SubscriptionTransitionManager
{
    public function __construct(private readonly BillingContext $context) {}

    public function schedule(Subscription $subscription, string $actionKey, string $effectiveAt, array $items, array $options, string $expiresAt, ?SubscriptionTransition $replaces = null): SubscriptionTransition
    {
        $this->context->assertModel($subscription);
        if ($actionKey === '' || strlen($actionKey) > 255 || $items === [] || ! array_is_list($items)
            || ! is_string($options['billing_action'] ?? null)
            || ! is_string($options['default_proration_mode'] ?? null)
            || array_diff(array_keys($options), ['billing_action', 'default_proration_mode', 'proration_date']) !== []) {
            throw new ConflictException('A bounded action, nonempty item list and explicit billing/proration choices are required.');
        }
        $specification = [$subscription->id, $effectiveAt, $items, $options, $expiresAt, $replaces?->id];
        $hash = hash('sha256', CanonicalJson::encode($specification));
        $existing = ModelRegistry::query('subscription_transition')->where([...$this->context->attributes(), 'action_key' => $actionKey])->first();
        if ($existing) {
            if (! hash_equals($existing->intent_hash, $hash)) {
                throw new ConflictException('Transition action key was already used for a different intent.');
            }

            return $existing;
        }
        if (Timestamp::compare($effectiveAt, now()->format('Y-m-d\TH:i:s.uP')) <= 0 || Timestamp::compare($expiresAt, $effectiveAt) < 0) {
            throw new ConflictException('Choose a future effective time and an explicit execution window.');
        }
        $subscription = app(SubscriptionSynchronizer::class)->reconcile($subscription, financial: true);
        if (! in_array($subscription->status, ['ACTIVE', 'IN_TRIAL'], true)) {
            throw new EvidenceException('A transition requires a canonically ACTIVE or IN_TRIAL source generation.');
        }
        $intent = ['remote_id' => $subscription->remote_id, 'expected_shape' => self::shape($subscription), 'items' => $items, 'options' => $options];
        // The index is only an early candidate filter; exact arbitrary fractional comparison decides eligibility.
        $indexedTime = new DateTimeImmutable(preg_replace('/(\.\d{6})\d+/', '$1', $effectiveAt));
        try {
            return DB::transaction(function () use ($subscription, $actionKey, $effectiveAt, $expiresAt, $hash, $intent, $indexedTime, $replaces): SubscriptionTransition {
                app(CommitGuard::class)->assertMayCommit();
                ModelRegistry::query('customer')->whereKey($subscription->customer_id)->lockForUpdate()->firstOrFail();
                $current = ModelRegistry::query('subscription')->findOrFail($subscription->id);
                $this->context->assertModel($current);
                $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $current->customer_id)->where('name', $current->name)->first();
                if ($slot?->subscription_id !== $current->id) {
                    throw new ConflictException('Only the current subscription generation can reserve a transition.');
                }
                if (self::shape($current) !== $intent['expected_shape']) {
                    throw new ConflictException('Subscription changed while preparing its transition.');
                }
                if ($replaces !== null) {
                    if ($replaces->subscription_id !== $subscription->id) {
                        throw new OwnershipException('A replacement must belong to the same subscription generation.');
                    }
                    $this->cancel($replaces);
                }
                $transition = ModelRegistry::make('subscription_transition', [
                    ...$this->context->attributes(), 'subscription_id' => $subscription->id, 'action_key' => $actionKey,
                    'intent_hash' => $hash, 'intent' => $intent, 'effective_at' => $effectiveAt, 'expires_at' => $expiresAt,
                    'due_index_at' => $indexedTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                    'status' => 'scheduled', 'active_key' => $subscription->id,
                ]);
                $transition->save();

                return $transition;
            });
        } catch (QueryException) {
            throw new ConflictException('A concurrent transition already reserves this action or generation.');
        }
    }

    public function replace(SubscriptionTransition $transition, string $actionKey, string $effectiveAt, array $items, array $options, string $expiresAt): SubscriptionTransition
    {
        $this->context->assertModel($transition);
        $subscription = ModelRegistry::query('subscription')->findOrFail($transition->subscription_id);

        return $this->schedule($subscription, $actionKey, $effectiveAt, $items, $options, $expiresAt, $transition);
    }

    public function cancel(SubscriptionTransition $transition): SubscriptionTransition
    {
        $this->context->assertModel($transition);

        return DB::transaction(function () use ($transition): SubscriptionTransition {
            app(CommitGuard::class)->assertMayCommit();
            $transition = ModelRegistry::query('subscription_transition')->findOrFail($transition->id);
            $this->context->assertModel($transition);
            if ($transition->status === 'cancelled') {
                return $transition;
            }
            $operation = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => self::commandKey($transition)])->first();
            if (! in_array($transition->status, ['scheduled', 'blocked', 'cancelled'], true)
                || ($operation !== null && ! in_array($operation->status, ['prepared', 'failed'], true))) {
                throw new ConflictException('An attempted transition cannot be cancelled or replaced locally.');
            }
            $changed = ModelRegistry::query('subscription_transition')->whereKey($transition->id)
                ->whereIn('status', ['scheduled', 'blocked', 'cancelled'])->update(['status' => 'cancelled', 'active_key' => null]);
            if ($changed !== 1) {
                throw new ConflictException('The transition began execution while cancellation was requested.');
            }
            if ($operation?->status === 'prepared') {
                ModelRegistry::query('operation')->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'failed', 'lock_key' => null, 'error_code' => 'transition_cancelled']);
            }

            return $transition->fresh();
        });
    }

    public function run(SubscriptionTransition $transition): SubscriptionTransition
    {
        $this->context->assertModel($transition);
        $transition = ModelRegistry::query('subscription_transition')->findOrFail($transition->id);
        $this->context->assertModel($transition);
        if (! in_array($transition->status, ['scheduled', 'executing', 'ambiguous'], true)) {
            return $transition;
        }
        if (Timestamp::compare(now()->format('Y-m-d\TH:i:s.uP'), $transition->effective_at) < 0) {
            return $transition;
        }
        if ($transition->status === 'scheduled') {
            if (ModelRegistry::query('subscription_transition')->whereKey($transition->id)->where('status', 'scheduled')->update(['status' => 'executing']) !== 1) {
                throw new ConflictException('A concurrent worker claimed or cancelled this transition.');
            }
        }
        try {
            $subscription = ModelRegistry::query('subscription')->findOrFail($transition->subscription_id);
            $this->context->assertModel($subscription);
            if ($subscription->remote_id !== $transition->intent['remote_id']) {
                throw new OwnershipException('Transition source generation no longer matches.');
            }
            app(SubscriptionManager::class)->applyTransition($subscription, self::commandKey($transition), $transition->intent['items'], $transition->intent['options'], $transition->intent['expected_shape'], $transition->expires_at);
            $operation = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => self::commandKey($transition)])->firstOrFail();
            $complete = $operation->status === 'succeeded';
            ModelRegistry::query('subscription_transition')->whereKey($transition->id)->whereIn('status', ['executing', 'ambiguous'])
                ->update(['status' => $complete ? 'completed' : 'ambiguous', 'active_key' => $complete ? null : $transition->subscription_id]);

            return $transition->fresh();
        } catch (Throwable $error) {
            $operation = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => self::commandKey($transition)])->first();
            $attempted = $operation !== null && in_array($operation->status, ['in_flight', 'ambiguous', 'acknowledged', 'succeeded'], true);
            ModelRegistry::query('subscription_transition')->whereKey($transition->id)->whereIn('status', ['executing', 'ambiguous'])
                ->update(['status' => $attempted ? 'ambiguous' : 'blocked']);
            throw $error;
        }
    }

    /** @internal Stable financial precondition, independent of natural period rollover. */
    public static function shape(Subscription $subscription): string
    {
        $items = [];
        foreach ($subscription->items()->get() as $item) {
            $items[$item->remote_id] = ['price_id' => $item->getAttribute('price_id'), 'quantity' => $item->getAttribute('quantity')];
        }
        ksort($items, SORT_STRING);
        $controls = array_intersect_key($subscription->snapshot, array_flip(['collection_method', 'payment_source_id', 'cancel_at_period_end', 'ends_at', 'starts_at']));

        return hash('sha256', CanonicalJson::encode([$subscription->id, $subscription->remote_id, $subscription->customer_id, $controls, $items]));
    }

    private static function commandKey(SubscriptionTransition $transition): string
    {
        return 'transition/'.$transition->id;
    }
}
