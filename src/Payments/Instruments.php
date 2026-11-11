<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Customer as BillingCustomer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\MethodObservation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\PaymentSource;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\SourceManager;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Timestamp;
use Throwable;

class Instruments
{
    private const MARKER = '_laravel_setup_key';

    public function __construct(private readonly Context $context, private readonly Customers $customers, private readonly Operations $operations) {}

    public function createSetup(Customer $customer, string $key, array $options = []): BrowserSetup
    {
        $customer = $this->customer($customer);
        if (array_diff(array_keys($options), ['next_triggered_by', 'merchant_trigger_reason', 'metadata', 'terms_of_use']) !== []) {
            throw new ConflictException('Consent setup identity and request fields are managed by the package.');
        }
        $metadata = $options['metadata'] ?? [];
        if (! is_array($metadata) || array_key_exists(self::MARKER, $metadata)) {
            throw new ConflictException('Consent correlation metadata is reserved.');
        }
        $intent = ['next_triggered_by' => 'merchant', 'merchant_trigger_reason' => 'unscheduled', ...$options,
            'customer_id' => $customer->remote_id,
            'metadata' => [...$metadata, self::MARKER => hash('sha256', CanonicalJson::encode([$customer->id, $key]))]];
        $operation = $this->operations->prepare($customer, $key, 'consent.create', $intent);
        if ($operation->status !== 'prepared') {
            return new BrowserSetup($this->recover($operation));
        }

        return $this->operations->execute($operation,
            fn () => app(Client::class)->sdk->payments()->paymentConsents()->create([...$intent, 'request_id' => $operation->request_id]),
            function (array $response) use ($customer, $operation): BrowserSetup {
                $instrument = $this->confirm($customer, $operation, $response['id']);

                return new BrowserSetup($instrument, is_string($response['client_secret'] ?? null) ? $response['client_secret'] : null,
                    is_array($response['next_action'] ?? null) ? $response['next_action'] : null);
            });
    }

    /** Callback parameters are only locators. Only a previously known setup can complete. */
    public function complete(Customer $customer, string $consentId): Instrument
    {
        $customer = $this->customer($customer);
        $this->local($customer, $consentId);

        return $this->observe($customer, $consentId);
    }

    /** Canonical Method-only notifications never invent a Consent state or billing source. */
    public function reconcileMethod(Customer $customer, string $methodId): void
    {
        $customer = $this->customer($customer);
        if (! MethodObservation::query()->where([...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $methodId])->exists()) {
            throw new OwnershipException('Payment Method has no known account-owned association.');
        }
        $method = $this->method($customer, $methodId);
        DB::transaction(function () use ($customer, $methodId, $method): void {
            $this->lockCustomer($customer);
            $proof = MethodObservation::query()->where([...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $methodId])->firstOrFail();
            if ($proof->provider_updated_at !== $method['updated_at'] || $proof->status !== $method['status']) {
                throw new ConflictException('Payment Method changed during canonical notification processing.');
            }
            $instruments = Instrument::query()->where([...$this->context->attributes(), 'customer_id' => $customer->id, 'method_id' => $methodId])->get();
            foreach ($instruments as $instrument) {
                $snapshot = $instrument->getAttribute('snapshot');
                $snapshot['method'] = ['id' => $methodId, 'customer_id' => $method['customer_id'], 'type' => $method['type'], 'status' => $method['status'], 'updated_at' => $method['updated_at'],
                    'card' => array_intersect_key($method['card'] ?? [], array_flip(['last4', 'brand', 'expiry_month', 'expiry_year']))];
                $instrument->forceFill(['method_status' => $method['status'], 'method_type' => $method['type'], 'snapshot' => $snapshot, 'revision' => $instrument->revision + 1])->save();
                if ($method['status'] !== 'CREATED') {
                    DB::table('airwallex_payment_instrument_defaults')->where('customer_id', $customer->id)->where('instrument_id', $instrument->id)->update(['instrument_id' => null]);
                }
                app(Observations::class)->record($instrument, ['remote_id' => $instrument->remote_id, 'status' => $instrument->status, 'method_status' => $instrument->method_status, 'payment_method_id' => $instrument->method_id]);
            }
        });
    }

    /** Strict saved-card eligibility for merchant-initiated unscheduled charges. */
    public function verified(Customer $customer, string $consentId): Instrument
    {
        $instrument = $this->complete($customer, $consentId);
        $this->assertUsable($instrument);
        if ($instrument->next_triggered_by !== 'merchant' || $instrument->merchant_trigger_reason !== 'unscheduled') {
            throw new EvidenceException('Consent is not verified for merchant-initiated unscheduled charges.');
        }

        return $instrument;
    }

