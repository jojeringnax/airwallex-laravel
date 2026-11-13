<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\SubscriptionBuilder;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;
use stdClass;
use Throwable;

class CheckoutManager
{
    private const MARKER = '_laravel_operation_id';

    public function __construct(private readonly Client $client, private readonly BillingContext $context, private readonly OperationManager $operations, private readonly CustomerManager $customers, private readonly SourceManager $sources, private readonly SubscriptionSynchronizer $subscriptions, private readonly Outbox $outbox) {}

    public function subscription(SubscriptionBuilder $builder, string $actionKey, array $options = []): Checkout
    {
        return $this->create($builder->billable(), $actionKey, 'SUBSCRIPTION', $builder->name(), $builder->definition(), $options, fn () => $builder->resolvePayload());
    }

    public function setup(Model $billable, string $actionKey, array $options = []): Checkout
    {
        return $this->create($billable, $actionKey, 'SETUP', null, [], $options, fn () => []);
    }

    private function create(Model $billable, string $actionKey, string $mode, ?string $name, array $definition, array $options, \Closure $resolve): Checkout
    {
        $customer = $this->customers->requireCustomer($billable);
        foreach (['request_id', 'billing_customer_id', 'mode', 'ui_mode', 'line_items', 'customer_data'] as $key) {
            if (array_key_exists($key, $options)) {
                throw new ConflictException('Checkout identity, mode and items are managed by the billing intent.');
            }
        }
        $options = [...Routing::defaults($options), ...$options];
        $couponId = $definition['coupon_id'] ?? null;
        if (array_key_exists('discounts', $options) && $options['discounts'] !== []) {
            $discounts = $options['discounts'];
            if ($mode !== 'SUBSCRIPTION' || $couponId !== null || ! is_array($discounts) || ! array_is_list($discounts) || count($discounts) !== 1
                || ! is_array($discounts[0]) || ($discounts[0]['type'] ?? null) !== 'COUPON'
                || ! is_array($discounts[0]['coupon'] ?? null) || ! is_string($discounts[0]['coupon']['id'] ?? null) || $discounts[0]['coupon']['id'] === '') {
                throw new ConflictException('Hosted Coupon creation supports one exclusive subscription-level Coupon.');
            }
            $couponId = $discounts[0]['coupon']['id'];
        }
        if ($couponId !== null && ! array_key_exists('currency', $options) && config('airwallex.currency') !== null) {
            $options['currency'] = config('airwallex.currency');
        }
        if ($mode === 'SETUP' && ! array_key_exists('currency', $options)) {
            $options['currency'] = config('airwallex.currency');
        }
        $specification = ['definition' => $definition, 'options' => $options];
        $kind = $mode === 'SUBSCRIPTION' ? 'checkout.subscription' : 'checkout.setup';
        $existing = $this->existing($customer, $actionKey, $kind, $specification, $name);
        if ($existing) {
            return $this->finish($existing);
        }
        $resolved = $resolve();
        $payload = [...$options, 'billing_customer_id' => $customer->remote_id, 'mode' => $mode, 'ui_mode' => 'HOSTED'];
        if ($mode === 'SUBSCRIPTION') {
            $payload['line_items'] = $resolved['items'];
            $subscriptionData = array_key_exists('subscription_data', $payload) ? $payload['subscription_data'] : [];
            if ($subscriptionData instanceof stdClass && get_object_vars($subscriptionData) === []) {
                $subscriptionData = [];
            } elseif (array_key_exists('subscription_data', $payload) && is_array($subscriptionData) && array_is_list($subscriptionData)) {
                throw new ConflictException('Explicit subscription checkout data must preserve object identity.');
            }
            if (! is_array($subscriptionData)) {
                throw new ConflictException('Subscription checkout data must be an SDK-shaped object map.');
            }
            foreach (['starts_at', 'trial_ends_at', 'discounts'] as $key) {
                if (array_key_exists($key, $subscriptionData)) {
                    throw new ConflictException('Checkout timing is managed by its builder.');
                }
                if (array_key_exists($key, $resolved)) {
                    $subscriptionData[$key] = $resolved[$key];
                }
            }
            $payload['subscription_data'] = $subscriptionData;
        }
        $intent = ['payload' => $payload, 'name' => $name];
        if ($couponId !== null) {
            $terms = app(CouponEvidence::class)->coupon($couponId);
            app(CouponEvidence::class)->assertCreation($payload, $terms);
            $payload['discounts'] = [['type' => 'COUPON', 'coupon' => ['id' => $couponId]]];
            $intent = ['payload' => $payload, 'name' => $name, 'coupon_terms' => $terms];
        }
        $operation = $this->operations->prepare($customer, $actionKey, $kind, $specification, $intent, name: $name);

        return $this->finish($operation);
    }

