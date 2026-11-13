<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Support\SweepFamily;

class SweepRegistry
{
    /** @var array<string, SweepFamily> */
    private array $families = [];

    public function __construct(BillingContext $context)
    {
        foreach (['customer', 'subscription', 'checkout', 'operation', 'receipt', 'event'] as $name) {
            $model = match ($name) {
                'receipt' => 'webhook_receipt',
                'event' => 'domain_event',
                default => $name,
            };
            $this->register(new SweepFamily($name, $context->environment, 'billing', $context->organizationId,
                function () use ($context, $model, $name) {
                    $query = ModelRegistry::query($model)->where($context->attributes());

                    match ($name) {
                        'customer' => $query->whereNotNull('remote_id'),
                        'operation' => $query->whereIn('status', ['in_flight', 'ambiguous', 'acknowledged', 'superseded']),
                        'receipt' => $query->where(fn ($query) => $query->whereIn('status', ['pending', 'failed'])
                            ->orWhere(fn ($query) => $query->where('status', 'processing')->where('lease_until', '<=', now()->utc()))),
                        'event' => $query->whereNull('dispatched_at')->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()->utc())),
                        default => $query,
                    };

                    return $query;
                },
                function (Model $row) use ($name): bool {
                    return match (true) {
                        $name === 'customer' && $row instanceof Customer => (bool) app(CustomerManager::class)->reconcile($row),
                        $name === 'subscription' && $row instanceof Subscription => (bool) app(SubscriptionSynchronizer::class)->reconcile($row),
                        $name === 'checkout' && $row instanceof Checkout => (bool) app(CheckoutManager::class)->reconcile($row),
                        $name === 'operation' && $row instanceof Operation => $this->recover($row),
                        $name === 'receipt' && $row instanceof WebhookReceipt => app(ReceiptManager::class)->dispatch($row),
                        $name === 'event' => app(OutboxDispatcher::class)->dispatch((string) $row->getKey()),
                        default => throw new ConfigurationException('Configured sweep resource model is invalid.'),
                    };
                },
            ));
        }
    }

    public function register(SweepFamily $family): void
    {
        if (isset($this->families[$family->name])) {
            throw new ConfigurationException('Sweep family names must be unique within the configured registry.');
        }
        $this->families[$family->name] = $family;
    }

    /** @return list<SweepFamily> */
    public function families(string $name = 'all'): array
    {
        if ($name === 'all') {
            return array_values($this->families);
        }

        return [$this->families[$name] ?? throw new ConfigurationException('Unknown sweep family.')];
    }

    private function recover(Operation $operation): bool
    {
        app(RecoveryManager::class)->operation($operation);

        return $operation->fresh()->status === 'succeeded';
    }
}
