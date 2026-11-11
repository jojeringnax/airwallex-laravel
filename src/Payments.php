<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Customers;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
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

    public function createIntent(string $actionKey, string $merchantOrderId, mixed $amount, string $currency, array $options = []): Intent
    {
        return app(Payments\Intents::class)->create($this->requiredCustomer(), $actionKey, $merchantOrderId, $amount, $currency, $options);
    }

    public function createIntentMinor(string $actionKey, string $merchantOrderId, mixed $minorUnits, string $currency, array $options = []): Intent
    {
        return $this->createIntent($actionKey, $merchantOrderId, Payments\Amount::fromMinor($minorUnits, $currency), $currency, $options);
    }

    public function reconcileIntent(Intent $intent): Intent
    {
        return app(Payments\Intents::class)->reconcile($this->requiredCustomer(), $intent);
    }

    public function intentBootstrap(Intent $intent): Payments\BrowserIntent
    {
        return app(Payments\Intents::class)->bootstrap($this->requiredCustomer(), $intent);
    }

    public function confirmIntent(Intent $intent, string $actionKey, string $consentId, array $options = []): Intent
    {
        return app(Payments\Intents::class)->confirmWithConsent($this->requiredCustomer(), $intent, $actionKey, $consentId, $options);
    }

    public function confirmIntentWithMethod(Intent $intent, string $actionKey, string $methodId, array $options = []): Intent
    {
        return app(Payments\Intents::class)->confirmWithMethod($this->requiredCustomer(), $intent, $actionKey, $methodId, $options);
    }

    public function continueIntent(Intent $intent): Intent
    {
        return app(Payments\Intents::class)->continueConfirmation($this->requiredCustomer(), $intent);
    }

    public function captureIntent(Intent $intent, string $actionKey, mixed $amount): Intent
    {
        return app(Payments\Intents::class)->capture($this->requiredCustomer(), $intent, $actionKey, $amount);
    }

    public function cancelIntent(Intent $intent, string $actionKey, ?string $reason = null): Intent
    {
        return app(Payments\Intents::class)->cancel($this->requiredCustomer(), $intent, $actionKey, $reason);
    }

    public function recoverIntent(string $operationId, ?string $operatorKnownId = null): Intent
    {
        return app(Payments\Intents::class)->recover($this->requiredCustomer(), Operation::query()->findOrFail($operationId), $operatorKnownId);
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
