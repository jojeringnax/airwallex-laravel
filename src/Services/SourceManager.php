<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Support\Collection;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\PaymentSource;
use Jojeringnax\AirwallexLaravel\Support\Pages;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;

class SourceManager
{
    public function __construct(private readonly Client $client, private readonly BillingContext $context) {}

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

    private function verify(Customer $customer, array $remote, ?string $expectedId = null): PaymentSource
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
