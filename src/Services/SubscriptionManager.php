<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\SdkException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\BillingException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionItem;
use Jojeringnax\AirwallexLaravel\SubscriptionBuilder;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\Quantity;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;
use SensitiveParameter;
use stdClass;
use Throwable;

class SubscriptionManager
{
    private const CHANGE_KINDS = ['subscription.quantity', 'subscription.swap', 'subscription.add_item', 'subscription.remove_item', 'subscription.items', 'subscription.free_period', 'subscription.remove_free_period', 'subscription.coupon', 'subscription.remove_coupon', 'subscription.first_charge_discount', 'subscription.remove_first_charge_discount', 'subscription.schedule_ending', 'subscription.schedule_ending_at', 'subscription.restore_renewal', 'subscription.cancel', 'subscription.payment_source'];

    private const DISCOUNT_KINDS = ['subscription.free_period', 'subscription.first_charge_discount', 'subscription.coupon'];

    public function __construct(private readonly BillingContext $context, private readonly OperationManager $operations, private readonly SubscriptionSynchronizer $synchronizer, private readonly CustomerManager $customers) {}

    public function create(SubscriptionBuilder $builder, string $actionKey, array $options = []): Subscription
    {
        $customer = $this->customers->requireCustomer($builder->billable());
        $this->managed($options, ['request_id', 'billing_customer_id', 'items', 'starts_at', 'trial_ends_at', 'discounts']);
        $options = [...Routing::defaults($options), 'collection_method' => config('airwallex.collection_method'), ...$options];
        if (! array_key_exists('currency', $options) && config('airwallex.currency') !== null) {
            $options['currency'] = config('airwallex.currency');
        }
        $specification = ['definition' => $builder->definition(), 'options' => $options];
        $existing = $this->existing($customer, $actionKey, 'subscription.create', $specification, name: $builder->name());
        if ($existing) {
            return $this->finishCreation($customer, $builder->name(), $existing);
        }
        $payload = [...$options, ...$builder->resolvePayload(), 'billing_customer_id' => $customer->remote_id];
        $intent = ['name' => $builder->name(), 'payload' => $payload];
        $couponId = $builder->definition()['coupon_id'] ?? null;
        if ($couponId !== null) {
            $terms = app(CouponEvidence::class)->coupon($couponId);
            app(CouponEvidence::class)->assertCreation($payload, $terms);
            $payload['discounts'] = [['type' => 'COUPON', 'coupon' => ['id' => $couponId]]];
            $intent = ['name' => $builder->name(), 'payload' => $payload, 'coupon_terms' => $terms];
        }
        $operation = $this->operations->prepare($customer, $actionKey, 'subscription.create', $specification, $intent, name: $builder->name());

        return $this->finishCreation($customer, $builder->name(), $operation);
    }

    public function updateQuantity(Subscription $subscription, string $actionKey, mixed $quantity, array $options, ?string $itemId = null): Subscription
    {
        $this->quantityType($quantity);

        return $this->change($subscription, $actionKey, 'subscription.quantity', ['quantity' => $quantity, 'item_id' => $itemId, 'options' => $options], function (Subscription $current) use ($quantity, $options, $itemId): array {
            $item = $this->item($current, $itemId);

            return $this->itemPayload([['id' => $item->remote_id, 'quantity' => $quantity]], $options);
        });
    }

    public function swapPrice(Subscription $subscription, string $actionKey, string $priceId, array $options, ?string $itemId = null, mixed $quantity = null): Subscription
    {
        if ($quantity !== null) {
            $this->quantityType($quantity);
        }

        return $this->change($subscription, $actionKey, 'subscription.swap', ['price_id' => $priceId, 'item_id' => $itemId, 'quantity' => $quantity, 'options' => $options], function (Subscription $current) use ($priceId, $options, $itemId, $quantity): array {
            $item = $this->item($current, $itemId);
            $quantity ??= $this->nativeQuantity($item);

            return $this->itemPayload([['id' => $item->remote_id, 'deleted' => true], ['price_id' => $priceId, 'quantity' => $quantity]], $options);
        });
    }

    public function addItem(Subscription $subscription, string $actionKey, string $priceId, mixed $quantity, array $options): Subscription
    {
        $this->quantityType($quantity);

        return $this->change($subscription, $actionKey, 'subscription.add_item', ['price_id' => $priceId, 'quantity' => $quantity, 'options' => $options], function (Subscription $current) use ($priceId, $quantity, $options): array {
            $this->mutableItems($current);

            return $this->itemPayload([['price_id' => $priceId, 'quantity' => $quantity]], $options);
        });
    }

    public function removeItem(Subscription $subscription, string $actionKey, string $itemId, array $options): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.remove_item', ['item_id' => $itemId, 'options' => $options], function (Subscription $current) use ($itemId, $options): array {
            $item = $this->item($current, $itemId);