    /** Called under the charge reservation's owning-Customer lock; performs no HTTP. */
    public function assertUsableReservation(Customer $customer, string $consentId): void
    {
        $customer = $this->customer($customer);
        $instrument = $this->local($customer, $consentId);
        $this->assertUsable($instrument);
        if ($instrument->next_triggered_by !== 'merchant' || $instrument->merchant_trigger_reason !== 'unscheduled') {
            throw new EvidenceException('Consent is not eligible for this merchant-triggered reservation.');
        }
    }

    /** @internal Retain canonical Method proof for an authorized on-session action. */
    public function observeMethod(Customer $customer, string $methodId): void
    {
        $this->method($this->customer($customer), $methodId);
    }

    /** @internal Called under the common Customer lock; no HTTP or Consent eligibility shortcut. */
    public function assertMethodReservation(Customer $customer, string $methodId): void
    {
        $customer = $this->customer($customer);
        $method = MethodObservation::query()->where([...$this->context->attributes(), 'remote_id' => $methodId])->first();
        if ($method === null || $method->customer_id !== $customer->id) {
            throw new OwnershipException('Owned canonical Payment Method proof is unavailable.');
        }
        $this->context->assertModel($method);
        if ($method->status !== 'CREATED' || ! in_array($method->method_type, ['card', 'applepay', 'googlepay'], true)) {
            throw new EvidenceException('Retained Payment Method proof does not allow this on-session charge.');
        }
    }

    /** Explicitly adopt existing same-customer consents after all list pages succeed. */
    public function all(Customer $customer): Collection
    {
        $customer = $this->customer($customer);
        $ids = [];
        for ($page = 0; $page < 10000; $page++) {
            $remote = app(Client::class)->sdk->payments()->paymentConsents()->list(['customer_id' => $customer->remote_id, 'page_num' => $page, 'page_size' => 100]);
            if (! is_array($remote['items'] ?? null) || ! array_is_list($remote['items']) || ! is_bool($remote['has_more'] ?? null)) {
                throw new EvidenceException('Consent listing did not establish a complete page.');
            }
            foreach ($remote['items'] as $item) {
                $id = $item['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($ids[$id]) || ($item['customer_id'] ?? null) !== $customer->remote_id) {
                    throw new OwnershipException('Consent listing repeated an identity or returned a foreign customer.');
                }
                $ids[$id] = true;
            }
            if (! $remote['has_more']) {
                return collect(array_map(fn (string $id) => $this->observe($customer, $id, allowLink: true), array_keys($ids)));
            }
            if ($remote['items'] === []) {
                throw new EvidenceException('Consent listing cannot advance an empty page.');
            }
        }
        throw new EvidenceException('Consent listing exceeded the bounded page budget.');
    }

    /** Existing tokenized card ID only; raw card, CVC and browser secrets never enter persistence. */
    public function verify(Customer $customer, string $key, string $consentId, string $methodId, array $options = []): BrowserSetup
    {
        if (array_diff(array_keys($options), ['return_url', 'descriptor']) !== []) {
            throw new ConflictException('Collect card and device data in the provider browser SDK.');
        }

        return $this->mutate($customer, $key, 'verify', $consentId, [...$options, 'payment_method' => ['id' => $methodId, 'type' => 'card']]);
    }

    public function continueVerification(Customer $customer, string $key, string $consentId, array $options): BrowserSetup
    {
        if (array_diff(array_keys($options), ['type', 'micro_deposit']) !== []) {
            throw new ConflictException('Continue verification accepts only the selected SDK continuation fields.');
        }

        return $this->mutate($customer, $key, 'continue', $consentId, $options);
    }

    public function update(Customer $customer, string $key, string $consentId, array $metadata): Instrument
    {
        $instrument = $this->local($this->customer($customer), $consentId);
        $marker = $instrument->getAttribute('correlation_key');
        if (array_key_exists(self::MARKER, $metadata)) {
            throw new ConflictException('Consent correlation metadata is reserved.');
        }
        if (is_string($marker)) {
            $metadata[self::MARKER] = $marker;
        }

        return $this->mutate($customer, $key, 'update', $consentId, ['metadata' => $metadata])->instrument;
    }

    /** Local revocation precedes remote disable; ambiguity cannot restore local use. */
    public function disable(Customer $customer, string $key, string $consentId): Instrument
    {
        return $this->mutate($customer, $key, 'disable', $consentId, [])->instrument;
    }