    private function existing(Customer $customer, string $actionKey, string $kind, array $specification, ?string $name): ?Operation
    {
        if ($actionKey === '' || strlen($actionKey) > 255) {
            throw new ConflictException('A bounded action key is required.');
        }
        $operation = ModelRegistry::query('operation')->where($this->context->attributes())->where('action_key', $actionKey)->first();
        if ($operation && ! hash_equals($operation->intent_hash, hash('sha256', CanonicalJson::encode([$kind, $customer->id, null, $name, $specification])))) {
            throw new ConflictException('Action key was already used for a different checkout intent.', $operation->id);
        }

        return $operation;
    }

    private function finish(Operation $operation): Checkout
    {
        if ($operation->status === 'failed') {
            throw new ConflictException('Rejected checkout intent requires a new action key.', $operation->id);
        }
        if ($operation->status === 'succeeded') {
            $local = ModelRegistry::query('checkout')->where($this->context->attributes())->where('operation_id', $operation->id)->where('remote_id', $operation->remote_id)->first();
            if (! $local || $local->customer_id !== $operation->customer_id) {
                throw new EvidenceException('Completed checkout intent has no matching local resource.');
            }

            return $local;
        }
        if ($operation->status !== 'prepared') {
            return $this->recover($operation);
        }
        try {
            $this->payload($operation);
            if (isset($operation->intent['coupon_terms'])) {
                if (app(CouponEvidence::class)->coupon($operation->intent['coupon_terms']['id']) !== $operation->intent['coupon_terms']) {
                    throw new EvidenceException('Hosted Coupon terms changed before the first write.');
                }
                app(CouponEvidence::class)->assertCreation($operation->intent['payload'], $operation->intent['coupon_terms']);
            }
        } catch (Throwable $exception) {
            $this->rejectPrepared($operation);
            throw $exception;
        }

        return $this->operations->execute($operation, function () use ($operation): array {
            $expires = $operation->intent['coupon_terms']['expires_at'] ?? null;
            if (is_string($expires) && Timestamp::compare($expires, now()->format('Y-m-d\TH:i:s.uP')) <= 0) {
                throw new EvidenceException('Hosted Coupon redemption expired before dispatch.');
            }

            return $this->client->billing()->checkouts()->create($this->payload($operation));
        }, function (array $response, Operation $current): Checkout {
            $customer = ModelRegistry::query('customer')->findOrFail($current->customer_id);
            $this->verify($response, $response['id'], $customer, $current);

            return $this->observe($current, $response['id']);
        });
    }

    /** Read-only recovery; unknown IDs require explicit, canonically verified correlation. */
    public function recover(Operation $operation, ?string $remoteId = null): Checkout
    {
        app(CreationRetries::class)->assertCurrent($operation, $remoteId);
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->findOrFail($operation->id);
        if (! in_array($operation->kind, ['checkout.subscription', 'checkout.setup'], true) || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Operation is not an attempted checkout creation.');
        }
        if ($operation->remote_id !== null && $remoteId !== null && $operation->remote_id !== $remoteId) {
            throw new OwnershipException('Checkout identity cannot change across recovery.');
        }
        $id = $operation->remote_id ?? $remoteId;
        if ($id === null) {
            throw new AmbiguousOperationException($operation->id);
        }
        try {
            $checkout = $this->observe($operation, $id, $operation->remote_id === null);
            $operation->refresh();
            if ($operation->remote_id === null) {
                $operation->remote_id = $id;
                $operation->save();
            }
            $this->operations->succeed($operation);

            return $checkout;
        } catch (Throwable $exception) {
            throw new AmbiguousOperationException($operation->id, $exception);
        }
    }

