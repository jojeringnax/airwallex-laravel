<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Closure;
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
use Jojeringnax\AirwallexLaravel\Timestamp;
use stdClass;
use Throwable;

class SubscriptionManager
{
    private const CHANGE_KINDS = ['subscription.quantity', 'subscription.swap', 'subscription.add_item', 'subscription.remove_item', 'subscription.items', 'subscription.schedule_ending', 'subscription.restore_renewal', 'subscription.cancel', 'subscription.payment_source'];

    public function __construct(private readonly BillingContext $context, private readonly OperationManager $operations, private readonly SubscriptionSynchronizer $synchronizer, private readonly CustomerManager $customers) {}

    public function create(SubscriptionBuilder $builder, string $actionKey, array $options = []): Subscription
    {
        $customer = $this->customers->requireCustomer($builder->billable());
        $this->managed($options, ['request_id', 'billing_customer_id', 'items', 'starts_at', 'trial_ends_at']);
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
        $operation = $this->operations->prepare($customer, $actionKey, 'subscription.create', $specification, ['name' => $builder->name(), 'payload' => $payload], name: $builder->name());

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

    public function cancelAtPeriodEnd(Subscription $subscription, string $actionKey): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.schedule_ending', [], fn () => ['cancel_at_period_end' => true]);
    }

    public function restoreRenewal(Subscription $subscription, string $actionKey, string $scheduleActionKey): Subscription
    {
        return $this->change($subscription, $actionKey, 'subscription.restore_renewal', ['schedule_action_key' => $scheduleActionKey], function (Subscription $current) use ($scheduleActionKey): array {
            $schedule = ModelRegistry::query('operation')->where([
                ...$this->context->attributes(), 'subscription_id' => $current->id,
                'action_key' => $scheduleActionKey, 'kind' => 'subscription.schedule_ending', 'status' => 'succeeded',
            ])->first();
            $original = $schedule?->intent['original_bounds'] ?? null;
            if (! is_array($original) || $schedule->remote_id !== $current->remote_id
                || ($original['status'] ?? null) !== 'ACTIVE' || ($original['collection_method'] ?? null) !== 'OUT_OF_BAND'
                || ($original['cancel_at_period_end'] ?? null) !== false || isset($original['payment_source_id'])
                || $current->status !== 'ACTIVE' || ($current->snapshot['collection_method'] ?? null) !== 'OUT_OF_BAND'
                || isset($current->snapshot['payment_source_id']) || $current->cancel_at_period_end !== true
                || ! is_string($original['ends_at'] ?? null)
                || ! is_string($current->scheduled_ends_at)
                || Timestamp::compare($current->scheduled_ends_at, now()->format('Y-m-d\TH:i:s.uP')) <= 0
                || Timestamp::compare($original['ends_at'], now()->format('Y-m-d\TH:i:s.uP')) <= 0) {
                throw new EvidenceException('Renewal restoration requires an owned saved finite ACTIVE OUT_OF_BAND schedule.');
            }
            $latest = ModelRegistry::query('operation')->where('subscription_id', $current->id)
                ->where('kind', 'subscription.schedule_ending')->where('status', '!=', 'failed')->orderByDesc('id')->first();
            $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $current->customer_id)->where('name', $current->name)->first();
            if ($latest?->id !== $schedule->id || $slot?->subscription_id !== $current->id) {
                throw new ConflictException('The cancellation action is not the current owned subscription generation.');
            }

            return ['cancel_at_period_end' => false, 'ends_at' => $original['ends_at']];
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
            $remote = app(Client::class)->billing()->subscriptions()->retrieve($candidateId);
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
        });

