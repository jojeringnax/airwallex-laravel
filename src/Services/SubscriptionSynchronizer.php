<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\Pages;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;

class SubscriptionSynchronizer
{
    private const DATES = ['starts_at', 'ends_at', 'trial_starts_at', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'cancel_requested_at'];

    public function __construct(private readonly Client $client, private readonly BillingContext $context, private readonly OperationManager $operations, private readonly Outbox $outbox) {}

    public function reconcile(Subscription $subscription, bool $financial = false, ?Closure $afterProjection = null): Subscription
    {
        $this->context->assertModel($subscription);
        $before = ModelRegistry::query('subscription')->findOrFail($subscription->getKey());
        $this->context->assertModel($before);
        $customer = ModelRegistry::query('customer')->findOrFail($before->customer_id);

        return $this->refresh($customer, $before->name, $before->remote_id, $before, financial: $financial, afterProjection: $afterProjection);
    }

    public function import(Customer $customer, string $name, string $remoteId): Subscription
    {
        return $this->refresh($customer, $name, $remoteId, $this->existing($customer, $name, $remoteId));
    }

    /** Confirm a known result of the already-recorded creation/checkout intent. */
    public function confirm(Customer $customer, string $name, string $remoteId, Operation $operation): Subscription
    {
        $operation = $this->creationOperation($customer, $name, $remoteId, $operation);

        return $this->refresh($customer, $name, $remoteId, $this->existing($customer, $name, $remoteId), $operation);
    }

    private function creationOperation(Customer $customer, string $name, string $remoteId, Operation $operation): Operation
    {
        $operation = ModelRegistry::query('operation')->findOrFail($operation->getKey());
        $this->context->assertModel($operation);
        if (! in_array($operation->kind, ['subscription.create', 'checkout.subscription'], true)) {
            throw new ConflictException('Operation is not a subscription creation intent.');
        }
        if (! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Subscription mutation has not been sent.');
        }
        if ($operation->kind === 'subscription.create' && $operation->remote_id !== $remoteId) {
            throw new OwnershipException('Subscription result does not match the durable operation identity.');
        }
        if ($operation->kind === 'checkout.subscription') {
            $checkout = ModelRegistry::query('checkout')->where($this->context->attributes())->where('operation_id', $operation->id)->first();
            if (! $checkout || $checkout->getAttribute('remote_id') !== $operation->remote_id || $checkout->getAttribute('remote_subscription_id') !== $remoteId || $checkout->getAttribute('status') !== 'COMPLETED' || $checkout->getAttribute('customer_id') !== $customer->id) {
                throw new OwnershipException('Subscription result has no confirmed checkout correlation.');
            }
            Routing::assertCheckout($checkout->snapshot ?? []);
        }
        if ($operation->customer_id !== $customer->id) {
            throw new OwnershipException('Subscription operation belongs to a different customer.');
        }

        if (isset($operation->intent['name']) && $operation->intent['name'] !== $name) {
            throw new OwnershipException('Subscription name does not match its durable intent.');
        }
        if ($operation->subscription_id !== null) {
            $result = ModelRegistry::query('subscription')->findOrFail($operation->subscription_id);
            if ($result->remote_id !== $remoteId || $result->customer_id !== $customer->id || $result->name !== $name) {
                throw new OwnershipException('Creation intent already has a different confirmed subscription result.');
            }
        }
        Routing::defaults($operation->intent['payload'] ?? []);

        return $operation;
    }

    private function existing(Customer $customer, string $name, string $remoteId): ?Subscription
    {
        $this->context->assertModel($customer);
        $existing = ModelRegistry::query('subscription')->where($this->context->attributes())->where('remote_id', $remoteId)->first();
        if ($existing && ($existing->customer_id !== $customer->id || $existing->name !== $name)) {
            throw new ConflictException('Remote subscription is already associated with a different parent or name.');
        }

        return $existing;
    }

