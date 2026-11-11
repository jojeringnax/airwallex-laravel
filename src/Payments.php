<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Customers;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;

/** Explicit provider-qualified entry point; the application authorizes the model first. */
final readonly class Payments
{
    public function __construct(private Model $billable) {}

    public static function for(Model $authorizedBillable): self
    {
        return new self($authorizedBillable);
    }

    public function customer(): ?Customer
    {
        return app(Customers::class)->local($this->billable);
    }

    public function createCustomer(string $actionKey, array $profile = []): Customer
    {
        return app(Customers::class)->create($this->billable, $actionKey, $profile);
    }

    public function linkCustomer(string $remoteId): Customer
    {
        return app(Customers::class)->link($this->billable, $remoteId);
    }

    public function syncCustomer(string $actionKey, array $profile): Customer
    {
        return app(Customers::class)->sync($this->requiredCustomer(), $actionKey, $profile);
    }

    public function recoverCustomer(string $operationId, ?string $operatorKnownId = null): Customer
    {
        $operation = Operation::query()->findOrFail($operationId);
        app(Context::class)->assertModel($operation);
        if ($operation->customer_id !== $this->requiredCustomer()->id) {
            throw new OwnershipException('Payments operation belongs to another billable.');
        }

        return app(Customers::class)->recover($operation, $operatorKnownId);
    }

    public function offboard(): void
    {
        app(Customers::class)->offboard($this->requiredCustomer());
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'Airwallex Payments'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Recreate Payments access after authorizing the persisted billable.');
    }

    private function requiredCustomer(): Customer
    {
        $customer = $this->customer();
        if ($customer === null) {
            throw new ConflictException('A mapped Payments customer is required.');
        }

        return $customer;
    }
}