        return $this->operations->execute($operation, function () use ($operation): array {
            $payload = $operation->intent['payload'];
            $payload['metadata'] = $this->metadata(array_key_exists('metadata', $payload) ? $payload['metadata'] : new stdClass, $operation->id);

            return app(Client::class)->billing()->subscriptions()->create([...$payload, 'request_id' => $operation->request_id]);
        }, function (array $response) use ($customer, $name, $operation): Subscription {
            $this->response($response, $response['id'], $customer);

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

        return $this->synchronizer->confirm($customer, $name, $id, $operation);
    }

    private function change(Subscription $subscription, string $actionKey, string $kind, array $specification, Closure $build): Subscription
    {
        $this->context->assertModel($subscription);
        $subscription = ModelRegistry::query('subscription')->findOrFail($subscription->getKey());
        $this->context->assertModel($subscription);
        $customer = ModelRegistry::query('customer')->findOrFail($subscription->customer_id);
        $this->context->assertModel($customer);
        $operation = $this->existing($customer, $actionKey, $kind, $specification, $subscription);
        $resumedRestoration = $operation?->status === 'prepared' && $kind === 'subscription.restore_renewal';
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
            if ($kind === 'subscription.schedule_ending') {
                $intent['original_bounds'] = array_intersect_key($subscription->snapshot, array_flip(['status', 'collection_method', 'cancel_at_period_end', 'starts_at', 'ends_at', 'duration', 'remaining_duration', 'payment_source_id', 'current_period_starts_at', 'current_period_ends_at']));
            }
            $operation = $this->operations->prepare($customer, $actionKey, $kind, $specification, $intent, $subscription);
        }
        if ($operation->status !== 'prepared') {
            return $this->recoverChange($subscription, $operation);
        }
        $this->preflight($operation, function () use ($subscription, $customer, $operation, $resumedRestoration): void {
            $current = ModelRegistry::query('subscription')->findOrFail($subscription->id);
            if ($operation->kind === 'subscription.restore_renewal') {
                $this->assertRestorationDispatch($current, $operation->intent['payload']);
                if ($resumedRestoration) {
                    $current = $this->synchronizer->reconcile($current, financial: true);
                    $this->assertRestorationDispatch($current, $operation->intent['payload']);
                }
            }
            Routing::assertSubscription([...$current->snapshot, ...($current->observed_routing ?? [])]);
            if ($this->fingerprint($current) !== $operation->intent['fingerprint']) {
                throw new EvidenceException('Subscription changed before the financial action; prepare a new intent.');
            }
            $payload = $operation->intent['payload'];
            if ((array_key_exists('payment_source_id', $payload) && ($payload['payment_source_id'] === null || $payload['payment_source_id'] === ''))
                || (array_key_exists('cancel_at_period_end', $payload) && $payload['cancel_at_period_end'] === null)) {
                throw new EvidenceException('Unverified source removal or schedule clearing requires explicit SDK use.');
            }
            $collection = array_key_exists('collection_method', $payload) ? $payload['collection_method'] : ($current->snapshot['collection_method'] ?? null);
            $sourceId = array_key_exists('payment_source_id', $payload) ? $payload['payment_source_id'] : ($current->snapshot['payment_source_id'] ?? null);
            if (isset($payload['items']) && ! in_array($collection, ['AUTO_CHARGE', 'CHARGE_ON_CHECKOUT', 'OUT_OF_BAND'], true)) {
                throw new EvidenceException('Item mutation requires verified effective collection semantics.');
            }
            if (isset($payload['items']) && $collection === 'AUTO_CHARGE') {
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
        });

        return $this->operations->execute($operation, function () use ($subscription, $operation): array {
            if ($operation->kind === 'subscription.restore_renewal') {
                $this->assertRestorationDispatch(ModelRegistry::query('subscription')->findOrFail($subscription->id), $operation->intent['payload']);
            }
            $payload = [...$operation->intent['payload'], 'request_id' => $operation->request_id];
            $resource = app(Client::class)->billing()->subscriptions();

            return $operation->kind === 'subscription.cancel' ? $resource->cancel($subscription->remote_id, $payload) : $resource->update($subscription->remote_id, $payload);
        }, function (array $response) use ($subscription, $customer, $operation): Subscription {
            $this->response($response, $subscription->remote_id, $customer);
            $result = $this->synchronizer->reconcile($subscription, financial: true);
            Routing::assertSubscription([...($operation->observed_routing ?? []), ...$result->snapshot]);
            Routing::assertSubscription([...$result->snapshot, ...($result->observed_routing ?? [])]);
            $this->outcome($operation, $result);

            return $result;
        });
    }

    private function outcome(Operation $operation, Subscription $subscription): bool
    {
        $payload = $operation->intent['payload'];
        $controlsConfirmed = $operation->status === 'acknowledged';
        if ($operation->kind === 'subscription.restore_renewal') {
            $snapshot = $subscription->snapshot;
            if ($subscription->status !== 'ACTIVE' || ($snapshot['collection_method'] ?? null) !== 'OUT_OF_BAND'
                || isset($snapshot['payment_source_id']) || ($snapshot['cancel_at_period_end'] ?? null) !== false
                || ! is_string($snapshot['ends_at'] ?? null)
                || Timestamp::compare($snapshot['ends_at'], $payload['ends_at']) !== 0) {
                throw new EvidenceException('The original finite renewal bound is not yet canonically restored.');
            }
            DB::transaction(function () use ($subscription): void {
                app(CommitGuard::class)->assertMayCommit();
                $changed = ModelRegistry::query('subscription')->whereKey($subscription->id)->where('revision', $subscription->revision)
                    ->update(['scheduled_ends_at' => null]);
                if ($changed !== 1) {
                    throw new ConflictException('Subscription changed during restoration confirmation.');
                }
                $subscription->scheduled_ends_at = null;
            });
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

    private function assertRestorationDispatch(Subscription $current, array $payload): void
    {
        $this->context->assertModel($current);
        $now = now()->format('Y-m-d\TH:i:s.uP');
        if ($current->status !== 'ACTIVE' || ($current->snapshot['collection_method'] ?? null) !== 'OUT_OF_BAND'
            || isset($current->snapshot['payment_source_id']) || $current->cancel_at_period_end !== true
            || ! is_string($current->scheduled_ends_at) || Timestamp::compare($current->scheduled_ends_at, $now) <= 0
            || ! is_string($payload['ends_at'] ?? null) || Timestamp::compare($payload['ends_at'], $now) <= 0) {
            throw new EvidenceException('The pending cancellation boundary is no longer eligible for a first restoration write.');
        }
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
            $result = $this->synchronizer->reconcile($subscription, financial: true);
            if ($operation->remote_id === null || $operation->remote_id !== $subscription->remote_id) {
                throw new AmbiguousOperationException($operation->id);
            }
            Routing::assertSubscription([...($operation->observed_routing ?? []), ...$result->snapshot]);
            Routing::assertSubscription([...$result->snapshot, ...($result->observed_routing ?? [])]);
            if ($this->outcome($operation, $result)) {
                $this->operations->succeed($operation);
            }

            return $result;
        } catch (Throwable $exception) {
            throw new AmbiguousOperationException($operation->id, $exception);
        }
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
        if (! is_string($options['default_proration_mode'] ?? null)) {
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['proration_mode'] ?? null)) {
                    throw new ConflictException('Every item change requires an explicit proration choice.');
                }
            }
        }

        return [...$options, 'items' => $items];
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