            return $this->itemPayload([['id' => $item->remote_id, 'deleted' => true]], $options);
        });
    }

    public function updateItems(Subscription $subscription, string $actionKey, array $items, array $options): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.items', ['items' => $items, 'options' => $options], function (Subscription $current) use ($items, $options): array {
            $this->mutableItems($current);
            foreach ($items as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                    $this->item($current, $item['id']);
                }
            }

            return $this->itemPayload($items, $options);
        });
    }

    public function applyCoupon(Subscription $subscription, string $actionKey, string $couponId): Subscription
    {
        $guard = fn (Subscription $current) => app(CouponEvidence::class)->assertCurrent($current);

        return $this->change($subscription, $actionKey, 'subscription.coupon', ['coupon_id' => $couponId], function (Subscription $current) use ($guard, $couponId): array {
            $guard($current);

            return ['discounts' => [['type' => 'COUPON', 'coupon' => ['id' => $couponId]]], 'default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'];
        }, $guard);
    }

    public function removeCoupon(Subscription $subscription, string $actionKey, string $originalActionKey): Subscription
    {
        return $this->removeDiscount($subscription, $actionKey, $originalActionKey, ['subscription.coupon', 'subscription.create', 'checkout.subscription'], 'subscription.remove_coupon');
    }

    public function applyFreePeriod(Subscription $subscription, string $actionKey, string $couponId): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.free_period', ['coupon_id' => $couponId], function (Subscription $current) use ($couponId): array {
            app(FreePeriodEvidence::class)->assertCurrent($current);

            return ['discounts' => [['type' => 'COUPON', 'coupon' => ['id' => $couponId]]], 'default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'];
        }, fn (Subscription $current) => app(FreePeriodEvidence::class)->assertCurrent($current));
    }

    public function removeFreePeriod(Subscription $subscription, string $actionKey, string $originalActionKey): Subscription
    {
        return $this->removeDiscount($subscription, $actionKey, $originalActionKey, 'subscription.free_period', 'subscription.remove_free_period');
    }

    public function applyFirstChargeDiscount(Subscription $subscription, string $actionKey, string $couponId, mixed $amountOff, string $currency, string $boundary): Subscription
    {
        if (FirstChargeDiscountEvidence::amount($amountOff) === '0') {
            throw new EvidenceException('First-charge discounts require an explicitly approved positive amount.');
        }
        $guard = function (Subscription $current) use ($boundary, $currency): void {
            app(FirstChargeDiscountEvidence::class)->assertCurrent($current);
            $this->assertNextCycleDispatch($current, $boundary);
            if (($current->snapshot['currency'] ?? null) !== $currency) {
                throw new EvidenceException('Discount currency differs from the original Subscription.');
            }
        };

        return $this->change($subscription, $actionKey, 'subscription.first_charge_discount', ['coupon_id' => $couponId, 'amount_off' => $amountOff, 'currency' => $currency, 'discount_boundary' => $boundary], function (Subscription $current) use ($guard, $couponId): array {
            $guard($current);

            return ['discounts' => [['type' => 'COUPON', 'coupon' => ['id' => $couponId]]], 'default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'];
        }, $guard);
    }

    public function removeFirstChargeDiscount(Subscription $subscription, string $actionKey, string $originalActionKey): Subscription
    {
        return $this->removeDiscount($subscription, $actionKey, $originalActionKey, 'subscription.first_charge_discount', 'subscription.remove_first_charge_discount');
    }

    private function removeDiscount(Subscription $subscription, string $actionKey, string $originalActionKey, string|array $originalKind, string $kind): Subscription
    {
        $guard = function (Subscription $current) use ($originalActionKey, $originalKind, $kind): void {
            app($kind === 'subscription.remove_coupon' ? CouponEvidence::class : FreePeriodEvidence::class)->assertGeneration($current);
            $query = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'subscription_id' => $current->id, 'action_key' => $originalActionKey, 'status' => 'succeeded']);
            $query->whereIn('kind', is_array($originalKind) ? $originalKind : [$originalKind]);
            $original = $query->first();
            if ($original === null || ! $this->discountTargetMatches($original, $current) || ! is_array($original->observation['discount_proof'] ?? null)) {
                throw new EvidenceException('Free-period removal requires its original confirmed owned command.');
            }
            $proof = $this->discountEvidence($original->kind)->proof($current, $current->snapshot, $original->intent['coupon_terms']);
            if (! $this->discountEvidence($original->kind)->sameProof($proof, $original->observation['discount_proof'])) {
                throw new EvidenceException('The current free period differs from the original command.');
            }
        };

        return $this->change($subscription, $actionKey, $kind, ['original_action_key' => $originalActionKey], function (Subscription $current) use ($guard): array {
            $guard($current);

            return ['discounts' => [], 'default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'];
        }, $guard);
    }

    /** @internal Execute a previously authorized due plan through the ordinary financial mutex and recovery. */
    public function applyTransition(Subscription $subscription, string $actionKey, array $items, array $options, string $expectedShape, string $expiresAt, ?string $effectiveAt = null, int $shapeVersion = 1): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.items', ['items' => $items, 'options' => $options, 'transition_shape' => $expectedShape, 'expires_at' => $expiresAt, ...($effectiveAt === null ? [] : ['effective_at' => $effectiveAt]), ...($shapeVersion === 1 ? [] : ['shape_version' => $shapeVersion])], function (Subscription $current) use ($items, $options, $expectedShape, $expiresAt, $effectiveAt, $shapeVersion): array {
            $this->assertTransitionDispatch($current, $expectedShape, $expiresAt, $effectiveAt, $shapeVersion);
            $this->mutableItems($current);
            foreach ($items as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                    $this->item($current, $item['id']);
                }
            }

            return $this->itemPayload($items, $options);
        }, fn (Subscription $current) => $this->assertTransitionDispatch($current, $expectedShape, $expiresAt, $effectiveAt, $shapeVersion));
    }

    public function updateItemsForNextCycle(Subscription $subscription, string $actionKey, array $items, string $boundary): Subscription
    {
        $options = ['default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE'];

        return $this->change($subscription, $actionKey, 'subscription.items', ['items' => $items, 'options' => $options, 'next_cycle_boundary' => $boundary], function (Subscription $current) use ($items, $options, $boundary): array {
            $this->assertNextCycleDispatch($current, $boundary);
            $this->mutableItems($current);
            foreach ($items as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                    $this->item($current, $item['id']);
                }
            }

            return $this->itemPayload($items, $options);
        }, fn (Subscription $current) => $this->assertNextCycleDispatch($current, $boundary));
    }

    private function assertNextCycleDispatch(Subscription $current, string $boundary): void
    {
        $observed = $current->status === 'IN_TRIAL' ? $current->snapshot['trial_ends_at'] ?? null : $current->snapshot['current_period_ends_at'] ?? null;
        if (! in_array($current->status, ['ACTIVE', 'IN_TRIAL'], true) || $current->cancel_at_period_end !== false
            || ! is_string($observed) || Timestamp::compare($observed, $boundary) !== 0
            || Timestamp::compare(now()->format('Y-m-d\TH:i:s.uP'), $boundary) >= 0) {
            throw new EvidenceException('Next-cycle changes require the same confirmed future boundary before the first write.');
        }
        $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $current->customer_id)->where('name', $current->name)->first();
        if ($slot?->subscription_id !== $current->id) {
            throw new ConflictException('A historic generation cannot prepare next-cycle billing.');
        }
    }

    private function assertTransitionDispatch(Subscription $current, string $expectedShape, string $expiresAt, ?string $effectiveAt = null, int $shapeVersion = 1): void
    {
        $now = now()->format('Y-m-d\TH:i:s.uP');
        if ($current->status !== 'ACTIVE' || SubscriptionTransitionManager::shape($current, $shapeVersion) !== $expectedShape
            || Timestamp::compare($now, $expiresAt) > 0) {
            throw new EvidenceException('Transition window, source state or expected financial shape no longer matches.');
        }
        if ($effectiveAt !== null) {
            $start = $current->snapshot['current_period_starts_at'] ?? null;
            $end = $current->snapshot['current_period_ends_at'] ?? null;
            if (! is_string($start) || ! is_string($end)
                || Timestamp::compare($effectiveAt, $start) < 0 || Timestamp::compare($effectiveAt, $end) >= 0
                || Timestamp::compare($now, $effectiveAt) < 0 || Timestamp::compare($now, $end) >= 0) {
                throw new EvidenceException('The intended execution time does not belong to the confirmed current billing cycle.');
            }
        }
        $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $current->customer_id)->where('name', $current->name)->first();
        if ($slot?->subscription_id !== $current->id) {
            throw new ConflictException('A historic subscription generation cannot execute a transition.');
        }
    }

    public function cancelAtPeriodEnd(Subscription $subscription, string $actionKey, ?array $renewalBounds = null): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.schedule_ending', $renewalBounds === null ? [] : ['renewal_bounds' => $renewalBounds], fn () => ['cancel_at_period_end' => true]);
    }

    public function cancelAt(Subscription $subscription, string $actionKey, string|DateTimeInterface $endsAt, array $options, ?array $renewalBounds = null): Subscription
    {
        $end = is_string($endsAt) ? $endsAt : $endsAt->format('Y-m-d\TH:i:s.uP');
        Timestamp::compare($end, $end);
        if (array_diff(array_keys($options), ['default_proration_mode', 'billing_action']) !== []
            || ! in_array($options['default_proration_mode'] ?? null, ['NONE', 'PRORATED', 'ALL'], true)
            || ! in_array($options['billing_action'] ?? null, ['DEFER_CHARGE_AND_KEEP_CYCLE', 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE', 'IMMEDIATE_CHARGE_AND_RESET_CYCLE'], true)) {
            throw new ConflictException('Dated ending requires explicit supported proration and billing controls.');
        }
        $specification = ['ends_at' => $end, 'options' => $options, ...($renewalBounds === null ? [] : ['renewal_bounds' => $renewalBounds])];
        $this->context->assertModel($subscription);
        $current = ModelRegistry::query('subscription')->findOrFail($subscription->getKey());
        $this->context->assertModel($current);
        // A repeated attempted action must retain read-only recovery after its date passes.
        if (! ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => $actionKey])->exists()) {
            $this->assertDatedEnding($current, $end, $renewalBounds);
        }
        $guard = fn (Subscription $current) => $this->assertDatedEnding($current, $end, $renewalBounds);

        return $this->change($subscription, $actionKey, 'subscription.schedule_ending_at', $specification,
            function (Subscription $current) use ($guard, $end, $options): array {
                $guard($current);

                return ['ends_at' => $end, 'duration' => null, 'remaining_duration' => null, ...$options];
            }, $guard);
    }

    private function assertDatedEnding(Subscription $subscription, string $end, ?array $renewalBounds = null): void
    {
        $this->context->assertModel($subscription);
        $bounds = $this->renewalBounds($subscription, $renewalBounds);
        if (! is_array($bounds) || ! $this->supportedRestoration($subscription->snapshot, $bounds)
            || ! in_array($subscription->status, ['PENDING', 'IN_TRIAL', 'ACTIVE'], true)
            || ! in_array($subscription->snapshot['collection_method'] ?? null, ['AUTO_CHARGE', 'CHARGE_ON_CHECKOUT', 'OUT_OF_BAND'], true)
            || $subscription->cancel_at_period_end !== false || $subscription->scheduled_ends_at !== null
            || Timestamp::compare($end, now()->format('Y-m-d\TH:i:s.uP')) <= 0
            || ! is_string($subscription->starts_at) || Timestamp::compare($end, $subscription->starts_at) <= 0
            || (is_string($subscription->ends_at) && Timestamp::compare($end, $subscription->ends_at) > 0)) {
            throw new EvidenceException('Dated ending requires a supported unscheduled subscription and a future end within its original lifetime.');
        }
        $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $subscription->customer_id)->where('name', $subscription->name)->first();
        if ($slot?->subscription_id !== $subscription->id) {
            throw new ConflictException('A historical subscription generation cannot schedule an ending.');
        }
    }

    public function restoreRenewal(Subscription $subscription, string $actionKey, string $scheduleActionKey): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.restore_renewal', ['schedule_action_key' => $scheduleActionKey], function (Subscription $current) use ($scheduleActionKey): array {
            $schedule = ModelRegistry::query('operation')->where([
                ...$this->context->attributes(), 'subscription_id' => $current->id,
                'action_key' => $scheduleActionKey, 'status' => 'succeeded',
            ])->whereIn('kind', ['subscription.schedule_ending', 'subscription.schedule_ending_at'])->first();
            $dated = $schedule?->kind === 'subscription.schedule_ending_at';
            $original = $schedule?->intent['original_bounds'] ?? null;
            $bounds = $schedule?->intent['renewal_bounds'] ?? (is_string($original['ends_at'] ?? null) ? ['ends_at' => $original['ends_at']] : null);
            if (! is_array($original) || $schedule->remote_id !== $current->remote_id
                || ! is_array($bounds) || ! $this->supportedRestoration($original, $bounds)
                || ($original['cancel_at_period_end'] ?? null) !== false
                || $current->status !== ($original['status'] ?? null)
                || ($current->snapshot['collection_method'] ?? null) !== ($original['collection_method'] ?? null)
                || ($current->snapshot['payment_source_id'] ?? null) !== ($original['payment_source_id'] ?? null)
                || ($dated ? ! $this->datedEndingMatches($current, $schedule->intent['payload']['ends_at']) : $current->cancel_at_period_end !== true)
                || ! is_string($current->scheduled_ends_at)
                || Timestamp::compare($current->scheduled_ends_at, now()->format('Y-m-d\TH:i:s.uP')) <= 0
                || (is_string($bounds['ends_at'] ?? null) && Timestamp::compare($bounds['ends_at'], now()->format('Y-m-d\TH:i:s.uP')) <= 0)) {
                throw new EvidenceException('Renewal restoration requires an owned saved intent and a supported unchanged state and source.');
            }
            $latest = ModelRegistry::query('operation')->where('subscription_id', $current->id)
                ->whereIn('kind', ['subscription.schedule_ending', 'subscription.schedule_ending_at'])->where('status', '!=', 'failed')->orderByDesc('id')->first();
            $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $current->customer_id)->where('name', $current->name)->first();
            if ($latest?->id !== $schedule->id || $slot?->subscription_id !== $current->id) {
                throw new ConflictException('The cancellation action is not the current owned subscription generation.');
            }

            return ['cancel_at_period_end' => false, ...$bounds];
        });
    }

    public function cancelNow(Subscription $subscription, string $actionKey, string $prorationBehavior): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.cancel', ['proration_behavior' => $prorationBehavior], fn () => ['proration_behavior' => $prorationBehavior]);
    }

    public function updatePaymentSource(Subscription $subscription, string $actionKey, string $sourceId): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.payment_source', ['payment_source_id' => $sourceId], fn () => ['payment_source_id' => $sourceId]);
    }

    /** @internal Read-only remote recovery; this method never sends a financial write. */
    public function reconcileOperation(Operation $operation): Subscription
    {
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->findOrFail($operation->getKey());
        $this->context->assertModel($operation);
        if ($operation->status === 'prepared' || $operation->status === 'failed') {
            throw new ConflictException('Read-only recovery cannot dispatch or replay this financial intent.', $operation->id);
        }
        $customer = ModelRegistry::query('customer')->findOrFail($operation->customer_id);
        $this->context->assertModel($customer);
        if ($operation->kind === 'subscription.create') {
            $name = $operation->intent['name'] ?? null;
            if (! is_string($name) || $name === '') {
                throw new EvidenceException('Creation intent has no durable subscription name.');
            }

            return $this->finishCreation($customer, $name, $operation);
        }
        if (! in_array($operation->kind, self::CHANGE_KINDS, true) || $operation->subscription_id === null) {
            throw new ConflictException('Operation is not a supported subscription financial intent.', $operation->id);
        }
        $subscription = ModelRegistry::query('subscription')->findOrFail($operation->subscription_id);
        $this->context->assertModel($subscription);
        if ($subscription->customer_id !== $customer->id || ($operation->intent['remote_id'] ?? null) !== $subscription->remote_id) {
            throw new OwnershipException('Financial intent target conflicts with its durable mapping.');
        }

        return $this->recoverChange($subscription, $operation);
    }

    /** A candidate is a locator, never proof; verify canonical correlation before recording it. */
    public function recoverCreation(Operation $operation, ?string $candidateId = null): Subscription
    {
        app(CreationRetries::class)->assertCurrent($operation, $candidateId);
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->findOrFail($operation->id);
        $this->context->assertModel($operation);
        if ($operation->kind !== 'subscription.create' || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Only attempted subscription creation supports candidate recovery.', $operation->id);
        }
        if ($candidateId !== null && $operation->remote_id !== null && $operation->remote_id !== $candidateId) {
            throw new OwnershipException('Creation resource identity cannot be replaced.');
        }
        if ($operation->remote_id === null && $candidateId !== null) {
            $customer = ModelRegistry::query('customer')->findOrFail($operation->customer_id);
            $this->context->assertModel($customer);
            $remote = SdkResponseShape::normalize(app(Client::class)->billing()->subscriptions()->retrieve($candidateId));
            $this->response($remote, $candidateId, $customer);
            $metadata = (array) ($remote['metadata'] ?? []);
            if (($remote['billing_customer_id'] ?? null) !== $customer->remote_id || ($metadata['_laravel_operation_id'] ?? null) !== $operation->id) {
                throw new OwnershipException('Candidate subscription has no matching owned creation correlation.');
            }
            Routing::assertSubscription($remote);
            DB::transaction(function () use ($operation, $candidateId): void {
                app(CommitGuard::class)->assertMayCommit();
                $operation->refresh();
                if (! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true) || ($operation->remote_id !== null && $operation->remote_id !== $candidateId)) {
                    throw new ConflictException('Creation correlation changed during recovery.', $operation->id);
                }
                if ($operation->remote_id === null) {
                    $operation->remote_id = $candidateId;
                    $operation->save();
                }
            });
        }

        return $this->reconcileOperation($operation);
    }

    private function finishCreation(Customer $customer, string $name, Operation $operation): Subscription
    {
        if ($operation->status === 'failed') {
            throw new ConflictException('Rejected subscription intent requires a new action key.', $operation->id);
        }
        if ($operation->status === 'succeeded') {
            $local = ModelRegistry::query('subscription')->where($this->context->attributes())->where('remote_id', $operation->remote_id)->first();
            if (! $local || $local->customer_id !== $customer->id || $local->name !== $name) {
                throw new ConflictException('Completed subscription identity has no matching local resource.', $operation->id);
            }

            return $local;
        }
        if ($operation->status !== 'prepared') {
            if ($operation->remote_id === null) {
                throw new AmbiguousOperationException($operation->id);
            }
            try {
                $confirmed = $this->confirmCreation($customer, $name, $operation->remote_id, $operation);
                $this->operations->succeed($operation);

                return $confirmed;
            } catch (Throwable $exception) {
                throw new AmbiguousOperationException($operation->id, $exception);
            }
        }
        $this->preflight($operation, function () use ($customer, $operation): void {
            $payload = $operation->intent['payload'];
            Routing::defaults($payload);
            $this->metadata(array_key_exists('metadata', $payload) ? $payload['metadata'] : new stdClass, $operation->id);
            if (is_string($payload['payment_source_id'] ?? null)) {
                app(SourceManager::class)->retrieve($customer, $payload['payment_source_id']);
            }
            if (array_key_exists('discounts', $payload)) {
                if (! isset($operation->intent['coupon_terms']) || app(CouponEvidence::class)->coupon($operation->intent['coupon_terms']['id']) !== $operation->intent['coupon_terms']) {
                    throw new EvidenceException('Creation Coupon terms changed or lack a managed original intent.');
                }
                app(CouponEvidence::class)->assertCreation($payload, $operation->intent['coupon_terms']);
            }
        });

        return $this->operations->execute($operation, function () use ($operation): array {
            $payload = $operation->intent['payload'];
            if (isset($operation->intent['coupon_terms'])) {
                $expires = $operation->intent['coupon_terms']['expires_at'];
                if (is_string($expires) && Timestamp::compare($expires, now()->format('Y-m-d\TH:i:s.uP')) <= 0) {
                    throw new EvidenceException('Coupon redemption expired before creation dispatch.');
                }
            }
            $payload['metadata'] = $this->metadata(array_key_exists('metadata', $payload) ? $payload['metadata'] : new stdClass, $operation->id);

            return app(Client::class)->billing()->subscriptions()->create([...$payload, 'request_id' => $operation->request_id]);
        }, function (array $response) use ($customer, $name, $operation): Subscription {
            $this->response($response, $response['id'], $customer);
            if (isset($operation->intent['coupon_terms'])) {
                $pending = ModelRegistry::make('subscription', [...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $response['id']]);
                $this->recordDiscountProof($operation, $pending, $response);
            }

            return $this->confirmCreation($customer, $name, $response['id'], $operation);
        });
    }

    private function confirmCreation(Customer $customer, string $name, string $id, Operation $operation): Subscription
    {
        $local = ModelRegistry::query('subscription')->where($this->context->attributes())->where('remote_id', $id)->first();
        if ($local) {
            if ($local->customer_id !== $customer->id || $local->name !== $name) {
                throw new OwnershipException('Subscription result belongs to a different mapping.');
            }
            $result = $this->synchronizer->reconcile($local, financial: true);
            Routing::assertSubscription([...($operation->observed_routing ?? []), ...$result->snapshot]);
            Routing::assertSubscription([...$result->snapshot, ...($result->observed_routing ?? [])]);
            $this->outcome($operation, $result);
            if ($result->status === 'CANCELLED') {
                ModelRegistry::query('subscription_slot')->where('operation_id', $operation->id)->whereNull('subscription_id')->delete();
            }

            return $result;
        }

        $result = $this->synchronizer->confirm($customer, $name, $id, $operation);
        if (isset($operation->intent['coupon_terms'])) {
            $this->outcome($operation, $result);
        }

        return $result;
    }

    private function change(Subscription $subscription, string $actionKey, string $kind, array $specification, Closure $build, ?Closure $dispatchGuard = null): Subscription
    {
        $this->context->assertModel($subscription);
        $subscription = ModelRegistry::query('subscription')->findOrFail($subscription->getKey());
        $this->context->assertModel($subscription);
        $customer = ModelRegistry::query('customer')->findOrFail($subscription->customer_id);
        $this->context->assertModel($customer);
        $operation = $this->existing($customer, $actionKey, $kind, $specification, $subscription);
        $resumedRestoration = $operation?->status === 'prepared' && $kind === 'subscription.restore_renewal';
        $resumedPrepared = $operation?->status === 'prepared';
        if ($operation === null) {
            $subscription = $this->synchronizer->reconcile($subscription, financial: true);
            Routing::assertSubscription([...$subscription->snapshot, ...($subscription->observed_routing ?? [])]);
            if ($subscription->status === 'CANCELLED') {
                throw new ConflictException('A permanently cancelled subscription cannot accept a new financial action.');
            }
            if (! in_array($subscription->status, ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID'], true)) {
                throw new EvidenceException('Unknown lifecycle state requires contract review before a financial change.');
            }
            $payload = $build($subscription);
            $this->managed($payload, ['request_id', 'billing_customer_id', 'id']);
            Routing::defaults($payload);
            $intent = ['payload' => $payload, 'remote_id' => $subscription->remote_id, 'routing' => Routing::defaults(), 'fingerprint' => $this->fingerprint($subscription), 'prior_item_ids' => $subscription->items()->pluck('remote_id')->all()];
            if (isset($specification['next_cycle_boundary'])) {
                $intent['next_cycle_boundary'] = $specification['next_cycle_boundary'];
            }
            if (in_array($kind, self::DISCOUNT_KINDS, true)) {
                $intent['coupon_terms'] = $this->discountEvidence($kind)->coupon($specification['coupon_id']);
                if ($kind === 'subscription.coupon') {
                    app(CouponEvidence::class)->assertCompatible($subscription, $intent['coupon_terms']);
                }
                if ($kind === 'subscription.first_charge_discount') {
                    if ($intent['coupon_terms']['amount_off'] !== FirstChargeDiscountEvidence::amount($specification['amount_off']) || $intent['coupon_terms']['currency'] !== $specification['currency']) {
                        throw new EvidenceException('Coupon terms differ from the consumer-approved amount and currency.');
                    }
                    $intent['discount_boundary'] = $specification['discount_boundary'];
                }
            }
            if (in_array($kind, ['subscription.schedule_ending', 'subscription.schedule_ending_at'], true)) {
                $intent['original_bounds'] = array_intersect_key($subscription->snapshot, array_flip(['status', 'collection_method', 'cancel_at_period_end', 'starts_at', 'ends_at', 'duration', 'remaining_duration', 'payment_source_id', 'current_period_starts_at', 'current_period_ends_at']));
                $intent['renewal_bounds'] = $this->renewalBounds($subscription, $specification['renewal_bounds'] ?? null);
            }
            if ($kind === 'subscription.restore_renewal') {
                $intent['restoration_context'] = array_intersect_key($subscription->snapshot, array_flip(['status', 'collection_method', 'payment_source_id']));
                if ($subscription->cancel_at_period_end === false && is_string($subscription->scheduled_ends_at)) {
                    $intent['restoration_context']['dated_ending'] = $subscription->scheduled_ends_at;
                }
            }
            $operation = $this->operations->prepare($customer, $actionKey, $kind, $specification, $intent, $subscription);
        }
        if ($operation->status !== 'prepared') {
            return $this->recoverChange($subscription, $operation);
        }
        $this->preflight($operation, function () use ($subscription, $customer, $operation, $resumedRestoration, $dispatchGuard, $resumedPrepared): void {
            $current = ModelRegistry::query('subscription')->findOrFail($subscription->id);
            if ($operation->kind === 'subscription.restore_renewal') {
                $this->assertRestorationDispatch($current, $operation);
                if ($resumedRestoration) {
                    $current = $this->synchronizer->reconcile($current, financial: true);
                    $this->assertRestorationDispatch($current, $operation);
                }
            }
            if ($dispatchGuard !== null) {
                $dispatchGuard($current);
                if ($resumedPrepared) {
                    $current = $this->synchronizer->reconcile($current, financial: true);
                    $dispatchGuard($current);
                }
            }
            Routing::assertSubscription([...$current->snapshot, ...($current->observed_routing ?? [])]);
            if ($this->fingerprint($current) !== $operation->intent['fingerprint']) {
                throw new EvidenceException('Subscription changed before the financial action; prepare a new intent.');
            }
            $payload = $operation->intent['payload'];
            if (isset($payload['items'])) {
                $this->assertItemDiscountControls($payload['items'], $payload);
            }
            if (in_array($operation->kind, self::DISCOUNT_KINDS, true)
                && $this->discountEvidence($operation->kind)->coupon($operation->intent['coupon_terms']['id']) !== $operation->intent['coupon_terms']) {
                throw new EvidenceException('Coupon terms changed before the first financial write.');
            }
            if ((array_key_exists('payment_source_id', $payload) && ($payload['payment_source_id'] === null || $payload['payment_source_id'] === ''))
                || (array_key_exists('cancel_at_period_end', $payload) && $payload['cancel_at_period_end'] === null)) {
                throw new EvidenceException('Unverified source removal or schedule clearing requires explicit SDK use.');
            }
            $collection = array_key_exists('collection_method', $payload) ? $payload['collection_method'] : ($current->snapshot['collection_method'] ?? null);
            $sourceId = array_key_exists('payment_source_id', $payload) ? $payload['payment_source_id'] : ($current->snapshot['payment_source_id'] ?? null);
            if (isset($payload['items']) && ! in_array($collection, ['AUTO_CHARGE', 'CHARGE_ON_CHECKOUT', 'OUT_OF_BAND'], true)) {
                throw new EvidenceException('Item mutation requires verified effective collection semantics.');
            }
            if ((isset($payload['items']) || in_array($operation->kind, ['subscription.restore_renewal', 'subscription.free_period', 'subscription.remove_free_period', 'subscription.first_charge_discount', 'subscription.remove_first_charge_discount', 'subscription.coupon', 'subscription.remove_coupon'], true)) && $collection === 'AUTO_CHARGE') {
                if (! is_string($sourceId) || $sourceId === '') {
                    throw new EvidenceException('Automatic collection requires a confirmed owned source.');
                }
                app(SourceManager::class)->retrieve($customer, $sourceId);
            } elseif (is_string($payload['payment_source_id'] ?? null)) {
                app(SourceManager::class)->retrieve($customer, $payload['payment_source_id']);
            }
            $latest = ModelRegistry::query('subscription')->findOrFail($subscription->id);
            if ($this->fingerprint($latest) !== $operation->intent['fingerprint']) {
                throw new EvidenceException('Subscription changed during preflight; prepare a new financial intent.');
            }
            if ($dispatchGuard !== null) {
                $dispatchGuard($latest);
            }
        });

        return $this->operations->execute($operation, function () use ($subscription, $operation, $dispatchGuard): array {
            if (in_array($operation->kind, self::DISCOUNT_KINDS, true)) {
                $expires = $operation->intent['coupon_terms']['expires_at'];
                if (is_string($expires) && Timestamp::compare($expires, now()->format('Y-m-d\TH:i:s.uP')) <= 0) {
                    throw new EvidenceException('Coupon redemption expired before dispatch.');
                }
            }
            if ($operation->kind === 'subscription.restore_renewal') {
                $this->assertRestorationDispatch(ModelRegistry::query('subscription')->findOrFail($subscription->id), $operation);
            }
            if ($dispatchGuard !== null) {
                $dispatchGuard(ModelRegistry::query('subscription')->findOrFail($subscription->id));
            }
            $payload = [...$operation->intent['payload'], 'request_id' => $operation->request_id];
            $resource = app(Client::class)->billing()->subscriptions();

            return $operation->kind === 'subscription.cancel' ? $resource->cancel($subscription->remote_id, $payload) : $resource->update($subscription->remote_id, $payload);
        }, function (array $response) use ($subscription, $customer, $operation): Subscription {
            $this->response($response, $subscription->remote_id, $customer);
            if (in_array($operation->kind, self::DISCOUNT_KINDS, true)) {
                $this->recordDiscountProof($operation, $subscription, $response);
            }
            $result = $this->reconcileChange($subscription, $operation);
            Routing::assertSubscription([...($operation->observed_routing ?? []), ...$result->snapshot]);
            Routing::assertSubscription([...$result->snapshot, ...($result->observed_routing ?? [])]);
            if (! in_array($operation->kind, ['subscription.restore_renewal', 'subscription.schedule_ending_at'], true)) {
                $this->outcome($operation, $result);
            }

            return $result;
        });
    }

    private function outcome(Operation $operation, Subscription $subscription): bool
    {
        $payload = $operation->intent['payload'];
        $controlsConfirmed = $operation->status === 'acknowledged';
        if (in_array($operation->kind, self::DISCOUNT_KINDS, true) || ($operation->kind === 'subscription.create' && isset($operation->intent['coupon_terms']))) {
            if (in_array($operation->kind, ['subscription.create', 'subscription.coupon'], true)) {
                app(CouponEvidence::class)->assertNoItemDiscounts($subscription);
            }
            $proof = $this->discountEvidence($operation->kind)->proof($subscription, $subscription->snapshot, $operation->intent['coupon_terms']);
            if (! is_array($operation->observation['discount_proof'] ?? null) || ! $this->discountEvidence($operation->kind)->sameProof($proof, $operation->observation['discount_proof'])) {
                throw new EvidenceException('The canonical free-period Discount does not match the original response identity and bounds.');
            }
        }
        if (in_array($operation->kind, ['subscription.remove_free_period', 'subscription.remove_first_charge_discount', 'subscription.remove_coupon'], true)) {
            app(FreePeriodEvidence::class)->assertRemoved($subscription);
        }
        if ($operation->kind === 'subscription.schedule_ending_at') {
            $end = $payload['ends_at'];
            if (($subscription->snapshot['cancel_at_period_end'] ?? null) !== false
                || ! is_string($subscription->snapshot['ends_at'] ?? null)
                || Timestamp::compare($subscription->snapshot['ends_at'], $end) !== 0) {
                throw new EvidenceException('The dated Subscription end is not yet canonically confirmed.');
            }
            $this->confirmScheduleProjection($subscription, ['scheduled_ends_at' => $end]);
        }
        if ($operation->kind === 'subscription.restore_renewal') {
            $snapshot = $subscription->snapshot;
            $context = $operation->intent['restoration_context'] ?? ['status' => 'ACTIVE', 'collection_method' => 'OUT_OF_BAND'];
            $indefinite = $this->indefiniteBounds($payload);
            $boundsConfirmed = $indefinite
                ? ($snapshot['ends_at'] ?? null) === null && ($snapshot['duration'] ?? null) === null && ($snapshot['remaining_duration'] ?? null) === null
                : is_string($snapshot['ends_at'] ?? null) && is_string($payload['ends_at'] ?? null) && Timestamp::compare($snapshot['ends_at'], $payload['ends_at']) === 0;
            if (! in_array($subscription->status, ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID'], true)
                || ($snapshot['collection_method'] ?? null) !== ($context['collection_method'] ?? null)
                || ($snapshot['payment_source_id'] ?? null) !== ($context['payment_source_id'] ?? null)
                || ($snapshot['cancel_at_period_end'] ?? null) !== false || ! $boundsConfirmed) {
                throw new EvidenceException('The original renewal intent is not yet canonically restored.');
            }
            $this->confirmScheduleProjection($subscription, ['scheduled_ends_at' => null, ...($indefinite ? ['ends_at' => null] : [])]);
        }
        if (! $controlsConfirmed && $operation->kind === 'subscription.cancel' && $subscription->status !== 'CANCELLED') {
            throw new EvidenceException('Immediate cancellation is not yet confirmed by the provider state.');
        }
        if (! $controlsConfirmed && array_key_exists('cancel_at_period_end', $payload) && $payload['cancel_at_period_end'] === true) {
            $snapshot = $subscription->snapshot;
            $ends = array_filter([$snapshot['ends_at'] ?? null, $snapshot['current_period_ends_at'] ?? null], fn ($end) => $end !== null);
            if (($snapshot['cancel_at_period_end'] ?? null) !== true || $ends === [] || ! is_string($snapshot['starts_at'] ?? null)) {
                throw new EvidenceException('Scheduled ending requires a confirmed flag and effective end.');
            }
            foreach ($ends as $end) {
                if (! is_string($end) || Timestamp::compare($snapshot['starts_at'], $end) >= 0) {
                    throw new EvidenceException('Scheduled ending has inconsistent provider bounds.');
                }
            }
        }
        if (! $controlsConfirmed && array_key_exists('cancel_at_period_end', $payload) && $payload['cancel_at_period_end'] === false && ($subscription->snapshot['cancel_at_period_end'] ?? null) !== false) {
            throw new EvidenceException('The requested schedule flag is not yet confirmed; no resume is inferred.');
        }
        if (! $controlsConfirmed && array_key_exists('payment_source_id', $payload) && ($subscription->snapshot['payment_source_id'] ?? null) !== $payload['payment_source_id']) {
            throw new EvidenceException('The intended source replacement is not yet confirmed.');
        }
        if (! $controlsConfirmed && array_key_exists('collection_method', $payload) && ($subscription->snapshot['collection_method'] ?? null) !== $payload['collection_method']) {
            throw new EvidenceException('The intended collection method is not yet confirmed.');
        }

        if (! $this->itemEffectConfirmed($operation, $subscription)) {
            $this->operations->acknowledgeItemEffect($operation);

            return false;
        }

        return true;
    }

    /** @param array<string,?string> $attributes */
    private function confirmScheduleProjection(Subscription $subscription, array $attributes): void
    {
        DB::transaction(function () use ($subscription, $attributes): void {
            app(CommitGuard::class)->assertMayCommit();
            $query = ModelRegistry::query('subscription')->whereKey($subscription->id)->where('revision', $subscription->revision);
            $query->lockForUpdate();
            $current = (clone $query)->first();
            if ($current === null) {
                throw new ConflictException('Subscription changed during scheduled-end confirmation.');
            }
            $unchanged = true;
            foreach ($attributes as $key => $value) {
                $unchanged = $unchanged && $current->getAttribute($key) === $value;
            }
            // MySQL counts changed rows: an already-projected cap or repeated null
            // clear is successful only after the locked revision has been verified.
            if (! $unchanged && $query->update($attributes) !== 1) {
                throw new ConflictException('Subscription changed during scheduled-end confirmation.');
            }
            $subscription->forceFill($attributes);
        });
    }

    private function assertRestorationDispatch(Subscription $current, Operation $operation): void
    {
        $this->context->assertModel($current);
        $payload = $operation->intent['payload'];
        $context = $operation->intent['restoration_context'] ?? ['status' => 'ACTIVE', 'collection_method' => 'OUT_OF_BAND'];
        $now = now()->format('Y-m-d\TH:i:s.uP');
        if ($current->status !== ($context['status'] ?? null)
            || ($current->snapshot['collection_method'] ?? null) !== ($context['collection_method'] ?? null)
            || ($current->snapshot['payment_source_id'] ?? null) !== ($context['payment_source_id'] ?? null)
            || (isset($context['dated_ending']) ? ! $this->datedEndingMatches($current, $context['dated_ending']) : $current->cancel_at_period_end !== true)
            || ! is_string($current->scheduled_ends_at) || Timestamp::compare($current->scheduled_ends_at, $now) <= 0
            || (! $this->indefiniteBounds($payload) && (! is_string($payload['ends_at'] ?? null) || Timestamp::compare($payload['ends_at'], $now) <= 0))) {
            throw new EvidenceException('The pending cancellation boundary is no longer eligible for a first restoration write.');
        }
    }

    private function datedEndingMatches(Subscription $subscription, string $end): bool
    {
        return $subscription->cancel_at_period_end === false
            && is_string($subscription->snapshot['ends_at'] ?? null)
            && Timestamp::compare($subscription->snapshot['ends_at'], $end) === 0
            && is_string($subscription->scheduled_ends_at)
            && Timestamp::compare($subscription->scheduled_ends_at, $end) === 0;
    }

    private function renewalBounds(Subscription $subscription, ?array $bounds): ?array
    {
        $observed = $subscription->snapshot;
        if ($bounds === null) {
            return is_string($observed['ends_at'] ?? null) ? ['ends_at' => $observed['ends_at']] : null;
        }
        if (count($bounds) === 1 && is_string($bounds['ends_at'] ?? null)
            && is_string($observed['ends_at'] ?? null) && Timestamp::compare($bounds['ends_at'], $observed['ends_at']) === 0) {
            return $bounds;
        }
        if (count($bounds) !== 3 || ! $this->indefiniteBounds($bounds) || $subscription->ends_at !== null
            || ($observed['duration'] ?? null) !== null || ($observed['remaining_duration'] ?? null) !== null) {
            throw new EvidenceException('Original renewal intent contradicts the canonical bounds or has an unsupported shape.');
        }

        return $bounds;
    }

    private function indefiniteBounds(array $bounds): bool
    {
        foreach (['ends_at', 'duration', 'remaining_duration'] as $key) {
            if (! array_key_exists($key, $bounds) || $bounds[$key] !== null) {
                return false;
            }
        }

        return true;
    }

    private function supportedRestoration(array $original, array $bounds): bool
    {
        $finite = count($bounds) === 1 && is_string($bounds['ends_at'] ?? null);
        if (($original['collection_method'] ?? null) === 'OUT_OF_BAND') {
            return $finite && ($original['status'] ?? null) === 'ACTIVE' && ! isset($original['payment_source_id']);
        }

        return ($original['collection_method'] ?? null) === 'AUTO_CHARGE'
            && is_string($original['payment_source_id'] ?? null) && $original['payment_source_id'] !== ''
            && (($finite && ($original['status'] ?? null) === 'ACTIVE')
                || ($this->indefiniteBounds($bounds) && in_array($original['status'] ?? null, ['PENDING', 'IN_TRIAL', 'ACTIVE', 'UNPAID'], true)));
    }

    private function itemEffectConfirmed(Operation $operation, Subscription $subscription): bool
    {
        if (! in_array($operation->kind, ['subscription.quantity', 'subscription.swap', 'subscription.add_item', 'subscription.remove_item', 'subscription.items'], true)) {
            return true;
        }
        $items = $subscription->items()->get();
        $priorIds = $operation->intent['prior_item_ids'] ?? null;
        $matchedNewIds = [];
        foreach ($operation->intent['payload']['items'] ?? [] as $requested) {
            if (! is_array($requested)) {
                return false;
            }
            if (is_string($requested['id'] ?? null)) {
                $item = $items->first(fn (SubscriptionItem $candidate) => $candidate->remote_id === $requested['id']);
                if (($requested['deleted'] ?? false) === true) {
                    if ($item) {
                        return false;
                    }

                    continue;
                }
                if (! $item || ! is_int($requested['quantity'] ?? null) || ! Quantity::equalsInteger($item->snapshot['quantity'] ?? null, $requested['quantity'])) {
                    return false;
                }

                continue;
            }
            if (! is_array($priorIds) || ! is_string($requested['price_id'] ?? null) || ! is_int($requested['quantity'] ?? null)) {
                return false;
            }
            // New references have no provider command correlation. Overlapping
            // unresolved item actions cannot share one new reference as proof.
            // Keep both acknowledgements unconfirmed without inferring causality.
            if (ModelRegistry::query('operation')->where('subscription_id', $subscription->id)
                ->whereKeyNot($operation->id)
                ->whereIn('kind', ['subscription.quantity', 'subscription.swap', 'subscription.add_item', 'subscription.remove_item', 'subscription.items'])
                ->whereIn('status', ['prepared', 'in_flight', 'ambiguous', 'acknowledged'])->exists()) {
                return false;
            }
            $item = $items->first(fn (SubscriptionItem $candidate) => ! in_array($candidate->remote_id, $priorIds, true)
                && ! in_array($candidate->remote_id, $matchedNewIds, true)
                && ($candidate->snapshot['price']['id'] ?? null) === $requested['price_id']
                && Quantity::equalsInteger($candidate->snapshot['quantity'] ?? null, $requested['quantity']));
            if (! $item) {
                return false;
            }
            $matchedNewIds[] = $item->remote_id;
        }

        return true;
    }

    private function recoverChange(Subscription $subscription, Operation $operation): Subscription
    {
        if ($operation->status === 'failed') {
            throw new ConflictException('Rejected financial action requires a new action key.', $operation->id);
        }
        if ($operation->status === 'succeeded') {
            if ($operation->remote_id !== $subscription->remote_id) {
                throw new ConflictException('Completed financial action has a different resource identity.', $operation->id);
            }

            return $subscription;
        }
        if (! in_array($operation->status, ['in_flight', 'ambiguous', 'acknowledged'], true)) {
            throw new ConflictException('Financial operation has no recoverable attempt.', $operation->id);
        }
        try {
            $result = $this->reconcileChange($subscription, $operation);
            if ($operation->remote_id === null || $operation->remote_id !== $subscription->remote_id) {
                throw new AmbiguousOperationException($operation->id);
            }
            Routing::assertSubscription([...($operation->observed_routing ?? []), ...$result->snapshot]);
            Routing::assertSubscription([...$result->snapshot, ...($result->observed_routing ?? [])]);
            if (in_array($operation->kind, ['subscription.restore_renewal', 'subscription.schedule_ending_at'], true) || $this->outcome($operation, $result)) {
                $this->operations->succeed($operation);
            }

            return $result;
        } catch (Throwable $exception) {
            throw new AmbiguousOperationException($operation->id, $exception);
        }
    }

    private function reconcileChange(Subscription $subscription, Operation $operation): Subscription
    {
        $confirmed = false;
        $confirm = in_array($operation->kind, ['subscription.restore_renewal', 'subscription.schedule_ending_at'], true) && $operation->remote_id === $subscription->remote_id
            ? function (Subscription $result) use ($operation, &$confirmed): void {
                Routing::assertSubscription([...($operation->observed_routing ?? []), ...$result->snapshot]);
                Routing::assertSubscription([...$result->snapshot, ...($result->observed_routing ?? [])]);
                $this->outcome($operation, $result);
                $confirmed = true;
            } : null;

        $result = $this->synchronizer->reconcile($subscription, financial: true, afterProjection: $confirm);
        if ($confirm !== null && ! $confirmed) {
            throw new EvidenceException('A concurrent observation superseded scheduled-end confirmation.');
        }

        return $result;
    }

    private function existing(Customer $customer, string $actionKey, string $kind, array $specification, ?Subscription $subscription = null, ?string $name = null): ?Operation
    {
        if ($actionKey === '' || strlen($actionKey) > 255) {
            throw new ConflictException('Action key must be a nonempty bounded string.');
        }
        $operation = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => $actionKey])->first();
        if ($operation && ! hash_equals($operation->intent_hash, hash('sha256', CanonicalJson::encode([$kind, $customer->getKey(), $subscription?->getKey(), $name, $specification])))) {
            throw new ConflictException('Action key was already used for a different intent.', $operation->id);
        }

        return $operation;
    }

    private function itemPayload(array $items, array $options): array
    {
        $this->managed($options, ['items', 'request_id', 'billing_customer_id', 'id']);
        $this->assertItemDiscountControls($items, $options);
        if (! is_string($options['default_proration_mode'] ?? null)) {
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['proration_mode'] ?? null)) {
                    throw new ConflictException('Every item change requires an explicit proration choice.');
                }
            }
        }

        return [...$options, 'items' => $items];
    }

    private function assertItemDiscountControls(#[SensitiveParameter] array $items, #[SensitiveParameter] array $options): void
    {
        if (array_key_exists('discounts', $options)) {
            throw new EvidenceException('Generic item changes do not establish discount outcomes; use a separately qualified discount command.');
        }
        foreach ($items as $item) {
            if (is_array($item) && array_key_exists('discounts', $item)) {
                throw new EvidenceException('Generic item changes do not establish discount outcomes; use a separately qualified discount command.');
            }
        }
    }

    private function mutableItems(Subscription $subscription): void
    {
        foreach ($subscription->items()->get() as $item) {
            if (! $item->canMutate()) {
                throw new EvidenceException('Observed item shape is unsupported for safe item mutations.');
            }
        }
    }

    private function item(Subscription $subscription, ?string $itemId): SubscriptionItem
    {
        $this->mutableItems($subscription);
        $items = $subscription->items()->get();
        if ($itemId === null && $items->count() !== 1) {
            throw new ConflictException('Select an explicit item for a multi-item subscription.');
        }
        $item = $itemId === null ? $items->first() : $items->first(fn (SubscriptionItem $candidate) => $candidate->remote_id === $itemId);
        if (! $item) {
            throw new OwnershipException('Item does not belong to the owned subscription.');
        }

        return $item;
    }

    private function nativeQuantity(SubscriptionItem $item): int
    {
        $value = $item->getAttribute('quantity');
        if (! is_string($value) || ! preg_match('/^([1-9][0-9]*)(?:\.0+)?$/D', $value, $match) || strlen($match[1]) > 10 || (strlen($match[1]) === 10 && strcmp($match[1], '2147483647') > 0)) {
            throw new EvidenceException('Observed quantity cannot be a native integer; provide an explicit replacement quantity.');
        }

        return (int) $match[1];
    }

    private function quantityType(mixed $quantity): void
    {
        if (! is_int($quantity)) {
            throw new ValidationException('Quantity must be a native integer.');
        }
    }

    private function fingerprint(Subscription $subscription): string
    {
        $items = [];
        foreach ($subscription->items()->get() as $item) {
            $items[$item->remote_id] = $item->snapshot;
        }
        ksort($items, SORT_STRING);

        return hash('sha256', CanonicalJson::encode([$subscription->snapshot, $items]));
    }

    private function discountTargetMatches(Operation $operation, Subscription $subscription): bool
    {
        if ($operation->kind !== 'checkout.subscription') {
            return $operation->remote_id === $subscription->remote_id;
        }

        return ModelRegistry::query('checkout')->where('operation_id', $operation->id)
            ->where('subscription_id', $subscription->id)->where('remote_subscription_id', $subscription->remote_id)->exists();
    }

    private function recordDiscountProof(Operation $operation, Subscription $subscription, array $response): void
    {
        app(DiscountProof::class)->record($operation, $subscription, $response, $this->discountEvidence($operation->kind));
    }

    private function discountEvidence(string $kind): FreePeriodEvidence
    {
        return app(match ($kind) {
            'subscription.first_charge_discount' => FirstChargeDiscountEvidence::class,
            'subscription.coupon', 'subscription.create', 'checkout.subscription' => CouponEvidence::class,
            default => FreePeriodEvidence::class,
        });
    }

    private function preflight(Operation $operation, Closure $check): void
    {
        try {
            $check();
        } catch (Throwable $exception) {
            try {
                DB::transaction(function () use ($operation): void {
                    app(CommitGuard::class)->assertMayCommit();
                    $changed = ModelRegistry::query('operation')->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'failed', 'lock_key' => null, 'error_code' => 'preflight_rejected']);
                    if ($changed !== 1) {
                        throw new AmbiguousOperationException($operation->id);
                    }
                    ModelRegistry::query('subscription_slot')->where('operation_id', $operation->id)->whereNull('subscription_id')->delete();
                });
            } catch (Throwable) {
                throw new AmbiguousOperationException($operation->id);
            }
            if ($exception instanceof BillingException || $exception instanceof SdkException) {
                throw $exception;
            }
            throw new EvidenceException('Financial action preflight could not be verified.');
        }
    }

    private function managed(array $options, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $options)) {
                throw new ConflictException('Managed financial identity or builder fields cannot be overridden.');
            }
        }
    }

    private function metadata(mixed $metadata, string $operationId): mixed
    {
        if ($metadata instanceof stdClass || (is_array($metadata) && ($metadata === [] || ! array_is_list($metadata)))) {
            $members = is_array($metadata) ? $metadata : get_object_vars($metadata);
            if (array_key_exists('_laravel_operation_id', $members)) {
                throw new ConflictException('Operation correlation metadata is reserved.');
            }

            $object = (object) $members;
            $object->_laravel_operation_id = $operationId;

            return $object;
        }

        return $metadata;
    }

    private function response(array $response, string $id, Customer $customer): void
    {
        Routing::assertSubscription($response);
        if (($response['id'] ?? null) !== $id || (array_key_exists('billing_customer_id', $response) && $response['billing_customer_id'] !== $customer->remote_id)) {
            throw new OwnershipException('Mutation response does not match the intended owned subscription.');
        }
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $response) && $response[$key] !== $expected) {
                throw new OwnershipException('Mutation response belongs to a foreign context.');
            }
        }
    }
}