    public function setDefault(Customer $customer, ?Instrument $instrument): void
    {
        $customer = $this->customer($customer);
        if ($instrument !== null) {
            $this->context->assertModel($instrument);
            $instrument = $this->complete($customer, $instrument->remote_id);
            $this->assertUsable($instrument);
        }
        DB::transaction(function () use ($customer, $instrument): void {
            $this->lockCustomer($customer);
            if ($instrument !== null) {
                $current = $this->local($customer, $instrument->remote_id);
                $this->assertUsable($current);
            }
            DB::table('airwallex_payment_instrument_defaults')->updateOrInsert(['customer_id' => $customer->id], ['instrument_id' => $instrument?->id]);
        });
    }

    public function default(Customer $customer): ?Instrument
    {
        $customer = $this->customer($customer);
        $id = DB::table('airwallex_payment_instrument_defaults')->where('customer_id', $customer->id)->value('instrument_id');
        if ($id === null) {
            return null;
        }
        $instrument = Instrument::query()->where([...$this->context->attributes(), 'customer_id' => $customer->id])->findOrFail($id);
        $instrument = $this->complete($customer, $instrument->remote_id);
        $this->assertUsable($instrument);

        return $instrument;
    }

    /** Explicit Billing association requires both authorized local mappings and canonical method linkage. */
    public function billingSource(Customer $customer, string $consentId, BillingCustomer $billingCustomer, string $sourceId): PaymentSource
    {
        $customer = $this->customer($customer);
        if ($billingCustomer->billable_type !== $customer->getAttribute('billable_type') || $billingCustomer->billable_id !== $customer->getAttribute('billable_id') || $billingCustomer->environment !== $customer->environment) {
            throw new OwnershipException('Billing and Payments customers must represent the same authorized billable/environment.');
        }
        $instrument = $this->verified($customer, $consentId);
        $source = app(SourceManager::class)->retrieve($billingCustomer, $sourceId);
        if ($source->linkedPaymentAccountId !== $this->context->accountId || ($source->toArray()['external_id'] ?? null) !== $instrument->method_id) {
            throw new OwnershipException('Billing Source collector or method does not match the verified Payments account.');
        }

        return $source;
    }

    public function recover(Operation $operation, ?string $operatorKnownId = null): Instrument
    {
        $this->context->assertModel($operation);
        if (! str_starts_with($operation->kind, 'consent.') || in_array($operation->status, ['prepared', 'failed'], true)) {
            throw new ConflictException('Only an attempted consent action can be recovered through reads.');
        }
        $customer = Customer::query()->findOrFail($operation->customer_id);
        $id = $operation->remote_id ?? $operation->target_id ?? $operatorKnownId;
        if ($id === null || ($operatorKnownId !== null && $operatorKnownId !== $id)) {
            throw new AmbiguousOperationException($operation->id);
        }
        try {
            if ($operation->status === 'succeeded') {
                return $this->complete($customer, $id);
            }
            $instrument = $this->confirm($customer, $operation, $id);
            if ($operation->remote_id === null) {
                $operation->remote_id = $id;
                $operation->save();
            }
            $this->operations->succeed($operation);

            return $instrument;
        } catch (Throwable $error) {
            throw new AmbiguousOperationException($operation->id, $error);
        }
    }

    private function mutate(Customer $customer, string $key, string $action, string $id, array $body): BrowserSetup
    {
        $customer = $this->customer($customer);
        $instrument = $this->local($customer, $id);
        $operation = $this->operations->prepare($customer, $key, 'consent.'.$action, $body, $id);
        if ($operation->status !== 'prepared') {
            return new BrowserSetup($this->recover($operation));
        }
        if ($action === 'disable') {
            DB::transaction(function () use ($customer, $instrument): void {
                $this->lockCustomer($customer);
                Instrument::query()->whereKey($instrument->id)->whereNull('revoked_at')->update(['revoked_at' => now()->utc()]);
                DB::table('airwallex_payment_instrument_defaults')->where('customer_id', $customer->id)->where('instrument_id', $instrument->id)->update(['instrument_id' => null]);
            });
        }

        return $this->operations->execute($operation,
            function () use ($customer, $instrument, $action, $id, $body, $operation): array {
                $this->complete($customer, $id);
                if ($action === 'verify') {
                    $this->method($customer, $body['payment_method']['id']);
                    if ($instrument->fresh()->revoked_at !== null) {
                        throw new ConflictException('Locally revoked consent cannot be verified again.');
                    }
                }
                $consents = app(Client::class)->sdk->payments()->paymentConsents();
                $payload = [...$body, 'request_id' => $operation->request_id];

                return match ($action) {
                    'verify' => $consents->verify($id, $payload),
                    'continue' => $consents->continueVerification($id, $payload),
                    'update' => $consents->update($id, $payload),
                    'disable' => $consents->disable($id, $payload),
                    default => throw new ConflictException('Unsupported consent action.'),
                };
            },
            function (array $response) use ($customer, $operation): BrowserSetup {
                return new BrowserSetup($this->confirm($customer, $operation, $response['id']), null,
                    is_array($response['next_action'] ?? null) ? $response['next_action'] : null);
            });
    }

