<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\CreationRetries;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;
use Throwable;

class Intents
{
    public function __construct(private readonly Context $context, private readonly Customers $customers, private readonly Operations $operations, private readonly Observations $observations) {}

    public function create(Customer $customer, string $key, string $merchantOrderId, mixed $amount, string $currency, array $options = []): Intent
    {
        $customer = $this->confirmedCustomer($customer);
        $amount = Amount::canonical($amount, $currency);
        if (Amount::units($amount, $currency) === '0' || $merchantOrderId === '' || strlen($merchantOrderId) > 255) {
            throw new ConflictException('A positive payable and stable merchant order identity are required; handle zero totals in the application.');
        }
        $this->options($options, ['additional_info', 'descriptor', 'merchant_category_code', 'merchant_website_url', 'metadata', 'order', 'payment_method_options', 'return_url', 'risk_control_options']);
        $body = [...$options, 'amount' => $amount, 'currency' => $currency, 'merchant_order_id' => $merchantOrderId, 'customer_id' => $customer->remote_id];
        try {
            [$operation, $intent] = DB::transaction(function () use ($customer, $key, $body, $merchantOrderId, $amount, $currency): array {
                $operation = $this->operations->prepare($customer, $key, 'intent.create', $body);
                $intent = Intent::query()->firstOrCreate([...$this->context->attributes(), 'order_reservation' => $merchantOrderId], [
                    'merchant_order_id' => $merchantOrderId, 'customer_id' => $customer->id, 'operation_id' => $operation->id, 'amount' => $amount, 'currency' => $currency,
                ]);
                if ($intent->operation_id !== $operation->id || $intent->customer_id !== $customer->id) {
                    throw new ConflictException('This merchant order already has an original Payments intent.', $intent->operation_id);
                }

                return [$operation, $intent->refresh()];
            });
        } catch (QueryException) {
            throw new ConflictException('A concurrent command reserved this Payments order.');
        }
        if ($operation->status !== 'prepared') {
            return $this->recover($customer, $operation);
        }

        return $this->operations->execute($operation,
            fn () => app(Client::class)->sdk->payments()->paymentIntents()->create([...$operation->intent, 'request_id' => $operation->request_id]),
            fn (array $remote) => $this->confirm($customer, $intent, $operation, $remote['id']),
        );
    }

    public function reconcile(Customer $customer, Intent $intent): Intent
    {
        $intent = $this->owned($customer, $intent);
        if ($intent->remote_id === null) {
            throw new ConflictException('Recover the original intent creation before reconciliation.', $intent->operation_id);
        }
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->paymentIntents()->retrieve($intent->remote_id));