    private function refresh(Customer $customer, string $name, string $remoteId, ?Subscription $before, ?Operation $operation = null, bool $financial = false, ?Closure $afterProjection = null): Subscription
    {
        $this->context->assertModel($customer);
        if (! $customer->exists) {
            throw new ConflictException('A persisted customer mapping is required.');
        }
        $customer = ModelRegistry::query('customer')->findOrFail($customer->getKey());
        $this->context->assertModel($customer);
        if ($name === '' || strlen($name) > 255 || $customer->remote_id === null) {
            throw new ConflictException('A confirmed customer and explicit subscription name are required.');
        }
        $remote = SdkResponseShape::normalize($this->client->billing()->subscriptions()->retrieve($remoteId));
        $this->verify($remote, $remoteId, $customer);
        if ($operation !== null || $financial) {
            Routing::assertSubscription([...($operation->observed_routing ?? []), ...$remote]);
        }
        $items = Pages::all(fn (array $query) => $this->client->billing()->subscriptions()->listItems($remoteId, $query));
        foreach ($items as $item) {
            if (($item['subscription_id'] ?? null) !== $remoteId) {
                throw new OwnershipException('Subscription item belongs to a different subscription.');
            }
        }
        // The provider supplies no transactional list snapshot. Detect a parent
        // change while paging and retain the previous complete local projection.
        $after = SdkResponseShape::normalize($this->client->billing()->subscriptions()->retrieve($remoteId));
        $this->verify($after, $remoteId, $customer);
        if ($operation !== null || $financial) {
            Routing::assertSubscription([...($operation->observed_routing ?? []), ...$after]);
        }
        if (! $this->sameCore($remote, $after) || Timestamp::compare($remote['updated_at'], $after['updated_at']) !== 0) {
            throw new EvidenceException('Subscription changed during pagination; reconcile again.');
        }
        if ($operation?->kind === 'subscription.create') {
            $payload = $operation->intent['payload'] ?? [];
            foreach (['collection_method', 'payment_source_id'] as $key) {
                if (array_key_exists($key, $payload) && ($after[$key] ?? null) !== $payload[$key]) {
                    throw new EvidenceException('Canonical creation result does not confirm its explicit collection controls.');
                }
            }
        }
        if ($operation?->kind === 'checkout.subscription' && ($after['collection_method'] ?? null) === 'AUTO_CHARGE') {
            if (! is_string($after['payment_source_id'] ?? null) || $after['payment_source_id'] === '') {
                throw new EvidenceException('Hosted subscription has no confirmed payment source.');
            }
            app(SourceManager::class)->retrieve($customer, $after['payment_source_id']);
        }
        if ($operation !== null && isset($operation->intent['coupon_terms'])
            && ($operation->kind !== 'checkout.subscription' || $operation->subscription_id === null || ! is_array($operation->observation['discount_proof'] ?? null))) {
            $evidence = app(CouponEvidence::class);
            $pending = ModelRegistry::make('subscription', [...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $remoteId]);
            $firstProof = $evidence->proof($pending, $remote, $operation->intent['coupon_terms']);
            $lastProof = $evidence->proof($pending, $after, $operation->intent['coupon_terms']);
            if (! $evidence->sameProof($firstProof, $lastProof)) {
                throw new EvidenceException('The original Coupon Discount changed during canonical reads.');
            }
            foreach ($items as $item) {
                if (($item['applied_discounts'] ?? null) !== []) {
                    throw new EvidenceException('Managed Coupon creation cannot stack with item-level discounts.');
                }
            }
            if ($operation->kind === 'checkout.subscription') {
                // Checkout returns the Subscription only after hosted completion. Preserve
                // its first complete canonical Discount identity before local association.
                app(DiscountProof::class)->record($operation, $pending, $after, $evidence);
            } elseif (! is_array($operation->observation['discount_proof'] ?? null)
                || ! $evidence->sameProof($lastProof, $operation->observation['discount_proof'])) {
                throw new EvidenceException('Canonical Coupon matching cannot replace the original direct-create Discount response.');
            }
        }
        try {
            return DB::transaction(function () use ($customer, $name, $remoteId, $before, $operation, $remote, $after, $items, $afterProjection): Subscription {
                app(CommitGuard::class)->assertMayCommit();
                ModelRegistry::query('customer')->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if ($operation !== null) {
                    $operation = $this->creationOperation($customer, $name, $remoteId, $operation);
                }
                $subscription = $this->existing($customer, $name, $remoteId);
                if ($before !== null && ($subscription === null || $subscription->id !== $before->id)) {
                    throw new ConflictException('The known subscription mapping was removed during reconciliation.');
                }
                $new = $subscription === null;
                $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $customer->id)->where('name', $name)->first();
                $terminal = $after['status'] === 'CANCELLED';
                if (! $terminal && $new && $operation && ! $slot) {
                    throw new ConflictException('Creation intent has no matching name reservation.');
                }
                if (! $terminal && $new && $slot && ($operation === null || $slot->operation_id !== $operation->id)) {
                    throw new ConflictException('Subscription name is already reserved.');
                }
                if ($operation && $slot && $slot->operation_id !== $operation->id && $slot->subscription_id !== $subscription?->id) {
                    throw new ConflictException('Subscription result conflicts with its reserved name.');
                }
                if ($subscription !== null && $subscription->provider_updated_at !== null) {
                    $order = Timestamp::compare($after['updated_at'], $subscription->provider_updated_at);
                    if ($order < 0 || ($order === 0 && ($before === null || $before->revision !== $subscription->revision))) {
                        return $subscription;
                    }
                    if ($subscription->status === 'CANCELLED' && ! $terminal) {
                        throw new EvidenceException('A cancelled subscription cannot be resurrected by an observation.');
                    }
                    if ($order === 0 && ! $this->sameCore($subscription->snapshot, $after)) {
                        throw new EvidenceException('Equal provider versions contain conflicting lifecycle data.');
                    }
                    if ($subscription->provider_created_at !== null && Timestamp::compare($subscription->provider_created_at, $after['created_at']) !== 0) {
                        throw new EvidenceException('Provider creation identity changed.');
                    }
                }
                $subscription ??= ModelRegistry::make('subscription', [...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $remoteId, 'name' => $name]);
                $previousStatus = $subscription->status;
                $restorationPending = $subscription->exists && ModelRegistry::query('operation')->where('subscription_id', $subscription->id)
                    ->where('kind', 'subscription.restore_renewal')->whereIn('status', ['prepared', 'in_flight', 'ambiguous'])->exists();
                $latestSchedule = $subscription->exists ? ModelRegistry::query('operation')->where('subscription_id', $subscription->id)
                    ->whereIn('kind', ['subscription.schedule_ending', 'subscription.schedule_ending_at', 'subscription.restore_renewal'])
                    ->whereIn('status', ['in_flight', 'ambiguous', 'succeeded'])->orderByDesc('id')->first() : null;
                $datedEnding = $latestSchedule?->kind === 'subscription.schedule_ending_at';
                $scheduleEnd = (($subscription->cancel_at_period_end === true && $after['cancel_at_period_end']) || $restorationPending || $datedEnding) ? $subscription->scheduled_ends_at : null;
                if ($datedEnding && is_string($after['ends_at'] ?? null)
                    && Timestamp::compare($after['ends_at'], $latestSchedule->intent['payload']['ends_at']) === 0) {
                    $scheduleEnd = $scheduleEnd ?? $after['ends_at'];
                }
                if ($after['cancel_at_period_end']) {
                    foreach (['ends_at', 'current_period_ends_at'] as $key) {
                        if (is_string($after[$key] ?? null) && ($scheduleEnd === null || Timestamp::compare($after[$key], $scheduleEnd) < 0)) {
                            $scheduleEnd = $after[$key];
                        }
                    }
                }
                $oldItems = $subscription->exists ? $subscription->items()->get()->keyBy('remote_id') : collect();
                foreach ($items as $item) {
                    $old = $oldItems->get($item['id']);
                    if ($old && $old->provider_updated_at !== null && self::itemTime($item['updated_at'] ?? null) !== null) {
                        $order = Timestamp::compare($item['updated_at'], $old->provider_updated_at);
                        if ($order < 0) {
                            throw new EvidenceException('Item observation is older than the local projection.');
                        }
                        if ($order === 0 && self::itemCore($item) !== self::itemCore($old->snapshot)) {
                            throw new EvidenceException('Equal item versions contain conflicting mutable data.');
                        }
                    }
                }
                $routing = array_filter(array_intersect_key([...$remote, ...$after], array_flip(['legal_entity_id', 'linked_payment_account_id'])), fn ($value) => is_string($value) && $value !== '');
                $subscription->observed_routing = [...($subscription->observed_routing ?? array_filter(array_intersect_key($subscription->snapshot ?? [], array_flip(['legal_entity_id', 'linked_payment_account_id'])), fn ($value) => is_string($value) && $value !== '')), ...$routing];
                $snapshot = self::sanitize($after);
                $changed = ! $subscription->exists || CanonicalJson::encode($subscription->snapshot) !== CanonicalJson::encode($snapshot);
                $subscription->fill(['status' => $after['status'], 'cancel_at_period_end' => $after['cancel_at_period_end'], 'scheduled_ends_at' => $scheduleEnd, 'provider_created_at' => $after['created_at'], 'provider_updated_at' => $after['updated_at'], 'snapshot' => $snapshot, 'synced_at' => now(), 'items_synced_at' => now(), 'revision' => ($subscription->revision ?? 0) + 1]);
                foreach (self::DATES as $date) {
                    if (isset($after[$date]) || $new) {
                        $subscription->setAttribute($date, $after[$date] ?? null);
                    }
                }
                $subscription->save();
                if ($operation !== null && $operation->subscription_id === null) {
                    $operation->subscription_id = $subscription->id;
                    $operation->save();
                }
                $remoteItems = [];
                foreach ($items as $item) {
                    $remoteItems[] = $item['id'];
                    $data = self::sanitize($item);
                    $old = $oldItems->get($item['id']);
                    $changed = $changed || ! $old || CanonicalJson::encode($old->snapshot) !== CanonicalJson::encode($data);
                    $local = $old ?? ModelRegistry::make('subscription_item', ['subscription_id' => $subscription->id, 'remote_id' => $item['id']]);
                    $local->fill(['snapshot' => $data, 'price_id' => is_array($item['price'] ?? null) && is_string($item['price']['id'] ?? null) ? $item['price']['id'] : null, 'quantity' => is_string($item['quantity'] ?? null) ? $item['quantity'] : null, 'provider_created_at' => self::itemTime($item['created_at'] ?? null), 'provider_updated_at' => self::itemTime($item['updated_at'] ?? null)]);
                    $local->save();
                }
                $removed = $subscription->items()->whereNotIn('remote_id', $remoteItems)->delete();
                $changed = $changed || $removed > 0;
                if ($terminal) {
                    $this->operations->releaseTerminated($subscription);
                    if ($operation) {
                        ModelRegistry::query('subscription_slot')->where('operation_id', $operation->id)->whereNull('subscription_id')->delete();
                    }
                } elseif ($new || ($operation && $slot?->operation_id === $operation->id)) {
                    $slot ??= ModelRegistry::make('subscription_slot', ['customer_id' => $customer->id, 'name' => $name]);
                    $slot->fill(['subscription_id' => $subscription->id, 'operation_id' => null])->save();
                }
                if ($afterProjection !== null) {
                    $afterProjection($subscription);
                    $changed = true;
                }
                if ($changed) {
                    $this->outbox->record('SubscriptionReconciled', $subscription, ['remote_id' => $remoteId, 'previous_status' => $previousStatus, 'status' => $subscription->status]);
                }

                return $subscription;
            });
        } catch (QueryException) {
            throw new ConflictException('A concurrent or invalid subscription projection must be reconciled again.');
        }
    }

    /** @param array<array-key,mixed> $remote */
    private function verify(array $remote, string $id, Customer $customer): void
    {
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $field => $expected) {
            if (array_key_exists($field, $remote) && $remote[$field] !== $expected) {
                throw new OwnershipException('Subscription response conflicts with the configured context.');
            }
        }
        if (($remote['id'] ?? null) !== $id || ($remote['billing_customer_id'] ?? null) !== $customer->remote_id) {
            throw new OwnershipException('Subscription identity or customer does not match its mapping.');
        }
        if (! is_string($remote['status'] ?? null) || $remote['status'] === '' || ! is_bool($remote['cancel_at_period_end'] ?? null)) {
            throw new EvidenceException('Subscription lifecycle data is malformed.');
        }
        foreach (['created_at', 'updated_at', 'starts_at'] as $field) {
            if (! is_string($remote[$field] ?? null)) {
                throw new EvidenceException('Required provider timing is missing.');
            }
        }
        foreach ([...self::DATES, 'created_at', 'updated_at'] as $date) {
            if (isset($remote[$date])) {
                if (! is_string($remote[$date])) {
                    throw new EvidenceException('Provider timing is malformed.');
                }
                Timestamp::compare($remote[$date], $remote[$date]);
            }
        }
    }

    private function sameCore(array $left, array $right): bool
    {
        foreach (['id', 'billing_customer_id', 'status', 'collection_method', 'payment_source_id', 'cancel_at_period_end', 'duration', 'remaining_duration', 'applied_discounts', 'legal_entity_id', 'linked_payment_account_id', 'created_at', ...self::DATES] as $key) {
            if (in_array($key, ['legal_entity_id', 'linked_payment_account_id'], true) && (! array_key_exists($key, $left) || ! array_key_exists($key, $right))) {
                continue;
            }
            if ($key === 'applied_discounts') {
                if (CanonicalJson::encode($left[$key] ?? null) !== CanonicalJson::encode($right[$key] ?? null)) {
                    return false;
                }
            } elseif (in_array($key, [...self::DATES, 'created_at'], true) && is_string($left[$key] ?? null) && is_string($right[$key] ?? null)) {
                if (Timestamp::compare($left[$key], $right[$key]) !== 0) {
                    return false;
                }
            } elseif (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function itemCore(array $item): string
    {
        return CanonicalJson::encode(['quantity' => $item['quantity'] ?? null, 'price_id' => is_array($item['price'] ?? null) ? ($item['price']['id'] ?? null) : null, 'metadata' => $item['metadata'] ?? null, 'rate_card_version' => $item['rate_card_version'] ?? null]);
    }

    private static function itemTime(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            Timestamp::compare($value, $value);

            return $value;
        } catch (EvidenceException) {
            return null;
        }
    }

    /** @internal Remove credential-like fields before durable snapshots. */
    public static function sanitize(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_string($key) && in_array(strtolower($key), ['client_secret', 'api_key', 'access_token', 'token', 'webhook_secret', 'authorization'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $value = self::sanitize($value);
            } elseif ($value instanceof \stdClass) {
                $value = (object) self::sanitize(get_object_vars($value));
            }
        }

        return $data;
    }
}