    private function confirm(Customer $customer, Operation $operation, string $id): Instrument
    {
        return $this->observe($customer, $id, operation: $operation, allowLink: $operation->kind === 'consent.create');
    }

    private function observe(Customer $customer, string $id, ?Operation $operation = null, bool $allowLink = false): Instrument
    {
        $this->context->assertModel($customer);
        $before = Instrument::query()->where([...$this->context->attributes(), 'remote_id' => $id])->first();
        if ($before !== null && $before->customer_id !== $customer->id) {
            throw new OwnershipException('Consent is already associated with another local customer.');
        }
        if ($before === null && ! $allowLink) {
            throw new OwnershipException('Consent setup is not known to this billable.');
        }
        $remote = app(Client::class)->sdk->payments()->paymentConsents()->retrieve($id);
        Proof::identity($remote, $id, $this->context);
        if (($remote['customer_id'] ?? null) !== $customer->remote_id || ! is_string($remote['status'] ?? null)) {
            throw new OwnershipException('Consent customer or status is not canonically confirmed.');
        }
        if (! is_string($remote['updated_at'] ?? null)) {
            throw new EvidenceException('Canonical consent version is unavailable.');
        }
        Timestamp::compare($remote['updated_at'], $remote['updated_at']);
        if ($operation !== null) {
            $intent = $operation->intent;
            $proven = $operation->kind === 'consent.create'
                ? Customers::contains($remote, $intent)
                : ($remote['request_id'] ?? null) === $operation->request_id;
            if (! $proven || ($operation->kind === 'consent.disable' && $remote['status'] !== 'DISABLED')
                || ($operation->kind === 'consent.update' && ! Customers::contains($remote, $intent))
                || ($operation->kind === 'consent.verify' && ($remote['payment_method']['id'] ?? null) !== ($intent['payment_method']['id'] ?? null))) {
                throw new EvidenceException('Consent action is not canonically correlated to its original intent.');
            }
        }
        $methodId = $remote['payment_method']['id'] ?? null;
        $method = $methodId === null ? null : $this->method($customer, $methodId);
        if ($method !== null && ($remote['payment_method']['type'] ?? null) !== $method['type']) {
            throw new EvidenceException('Consent and Payment Method types disagree.');
        }

        return DB::transaction(function () use ($customer, $remote, $id, $methodId, $method, $before, $operation): Instrument {
            $this->lockCustomer($customer);
            $current = Instrument::query()->where([...$this->context->attributes(), 'remote_id' => $id])->first();
            if ($current !== null && ($current->customer_id !== $customer->id || $before === null || $current->revision !== $before->revision)) {
                throw new ConflictException('Consent projection changed while its canonical reads were in flight.');
            }
            $previousTime = $current?->getAttribute('snapshot')['consent']['updated_at'] ?? null;
            if (is_string($previousTime)) {
                $order = Timestamp::compare($remote['updated_at'], $previousTime);
                if ($order < 0) {
                    throw new EvidenceException('A stale consent response cannot supply fresh eligibility proof.');
                }
                $same = $current->status === $remote['status'] && $current->method_id === $methodId
                    && $current->method_type === ($remote['payment_method']['type'] ?? null)
                    && $current->next_triggered_by === ($remote['next_triggered_by'] ?? null)
                    && $current->merchant_trigger_reason === ($remote['merchant_trigger_reason'] ?? null);
                if ($order === 0 && ! $same) {
                    throw new EvidenceException('Equal-version consent observations have conflicting eligibility fields.');
                }
            }
            $instrument = $current ?? new Instrument([...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $id,
                'create_operation_id' => $operation?->kind === 'consent.create' ? $operation->id : null]);
            $instrument->forceFill(['status' => $remote['status'], 'method_id' => $methodId, 'method_status' => $method['status'] ?? null,
                'method_type' => $method['type'] ?? ($remote['payment_method']['type'] ?? null),
                'next_triggered_by' => $remote['next_triggered_by'] ?? null, 'merchant_trigger_reason' => $remote['merchant_trigger_reason'] ?? null,
                'correlation_key' => $remote['metadata'][self::MARKER] ?? $instrument->getAttribute('correlation_key'),
                'snapshot' => ['consent' => array_intersect_key($remote, array_flip(['id', 'customer_id', 'status', 'next_triggered_by', 'merchant_trigger_reason', 'created_at', 'updated_at'])),
                    'method' => $method === null ? null : ['id' => $methodId, 'customer_id' => $method['customer_id'], 'type' => $method['type'], 'status' => $method['status'], 'updated_at' => $method['updated_at'],
                        'card' => array_intersect_key($method['card'] ?? [], array_flip(['last4', 'brand', 'expiry_month', 'expiry_year']))]],
                'revision' => ($current->revision ?? 0) + 1, 'synced_at' => now()->utc()])->save();
            if ($instrument->status !== 'VERIFIED' || $instrument->method_status !== 'CREATED' || $instrument->revoked_at !== null) {
                DB::table('airwallex_payment_instrument_defaults')->where('customer_id', $customer->id)->where('instrument_id', $instrument->id)->update(['instrument_id' => null]);
            }
            app(Observations::class)->record($instrument, ['remote_id' => $instrument->remote_id, 'status' => $instrument->status, 'method_status' => $instrument->method_status, 'payment_method_id' => $instrument->method_id]);

            return $instrument;
        });
    }