    /** @internal Read-only command/job recovery; this never submits a mutation. */
    public function reconcileOperation(Operation $operation, ?string $remoteId = null): Checkout
    {
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->findOrFail($operation->id);
        if (in_array($operation->kind, ['checkout.subscription', 'checkout.setup'], true)) {
            return $this->recover($operation, $remoteId);
        }
        if (! in_array($operation->kind, ['checkout.cancel', 'checkout.metadata'], true) || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Checkout operation is not eligible for read-only recovery.');
        }
        $checkout = ModelRegistry::query('checkout')->findOrFail($operation->intent['checkout_id']);

        return $this->change($checkout, $operation->action_key, $operation->kind, $operation->intent['input']);
    }

    public function reconcile(Checkout $checkout): Checkout
    {
        $this->context->assertModel($checkout);
        $checkout = ModelRegistry::query('checkout')->findOrFail($checkout->id);
        $operation = ModelRegistry::query('operation')->findOrFail($checkout->operation_id);

        return $this->observe($operation, $checkout->remote_id);
    }

    private function observe(Operation $operation, string $id, bool $requireCorrelation = false): Checkout
    {
        $this->context->assertModel($operation);
        if (! in_array($operation->kind, ['checkout.subscription', 'checkout.setup'], true) || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Checkout observation has no attempted creation intent.');
        }
        if ($operation->remote_id !== null && $operation->remote_id !== $id) {
            throw new OwnershipException('Checkout observation does not match its durable resource identity.');
        }
        $customer = ModelRegistry::query('customer')->findOrFail($operation->customer_id);
        $this->context->assertModel($customer);
        $before = ModelRegistry::query('checkout')->where($this->context->attributes())->where('remote_id', $id)->first();
        $remote = SdkResponseShape::normalize($this->client->billing()->checkouts()->retrieve($id));
        $this->verify($remote, $id, $customer, $operation, $requireCorrelation);
        try {
            $checkout = DB::transaction(function () use ($operation, $customer, $before, $remote, $id): Checkout {
                app(CommitGuard::class)->assertMayCommit();
                ModelRegistry::query('customer')->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                app(CreationRetries::class)->assertCurrent($operation, $id);
                $checkout = ModelRegistry::query('checkout')->where($this->context->attributes())->where('remote_id', $id)->first();
                if ($before !== null && ($checkout === null || $checkout->id !== $before->id)) {
                    throw new ConflictException('The known checkout mapping was removed during reconciliation.');
                }
                if ($checkout && ($checkout->operation_id !== $operation->id || $checkout->customer_id !== $customer->id)) {
                    throw new OwnershipException('Checkout is already mapped to a different intent.');
                }
                if ($checkout && $checkout->provider_updated_at !== null) {
                    $order = Timestamp::compare($remote['updated_at'], $checkout->provider_updated_at);
                    if ($order < 0 || ($order === 0 && ($before === null || $before->revision !== $checkout->revision))) {
                        return $checkout;
                    }
                    if ($order === 0) {
                        foreach (['subscription_id' => 'remote_subscription_id', 'payment_source_id' => 'remote_payment_source_id'] as $remoteField => $localField) {
                            if ($checkout->getAttribute($localField) !== null && isset($remote[$remoteField]) && $checkout->getAttribute($localField) !== $remote[$remoteField]) {
                                throw new EvidenceException('Equal checkout versions disagree about a positive resulting resource.');
                            }
                        }
                    }
                    if ($checkout->provider_created_at !== null && Timestamp::compare($checkout->provider_created_at, $remote['created_at']) !== 0) {
                        throw new EvidenceException('Checkout creation identity changed.');
                    }
                    if ($order === 0 && $checkout->status !== $remote['status']) {
                        throw new EvidenceException('Equal checkout versions disagree about completion.');
                    }
                    if (in_array($checkout->status, ['COMPLETED', 'CANCELLED', 'EXPIRED'], true) && $remote['status'] === 'ACTIVE') {
                        throw new EvidenceException('Terminal checkout cannot be reopened by an observation.');
                    }
                }
                if ($checkout?->subscription_id !== null && isset($remote['subscription_id']) && $remote['subscription_id'] !== $checkout->remote_subscription_id) {
                    throw new OwnershipException('Verified checkout subscription identity cannot change.');
                }
                if ($checkout?->payment_source_id !== null && isset($remote['payment_source_id']) && $remote['payment_source_id'] !== $checkout->payment_source_id) {
                    throw new OwnershipException('Verified setup source identity cannot change.');
                }
                $operation->refresh();
                if ($operation->remote_id !== null && $operation->remote_id !== $id) {
                    throw new OwnershipException('Checkout intent already identifies a different resource.');
                }
                if ($operation->remote_id === null) {
                    $operation->remote_id = $id;
                    $operation->save();
                }
                $boundRemoteId = null;
                if ($operation->subscription_id !== null) {
                    $bound = ModelRegistry::query('subscription')->findOrFail($operation->subscription_id);
                    $boundRemoteId = $bound->remote_id;
                    if (isset($remote['subscription_id']) && $bound->remote_id !== $remote['subscription_id']) {
                        throw new OwnershipException('Checkout creation intent already has a different confirmed result.');
                    }
                }
                $new = $checkout === null;
                $name = null;
                if ($operation->kind === 'checkout.subscription') {
                    $slot = ModelRegistry::query('subscription_slot')->where('operation_id', $operation->id)->first();
                    $name = $operation->intent['name'] ?? $slot->name ?? $checkout->name ?? $before?->name;
                    if ($name === null) {
                        throw new ConflictException('Subscription checkout has no durable name reservation.');
                    }
                }
                $checkout ??= ModelRegistry::make('checkout', [...$this->context->attributes(), 'customer_id' => $customer->id, 'operation_id' => $operation->id, 'name' => $name, 'mode' => $remote['mode'], 'remote_id' => $id]);
                $snapshot = SubscriptionSynchronizer::sanitize($remote);
                $changed = $new || CanonicalJson::encode($checkout->snapshot) !== CanonicalJson::encode($snapshot);
                $oldStatus = $checkout->status;
                $checkout->fill(['status' => $remote['status'], 'expires_at' => $remote['expires_at'], 'url' => $remote['url'] ?? null, 'remote_subscription_id' => $remote['subscription_id'] ?? $boundRemoteId ?? $checkout->remote_subscription_id, 'remote_payment_source_id' => $remote['payment_source_id'] ?? $checkout->remote_payment_source_id ?? $checkout->payment_source_id, 'snapshot' => $snapshot, 'provider_created_at' => $remote['created_at'], 'provider_updated_at' => $remote['updated_at'], 'revision' => ($checkout->revision ?? 0) + 1])->save();
                if ($changed) {
                    $this->outbox->record('CheckoutReconciled', $checkout, ['remote_id' => $id, 'mode' => $checkout->mode, 'previous_status' => $oldStatus, 'status' => $checkout->status]);
                }

                return $checkout;
            });
        } catch (QueryException) {
            throw new ConflictException('Checkout observation conflicted with a concurrent mapping.');
        }
        if ($checkout->status === 'COMPLETED') {
            if ($checkout->mode === 'SUBSCRIPTION') {
                if ($checkout->remote_payment_source_id !== null) {
                    $source = $this->sources->retrieve($customer, $checkout->remote_payment_source_id);
                    $this->linkChild($checkout, 'source', $source->id, $source->id);
                }
                if (! is_string($checkout->remote_subscription_id) || $checkout->remote_subscription_id === '') {
                    throw new EvidenceException('Completed checkout has no confirmed subscription identity.');
                }
                $operation->refresh();
                if ($operation->remote_id === null) {
                    $operation->remote_id = $id;
                    $operation->save();
                }
                $subscription = $this->subscriptions->confirm($customer, $checkout->name, $checkout->remote_subscription_id, $operation);
                $this->linkChild($checkout, 'subscription', $subscription->id, $subscription->remote_id);
            } else {
                if (! is_string($checkout->remote_payment_source_id) || $checkout->remote_payment_source_id === '') {
                    throw new EvidenceException('Completed setup has no confirmed source identity.');
                }
                $source = $this->sources->retrieve($customer, $checkout->remote_payment_source_id);
                $this->linkChild($checkout, 'source', $source->id, $source->id);
            }
        } elseif (in_array($checkout->status, ['CANCELLED', 'EXPIRED'], true)) {
            DB::transaction(function () use ($checkout, $operation): void {
                app(CommitGuard::class)->assertMayCommit();
                $locked = ModelRegistry::query('checkout')->whereKey($checkout->id);
                $locked->lockForUpdate();
                $current = $locked->firstOrFail();
                $this->context->assertModel($current);
                $intent = ModelRegistry::query('operation')->findOrFail($operation->id);
                if ($intent->subscription_id !== null || ! in_array($current->status, ['CANCELLED', 'EXPIRED'], true) || $current->remote_subscription_id !== null || $current->remote_payment_source_id !== null || $current->subscription_id !== null || $current->payment_source_id !== null) {
                    throw new EvidenceException('Terminal checkout has conflicting resulting-resource evidence.');
                }
                ModelRegistry::query('subscription_slot')->where('operation_id', $operation->id)->whereNull('subscription_id')->delete();
            });
        }

        return $checkout->fresh();
    }

