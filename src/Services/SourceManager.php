<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Customers as PaymentsCustomers;
use Jojeringnax\AirwallexLaravel\Payments\Instruments;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer as PaymentsCustomer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\PaymentSource;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\Pages;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use SensitiveParameter;

class SourceManager
{
    public function __construct(private readonly Client $client, private readonly BillingContext $context) {}

    private const MARKER = '_laravel_source_key';

    /** Create an owned Billing Source after the browser has verified the Payments Consent. */
    public function createFromInstrument(PaymentsCustomer $paymentsCustomer, string $consentId, Customer $customer, string $actionKey): PaymentSource
    {
        $customer = $this->customer($customer);
        $paymentsCustomer = $this->bridge($paymentsCustomer, $customer);
        $specification = ['payments_customer_id' => $paymentsCustomer->id, 'consent_id' => $consentId];
        $existing = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => $actionKey])->first();
        $intent = $existing?->intent;
        if ($intent === null) {
            $instrument = app(Instruments::class)->verified($paymentsCustomer, $consentId);
            $intent = [...$specification, 'payments_account_id' => $paymentsCustomer->account_id,
                'body' => ['billing_customer_id' => $customer->remote_id, 'external_id' => $instrument->method_id,
                    'linked_payment_account_id' => $paymentsCustomer->account_id,
                    'metadata' => [self::MARKER => hash('sha256', CanonicalJson::encode([$customer->id, $actionKey]))]]];
        }
        $operations = app(OperationManager::class);
        $operation = $operations->prepare($customer, $actionKey, 'payment_source.create', $specification, $intent);
        if ($operation->status !== 'prepared') {
            return $this->recover($operation);
        }
        $instrument = app(Instruments::class)->verified($paymentsCustomer, $consentId);
        if ($instrument->method_id !== ($operation->intent['body']['external_id'] ?? null)) {
            throw new EvidenceException('Consent Method changed after the source command was prepared.');
        }

        return $operations->execute($operation,
            function () use ($paymentsCustomer, $consentId, $operation): array {
                // Claim eligibility under the same Customer lock used by local revocation.
                DB::transaction(function () use ($paymentsCustomer, $consentId, $operation): void {
                    app(CommitGuard::class)->assertMayCommit();
                    PaymentsCustomer::query()->whereKey($paymentsCustomer->id)->lockForUpdate()->firstOrFail();
                    app(Instruments::class)->assertUsableReservation($paymentsCustomer, $consentId);
                    $current = Instrument::query()->where('customer_id', $paymentsCustomer->id)->where('remote_id', $consentId)->firstOrFail();
                    if ($current->method_id !== $operation->intent['body']['external_id']) {
                        throw new EvidenceException('Consent Method changed before the source dispatch claim.');
                    }
                });

                return $this->client->billing()->paymentSources()->create([...$operation->intent['body'], 'request_id' => $operation->request_id]);
            },
            function (#[SensitiveParameter] array $response) use ($customer, $operation): PaymentSource {
                $this->proof($customer, $operation, $response);

                return $this->proof($customer, $operation, SdkResponseShape::normalize($this->client->billing()->paymentSources()->retrieve($response['id'])), $response['id']);
            });
    }

    /** A candidate is only a locator; canonical original correlation establishes identity. */
    public function recover(Operation $operation, ?string $candidateId = null): PaymentSource
    {
        app(CreationRetries::class)->assertCurrent($operation, $candidateId);
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->findOrFail($operation->id);
        if ($operation->kind !== 'payment_source.create' || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Only attempted source creation supports read recovery.', $operation->id);
        }
        $customer = $this->customer(ModelRegistry::query('customer')->findOrFail($operation->customer_id));
        $id = $operation->remote_id ?? $candidateId;
        if ($id === null) {
            throw new AmbiguousOperationException($operation->id);
        }
        if ($candidateId !== null && $id !== $candidateId) {
            throw new OwnershipException('Source recovery cannot replace its original resource identity.');
        }
        $source = $this->proof($customer, $operation, SdkResponseShape::normalize($this->client->billing()->paymentSources()->retrieve($id)), $id);
        app(CreationRetries::class)->assertCurrent($operation, $id);
        DB::transaction(function () use ($operation, $id): void {
            app(CommitGuard::class)->assertMayCommit();
            app(CreationRetries::class)->assertCurrent($operation, $id);
            $query = ModelRegistry::query('operation')->whereKey($operation->id);
            $query->lockForUpdate();
            $current = $query->firstOrFail();
            if (! in_array($current->status, ['in_flight', 'ambiguous', 'succeeded'], true) || ($current->remote_id !== null && $current->remote_id !== $id)) {
                throw new ConflictException('Source operation changed during recovery.', $operation->id);
            }
            if ($current->remote_id === null) {
                $current->remote_id = $id;
                $current->save();
            }
            app(OperationManager::class)->succeed($current);
        });

        return $source;
    }

    private function bridge(PaymentsCustomer $paymentsCustomer, Customer $customer): PaymentsCustomer
    {
        $paymentsCustomer = app(PaymentsCustomers::class)->current($paymentsCustomer);
        if ($paymentsCustomer->remote_id === null || $customer->billable_type !== $paymentsCustomer->getAttribute('billable_type')
            || $customer->billable_id !== $paymentsCustomer->getAttribute('billable_id') || $customer->environment !== $paymentsCustomer->environment
            || Routing::collector() !== $paymentsCustomer->account_id) {
            throw new OwnershipException('Billing source requires the same billable, environment and linked Payments account.');
        }

        return $paymentsCustomer;
    }

    private function proof(Customer $customer, Operation $operation, #[SensitiveParameter] array $remote, ?string $id = null): PaymentSource
    {
        $source = $this->verify($customer, $remote, $id);
        $body = $operation->intent['body'] ?? [];
        if ($source->linkedPaymentAccountId !== ($operation->intent['payments_account_id'] ?? null)
            || ($remote['external_id'] ?? null) !== ($body['external_id'] ?? null)
            || ! is_string($body['metadata'][self::MARKER] ?? null)
            || ($remote['metadata'][self::MARKER] ?? null) !== $body['metadata'][self::MARKER]) {
            throw new OwnershipException('Payment source does not prove its original Method, account and creation correlation.');
        }

        return $source;
    }

    public function retrieve(Customer $customer, string $id): PaymentSource
    {
        $customer = $this->customer($customer);

        return $this->verify($customer, SdkResponseShape::normalize($this->client->billing()->paymentSources()->retrieve($id)), $id);
    }

    /** @return Collection<int,PaymentSource> */
    public function all(Customer $customer, array $query = []): Collection
    {
        $customer = $this->customer($customer);
        $scope = ['billing_customer_id' => $customer->remote_id, 'linked_payment_account_id' => Routing::collector()];
        foreach ($scope as $key => $value) {
            if (array_key_exists($key, $query) && $query[$key] !== $value) {
                throw new OwnershipException('Payment source filters cannot change the mapped customer or collector.');
            }
        }
        $items = Pages::all(fn (array $page) => $this->client->billing()->paymentSources()->list($page), [...$query, ...$scope]);

        return collect(array_map(fn (array $item) => $this->verify($customer, $item), $items));
    }

    private function customer(Customer $customer): Customer
    {
        $this->context->assertModel($customer);
        if (! $customer->exists) {
            throw new ConflictException('A confirmed customer mapping is required.');
        }
        $customer = ModelRegistry::query('customer')->findOrFail($customer->getKey());
        $this->context->assertModel($customer);
        if ($customer->remote_id === null) {
            throw new ConflictException('A confirmed customer mapping is required.');
        }

        return $customer;
    }

    private function verify(Customer $customer, #[SensitiveParameter] array $remote, ?string $expectedId = null): PaymentSource
    {
        $id = $remote['id'] ?? null;
        if (! is_string($id) || $id === '' || ($expectedId !== null && $id !== $expectedId)
            || ($remote['billing_customer_id'] ?? null) !== $customer->remote_id
            || ($remote['linked_payment_account_id'] ?? null) !== Routing::collector()) {
            throw new OwnershipException('Payment source identity, customer or collector does not match.');
        }
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $remote) && $remote[$key] !== $expected) {
                throw new OwnershipException('Payment source belongs to a foreign context.');
            }
        }
        if (! is_string($remote['external_id'] ?? null) || $remote['external_id'] === '') {
            throw new EvidenceException('Payment source method linkage is not confirmed.');
        }

        return new PaymentSource($id, $remote['billing_customer_id'], $remote['linked_payment_account_id'], SubscriptionSynchronizer::sanitize($remote));
    }
}