    private function method(Customer $customer, string $id): array
    {
        $method = app(Client::class)->sdk->payments()->paymentMethods()->retrieve($id);
        Proof::identity($method, $id, $this->context);
        if (($method['customer_id'] ?? null) !== $customer->remote_id || ! is_string($method['status'] ?? null) || ! is_string($method['type'] ?? null)) {
            throw new OwnershipException('Payment Method does not belong to the mapped customer.');
        }
        if (! is_string($method['updated_at'] ?? null)) {
            throw new EvidenceException('Canonical Payment Method version is unavailable.');
        }
        Timestamp::compare($method['updated_at'], $method['updated_at']);
        // Preserve independent positive Method evidence even if the Consent observation
        // is later rejected as stale. Other Consents cannot erase a known disable.
        DB::transaction(function () use ($customer, $id, $method): void {
            $this->lockCustomer($customer);
            $proof = MethodObservation::query()->where([...$this->context->attributes(), 'remote_id' => $id])->first();
            if ($proof !== null) {
                if ($proof->customer_id !== $customer->id) {
                    throw new OwnershipException('Payment Method is already associated with another customer.');
                }
                $order = Timestamp::compare($method['updated_at'], $proof->provider_updated_at);
                if ($order < 0 || ($order === 0 && ($proof->status !== $method['status'] || $proof->method_type !== $method['type']))) {
                    throw new EvidenceException('Payment Method version is stale or contradicts retained eligibility proof.');
                }
            }
            $proof ??= new MethodObservation([...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $id]);
            $proof->forceFill(['status' => $method['status'], 'method_type' => $method['type'], 'provider_updated_at' => $method['updated_at']])->save();
        });

        return $method;
    }

    private function local(Customer $customer, string $id): Instrument
    {
        return Instrument::query()->where([...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $id])->first()
            ?? throw new OwnershipException('Consent setup is not known to this billable.');
    }

    private function assertUsable(Instrument $instrument): void
    {
        if ($instrument->status !== 'VERIFIED' || $instrument->method_status !== 'CREATED' || $instrument->method_type !== 'card' || $instrument->method_id === null || $instrument->revoked_at !== null) {
            throw new EvidenceException('Saved card consent or method is not canonically usable.');
        }
        $method = MethodObservation::query()->where([...$this->context->attributes(), 'customer_id' => $instrument->customer_id, 'remote_id' => $instrument->method_id])->first();
        if ($method === null || $method->status !== 'CREATED' || $method->method_type !== 'card') {
            throw new EvidenceException('Retained Payment Method proof does not allow a charge reservation.');
        }
    }

    private function customer(Customer $customer): Customer
    {
        $current = $this->customers->current($customer);
        if ($current->remote_id === null || $current->disabled_at !== null) {
            throw new ConflictException('An enabled mapped Payments customer is required.');
        }

        return $current;
    }

    private function lockCustomer(Customer $customer): void
    {
        app(CommitGuard::class)->assertMayCommit();
        $query = Customer::query()->whereKey($customer->id);
        $query->lockForUpdate();
        $current = $query->firstOrFail();
        $this->context->assertModel($current);
        if ($current->disabled_at !== null) {
            throw new ConflictException('Payments customer was offboarded during the operation.');
        }
    }
}