    private function linkChild(Checkout $checkout, string $kind, string $localId, string $remoteId): void
    {
        DB::transaction(function () use ($checkout, $kind, $localId, $remoteId): void {
            app(CommitGuard::class)->assertMayCommit();
            $locked = ModelRegistry::query('checkout')->whereKey($checkout->id);
            $locked->lockForUpdate();
            $current = $locked->firstOrFail();
            $this->context->assertModel($current);
            Routing::assertCheckout($current->snapshot ?? []);
            $candidate = $kind === 'subscription' ? $current->remote_subscription_id : $current->remote_payment_source_id;
            $field = $kind === 'subscription' ? 'subscription_id' : 'payment_source_id';
            if ($current->status !== 'COMPLETED' || $candidate !== $remoteId || $current->operation_id !== $checkout->operation_id || $current->customer_id !== $checkout->customer_id) {
                throw new EvidenceException('Checkout changed while its resulting resource was being verified.');
            }
            if ($current->getAttribute($field) !== null && $current->getAttribute($field) !== $localId) {
                throw new OwnershipException('Verified checkout result cannot be reassigned.');
            }
            $current->setAttribute($field, $localId);
            $current->save();
        });
    }

    public function redirect(Checkout $checkout): RedirectResponse
    {
        $this->context->assertModel($checkout);
        $checkout = ModelRegistry::query('checkout')->findOrFail($checkout->id);
        $this->context->assertModel($checkout);
        Routing::assertCheckout($checkout->snapshot ?? []);
        $url = $checkout->url;
        $parts = is_string($url) ? parse_url($url) : false;
        if ($checkout->status !== 'ACTIVE' || ! is_string($checkout->expires_at) || Timestamp::compare(now()->format('Y-m-d\TH:i:s.uP'), $checkout->expires_at) >= 0
            || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new EvidenceException('Checkout has no current confirmed HTTPS hosted redirect.');
        }

        return redirect()->away($url);
    }