        return $this->observe($customer, $intent, $remote, $intent->remote_id);
    }

    public function bootstrap(Customer $customer, Intent $intent): BrowserIntent
    {
        $intent = $this->owned($customer, $intent);
        if ($this->customers->current($customer)->disabled_at !== null) {
            throw new ConflictException('Offboarded customers cannot receive browser payment credentials.');
        }
        if ($intent->remote_id === null) {
            throw new ConflictException('An owned confirmed intent is required for browser bootstrap.');
        }
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->paymentIntents()->retrieve($intent->remote_id));
        $intent = $this->observe($customer, $intent, $remote, $intent->remote_id);
        if (! is_string($remote['client_secret'] ?? null) || $remote['client_secret'] === '') {
            throw new EvidenceException('Provider did not return client bootstrap.');
        }

        return new BrowserIntent($intent, $remote['client_secret'], $remote['next_action'] ?? null);
    }

    public function paymentAttempt(Customer $customer, Intent $intent, ?string $attemptId = null): ?PaymentAttempt
    {
        $intent = $this->reconcile($customer, $intent);

        return $this->readAttempt($intent, $attemptId);
    }

    public function savedCardChallenge(Customer $customer, Intent $intent, string $consentId): BrowserChallenge
    {
        $customer = $this->confirmedCustomer($customer);
        $intent = $this->owned($customer, $intent);
        if ($intent->remote_id === null) {
            throw new ConflictException('A confirmed Payments intent is required for a saved-card challenge.');
        }
        $instrument = app(Instruments::class)->verified($customer, $consentId);
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->paymentIntents()->retrieve($intent->remote_id));
        $intent = $this->observe($customer, $intent, $remote, $intent->remote_id);
        if ($intent->status !== 'REQUIRES_CUSTOMER_ACTION') {
            throw new ConflictException('This Payments intent has no outstanding customer action.');
        }
        $requestId = $remote['request_id'] ?? null;
        $operation = is_string($requestId) ? Operation::query()->where([
            ...$this->context->attributes(), 'customer_id' => $customer->id, 'kind' => 'intent.confirm',
            'target_id' => $intent->remote_id, 'request_id' => $requestId,
        ])->whereIn('status', ['in_flight', 'ambiguous', 'acknowledged', 'succeeded'])->first() : null;
        if ($operation === null || ($operation->intent['body']['payment_consent_id'] ?? null) !== $consentId
            || ($operation->intent['body']['triggered_by'] ?? 'merchant') !== 'merchant'
            || isset($operation->intent['body']['payment_method'])) {
            throw new OwnershipException('The outstanding customer action does not match the original saved-card confirmation.');
        }
        $attemptId = $this->latestAttemptId($remote);
        if ($attemptId === null || $attemptId === ($operation->confirmation_baseline['attempt_id'] ?? null)) {
            throw new EvidenceException('The challenge response has no distinct current attempt identity.');
        }
        $attempt = $this->readAttempt($intent, $attemptId);
        if ($attempt === null || $attempt->status !== 'AUTHENTICATION_REDIRECTED') {
            throw new EvidenceException('A canonical attempt awaiting authentication is required.');
        }
        $originalMethod = $operation->confirmation_baseline['consent_method_id'] ?? null;
        if (($attempt->consentId === null && $attempt->methodId === null)
            || ($originalMethod !== null && $originalMethod !== $instrument->method_id)
            || ($originalMethod === null && $attempt->methodId === null)
            || ($attempt->consentId !== null && $attempt->consentId !== $consentId)
            || ($attempt->methodId !== null && $attempt->methodId !== $instrument->method_id)) {
            throw new OwnershipException('The challenge attempt does not link to the original saved card.');
        }
        $action = $remote['next_action'] ?? null;
        $url = is_array($action) ? ($action['url'] ?? null) : null;
        if (! is_array($action) || ($action['type'] ?? null) !== 'redirect_iframe' || ($action['method'] ?? null) !== 'GET'
            || ! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null) {
            throw new EvidenceException('Provider did not return a supported HTTPS saved-card challenge.');
        }
        $current = $this->owned($customer, $intent);
        if ($current->status !== $intent->status || ($current->snapshot['updated_at'] ?? null) !== $remote['updated_at']
            || $this->latestAttemptId($current->snapshot) !== $attempt->id) {
            throw new ConflictException('The Payments intent changed during its challenge read.');
        }
        app(Instruments::class)->assertUsableReservation($customer, $consentId);
        if ($this->reservedMethod($customer, $consentId) !== $instrument->method_id) {
            throw new ConflictException('The saved card changed during its challenge read.');
        }

        return new BrowserChallenge($attempt->paymentIntentId, $attempt->id, $url);
    }

    private function readAttempt(Intent $intent, ?string $attemptId = null): ?PaymentAttempt
    {
        $attemptId ??= $this->latestAttemptId($intent->snapshot);
        if ($attemptId === null) {
            return null;
        }
        if ($attemptId === '') {
            throw new EvidenceException('Payments attempt identity is missing.');
        }
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->paymentAttempts()->retrieve($attemptId));
        Proof::identity($remote, $attemptId, $this->context);
        if ($intent->remote_id === null || ($remote['payment_intent_id'] ?? null) !== $intent->remote_id
            || (isset($remote['currency']) && $remote['currency'] !== $intent->currency)) {
            throw new OwnershipException('Payments attempt does not belong to this intent and currency.');
        }
        if (! is_string($remote['status'] ?? null) || $remote['status'] === '') {
            throw new EvidenceException('Payments attempt has no canonical status.');
        }
        $failure = is_array($remote['failure_details'] ?? null) ? $remote['failure_details'] : [];
        $method = is_array($remote['payment_method'] ?? null) ? $remote['payment_method'] : [];
        $card = is_array($method['card'] ?? null) ? $method['card'] : [];
        $optional = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new PaymentAttempt(
            $attemptId, $intent->remote_id, $remote['status'], $optional($remote['failure_code'] ?? null),
            $optional($failure['code'] ?? null), $optional($failure['message'] ?? null) ?? $optional($remote['provider_original_response_description'] ?? null),
            $optional($remote['provider_original_response_code'] ?? null), $optional($card['brand'] ?? null),
            $optional($remote['payment_consent_id'] ?? null), $optional($method['id'] ?? null),
        );
    }

    private function latestAttemptId(?array $snapshot): ?string
    {
        $attempt = $snapshot['latest_payment_attempt'] ?? null;
        $id = is_array($attempt) ? ($attempt['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Merchant-initiated saved-instrument charge; no raw card credentials accepted. */
    public function confirmWithConsent(Customer $customer, Intent $intent, string $key, string $consentId, array $options = []): Intent
    {
        $customer = $this->confirmedCustomer($customer);
        $this->options($options, ['return_url', 'payment_method_options']);
        if ($this->requiresInstrumentProof($key)) {
            app(Instruments::class)->verified($customer, $consentId);
        }

        return $this->action($customer, $intent, $key, 'confirm', [...$options, 'payment_consent_id' => $consentId], $consentId);
    }

    /** Customer-present tokenized method, collected by an eligible authorized browser client. */
    public function confirmWithMethod(Customer $customer, Intent $intent, string $key, string $methodId, array $options = []): Intent
    {
        $this->options($options, ['return_url', 'payment_method_options', 'device_data']);
        $customer = $this->confirmedCustomer($customer);
        if ($this->requiresInstrumentProof($key)) {
            app(Instruments::class)->observeMethod($customer, $methodId);
        }

        return $this->action($customer, $intent, $key, 'confirm', [...$options, 'payment_method' => ['id' => $methodId], 'triggered_by' => 'customer'], methodId: $methodId);
    }

    /** Browser 3DS/wallet return is a locator, never financial proof or a server PIN call. */
    public function continueConfirmation(Customer $customer, Intent $intent): Intent
    {
        return $this->reconcile($customer, $intent);
    }

    public function capture(Customer $customer, Intent $intent, string $key, mixed $amount): Intent
    {
        return $this->action($customer, $intent, $key, 'capture', ['amount' => Amount::canonical($amount, $intent->currency)]);
    }

    public function cancel(Customer $customer, Intent $intent, string $key, ?string $reason = null): Intent
    {
        return $this->action($customer, $intent, $key, 'cancel', $reason === null ? [] : ['cancellation_reason' => $reason]);
    }

    public function recover(Customer $customer, Operation $operation, ?string $operatorKnownId = null): Intent
    {
        app(CreationRetries::class)->assertCurrent($operation, $operatorKnownId);
        $this->context->assertModel($operation);
        $customer = $this->confirmedCustomer($customer);
        if ($operation->customer_id !== $customer->id || ! str_starts_with($operation->kind, 'intent.') || $operation->status === 'failed') {
            throw new OwnershipException('Payments intent operation is not recoverable under this customer.');
        }
        $intent = $operation->kind === 'intent.create'
            ? Intent::query()->where('operation_id', $operation->id)->firstOrFail()
            : Intent::query()->where('customer_id', $customer->id)->where('remote_id', $operation->target_id)->firstOrFail();
        if ($operation->status === 'succeeded') {
            return $this->reconcile($customer, $intent);
        }
        $id = $operation->remote_id ?? $operation->target_id ?? $operatorKnownId;
        if ($id === null || ($operatorKnownId !== null && $id !== $operatorKnownId)) {
            throw new AmbiguousOperationException($operation->id);
        }
        try {
            $result = $this->confirm($customer, $intent, $operation, $id);
            if ($operation->remote_id === null) {
                $operation->remote_id = $id;
                $operation->save();
            }
            if ($operation->fresh()->status !== 'acknowledged') {
                $this->operations->succeed($operation);
            }

            return $result;
        } catch (Throwable $exception) {
            throw new AmbiguousOperationException($operation->id, $exception);
        }
    }

    private function action(Customer $customer, Intent $intent, string $key, string $action, array $body, ?string $consentId = null, ?string $methodId = null): Intent
    {
        $customer = $this->confirmedCustomer($customer);
        $intent = $this->owned($customer, $intent);
        if ($intent->remote_id === null) {
            throw new ConflictException('Original intent must be recovered before another action.');
        }
        $operation = DB::transaction(function () use ($customer, $intent, $key, $action, $body, $consentId, $methodId): Operation {
            // Retain the existing identity for saved-card commands across the wire correction.
            $specification = $consentId === null ? $body : [...$body, 'triggered_by' => 'merchant'];
            $operation = $this->operations->prepare($customer, $key, 'intent.'.$action, ['body' => $body, 'before_captured' => $intent->captured_amount], $intent->remote_id, 'customer/'.$customer->id, $specification);
            if ($consentId !== null && $operation->status === 'prepared') {
                // Revocation uses the same customer lock. It cannot silently substitute
                // another instrument after this explicit command has been reserved.
                app(Instruments::class)->assertUsableReservation($customer, $consentId);
            }
            if ($methodId !== null && $operation->status === 'prepared') {
                app(Instruments::class)->assertMethodReservation($customer, $methodId);
            }

            return $operation;
        });
        if ($operation->status !== 'prepared') {
            return $this->recover($customer, $operation);
        }
        $canonical = $this->reconcile($customer, $intent);
        $allowed = match ($action) {
            'confirm' => ['REQUIRES_PAYMENT_METHOD'],
            'capture' => ['REQUIRES_CAPTURE'],
            'cancel' => ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION', 'REQUIRES_CAPTURE'],
            default => throw new ConflictException('Unsupported Payments intent action.'),
        };
        if (! in_array($canonical->status, $allowed, true)) {
            $this->operations->rejectPrepared($operation);
            throw new ConflictException('Canonical intent is not eligible for this financial action.', $operation->id);
        }
        $attempt = null;
        if ($action === 'confirm') {
            try {
                $attempt = $this->readAttempt($canonical);
                if ($attempt !== null && ! in_array($attempt->status, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {
                    throw new ConflictException('An earlier attempt has no confirmed unsuccessful outcome.', $operation->id);
                }
            } catch (Throwable $exception) {
                $this->operations->rejectPrepared($operation);
                throw $exception;
            }
        }
        if ($action === 'capture') {
            $requested = Amount::units($body['amount'], $canonical->currency);
            if ($requested === '0' || $canonical->captured_amount === null
                || $canonical->captured_amount !== ($operation->intent['before_captured'] ?? null)
                || Amount::compare(Amount::add(Amount::units($canonical->captured_amount, $canonical->currency), $requested), Amount::units($canonical->amount, $canonical->currency)) > 0) {
                $this->operations->rejectPrepared($operation);
                throw new ConflictException('Capture exceeds the positively observed authorization.', $operation->id);
            }
        }

        try {
            return $this->operations->execute($operation,
                fn () => app(Client::class)->sdk->payments()->paymentIntents()->{$action}($canonical->remote_id, [...$body, 'request_id' => $operation->request_id]),
                fn () => $this->confirm($customer, $canonical, $operation, $canonical->remote_id),
                function () use ($customer, $canonical, $consentId, $methodId, $operation, $action, $attempt): void {
                    if ($this->owned($customer, $canonical)->revision !== $canonical->revision) {
                        throw new ConflictException('Canonical intent changed before the financial action was claimed.', $operation->id);
                    }
                    if ($consentId !== null) {
                        app(Instruments::class)->assertUsableReservation($customer, $consentId);
                    }
                    if ($methodId !== null) {
                        app(Instruments::class)->assertMethodReservation($customer, $methodId);
                    }
                    if ($action === 'confirm') {
                        $operation->forceFill(['confirmation_baseline' => [
                            'attempt_id' => $attempt?->id,
                            'consent_method_id' => $consentId === null ? null : $this->reservedMethod($customer, $consentId),
                        ]])->save();
                    }
                },
            );
        } catch (EvidenceException|ConflictException $exception) {
            $this->operations->rejectPrepared($operation);
            throw $exception;
        }
    }

    private function confirm(Customer $customer, Intent $intent, Operation $operation, string $id): Intent
    {
        return app(CreationRetries::class)->during($operation, fn () => $this->confirmCurrent($customer, $intent, $operation, $id));
    }

    private function confirmCurrent(Customer $customer, Intent $intent, Operation $operation, string $id): Intent
    {
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->paymentIntents()->retrieve($id));
        if (($remote['request_id'] ?? null) !== $operation->request_id) {
            throw new EvidenceException('Canonical intent does not correlate to the original financial action.');
        }

        $result = $this->observe($customer, $intent, $remote, $id);
        $fulfilled = match ($operation->kind) {
            'intent.capture' => $result->captured_amount !== null
                && Amount::compare(Amount::units($result->captured_amount, $result->currency), Amount::add(Amount::units($operation->intent['before_captured'] ?? '0', $result->currency), Amount::units($operation->intent['body']['amount'], $result->currency))) >= 0,
            'intent.cancel' => $result->status === 'CANCELLED',
            'intent.confirm' => $result->status !== 'REQUIRES_PAYMENT_METHOD'
                || ($operation->confirmation_baseline !== null
                    && $this->latestAttemptId($result->snapshot) !== $operation->confirmation_baseline['attempt_id']
                    && in_array($this->readAttempt($result)?->status, ['FAILED', 'EXPIRED', 'CANCELLED'], true)),
            default => true,
        };
        if (! $fulfilled) {
            Operation::query()->whereKey($operation->id)->whereIn('status', ['in_flight', 'ambiguous', 'acknowledged'])->update(['status' => 'acknowledged', 'error_code' => 'financial_effect_unconfirmed']);
        } elseif ($operation->status === 'acknowledged') {
            $this->operations->succeed($operation);
        }

        return $result;
    }

    private function observe(Customer $customer, Intent $intent, array $remote, string $id): Intent
    {
        $customer = $this->confirmedCustomer($customer);
        Proof::identity($remote, $id, $this->context);
        if (($remote['customer_id'] ?? null) !== $customer->remote_id || ($remote['currency'] ?? null) !== $intent->currency
            || ($remote['merchant_order_id'] ?? null) !== $intent->merchant_order_id
            || Amount::units($remote['amount'] ?? null, $intent->currency) !== Amount::units($intent->amount, $intent->currency)) {
            throw new OwnershipException('Canonical Payments intent does not match original owner, order or amount.');
        }
        if (! is_string($remote['status'] ?? null) || $remote['status'] === '') {
            throw new EvidenceException('Payments intent has no canonical status.');
        }
        if (! is_string($remote['updated_at'] ?? null)) {
            throw new EvidenceException('Payments intent has no versioned observation timestamp.');
        }
        Timestamp::compare($remote['updated_at'], $remote['updated_at']);
        $captured = isset($remote['captured_amount']) ? Amount::canonical($remote['captured_amount'], $intent->currency) : null;
        if ($captured !== null && Amount::compare(Amount::units($captured, $intent->currency), Amount::units($intent->amount, $intent->currency)) > 0) {
            throw new EvidenceException('Captured observation exceeds original intent amount.');
        }

        $attemptId = $this->latestAttemptId($remote);
        if (isset($remote['latest_payment_attempt']) && $attemptId === null) {
            throw new EvidenceException('Latest Payments attempt has no canonical identity.');
        }

        return DB::transaction(function () use ($customer, $intent, $remote, $id, $captured, $attemptId): Intent {
            app(CommitGuard::class)->assertMayCommit();
            $current = $this->owned($customer, $intent);
            if ($current->revision !== $intent->revision || ($current->remote_id !== null && $current->remote_id !== $id)) {
                throw new ConflictException('Intent changed during canonical observation.');
            }
            $previous = $current->snapshot['updated_at'] ?? null;
            if (is_string($previous) && Timestamp::compare($remote['updated_at'], $previous) < 0) {
                throw new EvidenceException('Payments intent observation is older than retained evidence.');
            }
            $previousAttemptId = $this->latestAttemptId($current->snapshot);
            if (is_string($previous) && Timestamp::compare($remote['updated_at'], $previous) === 0
                && ($current->status !== $remote['status'] || $current->captured_amount !== $captured
                    || ($previousAttemptId !== null && $attemptId !== null && $previousAttemptId !== $attemptId))) {
                throw new EvidenceException('Equal-version Payments observations contain contradictory financial evidence.');
            }
            $changed = Intent::query()->whereKey($current->id)->where('revision', $current->revision)->update([
                'revision' => $current->revision + 1,
            ]);
            if ($changed !== 1) {
                throw new ConflictException('Concurrent intent observation must be refreshed.');
            }
            $snapshot = SubscriptionSynchronizer::sanitize($remote);
            unset($snapshot['next_action'], $snapshot['authentication_data']);
            if (is_array($snapshot['latest_payment_attempt'] ?? null)) {
                unset($snapshot['latest_payment_attempt']['next_action'], $snapshot['latest_payment_attempt']['authentication_data']);
            } elseif ($previousAttemptId !== null) {
                $snapshot['latest_payment_attempt'] = ['id' => $previousAttemptId];
            }
            $current->forceFill(['remote_id' => $id, 'status' => $remote['status'], 'captured_amount' => $captured, 'snapshot' => $snapshot])->save();
            $this->observations->record($current, ['remote_id' => $id, 'status' => $current->status, 'amount' => $current->amount, 'currency' => $current->currency, 'captured_amount' => $captured, 'financial_identity' => 'payment_intent/'.$id]);

            return $current->refresh();
        });
    }

    public function owned(Customer $customer, Intent $intent): Intent
    {
        $this->context->assertModel($intent);
        $customer = $this->customers->current($customer);
        $current = Intent::query()->find($intent->id);
        if ($current === null || $current->customer_id !== $customer->id) {
            throw new OwnershipException('Payments intent belongs to another customer or was removed.');
        }
        $this->context->assertModel($current);

        return $current;
    }

    private function reservedMethod(Customer $customer, string $consentId): ?string
    {
        return Instrument::query()->where([
            ...$this->context->attributes(), 'customer_id' => $customer->id, 'remote_id' => $consentId,
        ])->firstOrFail()->method_id;
    }

    private function confirmedCustomer(Customer $customer): Customer
    {
        $customer = $this->customers->current($customer);
        if ($customer->remote_id === null) {
            throw new ConflictException('A confirmed Payments customer is required.');
        }

        return $customer;
    }

    private function requiresInstrumentProof(string $key): bool
    {
        Operations::validateKey($key);
        $operation = Operation::query()->where([...$this->context->attributes(), 'action_key' => $key])->first();

        // The action still verifies the original customer/target/body hash before recovering.
        return $operation === null || $operation->status === 'prepared';
    }

    private function options(array $options, array $allowed): void
    {
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new ConflictException('Payments owner, amount, account, target and request identity cannot be overridden.');
        }
    }
}