    public function updateMetadata(Checkout $checkout, string $actionKey, array|stdClass $metadata): Checkout
    {
        return $this->change($checkout, $actionKey, 'checkout.metadata', ['metadata' => $metadata]);
    }

    public function cancel(Checkout $checkout, string $actionKey): Checkout
    {
        return $this->change($checkout, $actionKey, 'checkout.cancel', []);
    }

    private function change(Checkout $checkout, string $actionKey, string $kind, array $input): Checkout
    {
        $this->context->assertModel($checkout);
        $checkout = ModelRegistry::query('checkout')->findOrFail($checkout->id);
        $customer = ModelRegistry::query('customer')->findOrFail($checkout->customer_id);
        $creation = ModelRegistry::query('operation')->findOrFail($checkout->operation_id);
        $specification = ['checkout_id' => $checkout->id, 'input' => $input];
        $operation = $this->existing($customer, $actionKey, $kind, $specification, null);
        if ($operation) {
            if ($operation->status === 'failed') {
                throw new ConflictException('Rejected checkout mutation requires a new action key.', $operation->id);
            }
            if ($operation->status === 'succeeded') {
                return $checkout;
            }
            if ($operation->status !== 'prepared') {
                try {
                    $current = $this->reconcile($checkout);
                    if ($operation->remote_id === null || $operation->remote_id !== $current->remote_id) {
                        throw new AmbiguousOperationException($operation->id);
                    }
                    Routing::assertCheckout([...($operation->observed_routing ?? []), ...$current->snapshot]);
                    $this->outcome($current, $operation);
                    $this->operations->succeed($operation);

                    return $current;
                } catch (Throwable $exception) {
                    throw new AmbiguousOperationException($operation->id, $exception);
                }
            }
        } else {
            $checkout = $this->reconcile($checkout);
            if ($kind === 'checkout.cancel' && $checkout->status !== 'ACTIVE') {
                throw new ConflictException('Only an active hosted checkout can be cancelled.');
            }
            $operation = $this->operations->prepare($customer, $actionKey, $kind, $specification, ['checkout_id' => $checkout->id, 'input' => $input], lockKey: 'checkout/'.$checkout->id);
        }
        try {
            if ($kind === 'checkout.metadata') {
                $this->metadata($input['metadata'], $creation->id);
            }
        } catch (Throwable $exception) {
            $this->rejectPrepared($operation);
            throw $exception;
        }

        return $this->operations->execute($operation, fn () => $kind === 'checkout.cancel'
            ? $this->client->billing()->checkouts()->cancel($checkout->remote_id)
            : $this->client->billing()->checkouts()->update($checkout->remote_id, ['metadata' => $this->metadata($operation->intent['input']['metadata'], $creation->id)]),
            function (array $response) use ($checkout, $customer, $creation, $operation): Checkout {
                $this->verify($response, $checkout->remote_id, $customer, $creation);
                $current = $this->observe($creation, $checkout->remote_id);
                $this->outcome($current, $operation);

                return $current;
            });
    }

    private function outcome(Checkout $checkout, Operation $operation): void
    {
        if ($operation->kind === 'checkout.cancel') {
            if ($checkout->status !== 'CANCELLED') {
                throw new EvidenceException('Checkout cancellation is not canonically confirmed.');
            }

            return;
        }
        $expected = get_object_vars($this->metadata($operation->intent['input']['metadata'], $checkout->operation_id));
        $observed = $checkout->snapshot['metadata'] ?? null;
        if (! is_array($observed) || array_is_list($observed)) {
            throw new EvidenceException('Checkout metadata update is not confirmed.');
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $observed) || $observed[$key] !== $value) {
                throw new EvidenceException('Checkout metadata update is not confirmed.');
            }
        }
    }

    private function payload(Operation $operation): array
    {
        $payload = $operation->intent['payload'];
        Routing::defaults($payload);
        $payload['metadata'] = $this->metadata(array_key_exists('metadata', $payload) ? $payload['metadata'] : new stdClass, $operation->id);
        if ($payload['mode'] === 'SUBSCRIPTION') {
            $data = $payload['subscription_data'];
            $data['metadata'] = $this->metadata(array_key_exists('metadata', $data) ? $data['metadata'] : new stdClass, $operation->id);
            $payload['subscription_data'] = $data;
        }

        return [...$payload, 'request_id' => $operation->request_id];
    }

    private function metadata(mixed $metadata, string $operationId): stdClass
    {
        if ($metadata instanceof stdClass) {
            $members = get_object_vars($metadata);
        } elseif (is_array($metadata) && ! array_is_list($metadata)) {
            $members = $metadata;
        } else {
            throw new ConflictException('Checkout metadata must be an explicit object map.');
        }
        if (array_key_exists(self::MARKER, $members)) {
            throw new ConflictException('Checkout correlation metadata is reserved.');
        }
        $object = (object) $members;
        $object->{self::MARKER} = $operationId;

        return $object;
    }

    private function verify(array $remote, string $id, Customer $customer, Operation $operation, bool $requireCorrelation = false): void
    {
        $mode = $operation->kind === 'checkout.subscription' ? 'SUBSCRIPTION' : 'SETUP';
        if (($remote['id'] ?? null) !== $id || ($remote['billing_customer_id'] ?? null) !== $customer->remote_id || ($remote['mode'] ?? null) !== $mode || ($remote['ui_mode'] ?? null) !== 'HOSTED') {
            throw new OwnershipException('Checkout identity, mode or customer does not match its durable intent.');
        }
        Routing::assertCheckout([...($operation->observed_routing ?? []), ...$remote]);
        if (isset($operation->intent['coupon_terms'])) {
            $discounts = $remote['discounts'] ?? null;
            if (! is_array($discounts) || ! array_is_list($discounts) || count($discounts) !== 1
                || ! is_array($discounts[0]) || ($discounts[0]['type'] ?? null) !== 'COUPON'
                || ($discounts[0]['coupon']['id'] ?? null) !== $operation->intent['coupon_terms']['id']
                || ($operation->intent['coupon_terms']['discount_model'] === 'FLAT' && ($remote['currency'] ?? null) !== $operation->intent['coupon_terms']['currency'])) {
                throw new EvidenceException('The hosted checkout does not confirm its frozen Coupon selection and currency.');
            }
        }
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $remote) && $remote[$key] !== $expected) {
                throw new OwnershipException('Checkout response belongs to a foreign context.');
            }
        }
        if (! is_string($remote['status'] ?? null) || $remote['status'] === '') {
            throw new EvidenceException('Checkout status is malformed.');
        }
        foreach (['created_at', 'updated_at', 'expires_at'] as $field) {
            if (! is_string($remote[$field] ?? null)) {
                throw new EvidenceException('Checkout timing is missing.');
            }
            Timestamp::compare($remote[$field], $remote[$field]);
        }
        $metadata = $remote['metadata'] ?? null;
        $marker = is_array($metadata) ? ($metadata[self::MARKER] ?? null) : ($metadata instanceof stdClass ? ($metadata->{self::MARKER} ?? null) : null);
        if (($requireCorrelation || $marker !== null) && $marker !== $operation->id) {
            throw new OwnershipException('Checkout correlation does not match the durable operation.');
        }
    }

    private function rejectPrepared(Operation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            app(CommitGuard::class)->assertMayCommit();
            $changed = ModelRegistry::query('operation')->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'failed', 'lock_key' => null, 'error_code' => 'checkout_preflight']);
            if ($changed !== 1) {
                throw new AmbiguousOperationException($operation->id);
            }
            ModelRegistry::query('subscription_slot')->where('operation_id', $operation->id)->whereNull('subscription_id')->delete();
        });
    }
}
