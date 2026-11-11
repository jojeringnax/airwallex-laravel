<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Payments\Models\MethodObservation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Models\PaymentModel;
use Jojeringnax\AirwallexLaravel\Payments\Models\Receipt;
use Jojeringnax\AirwallexLaravel\Payments\Models\Refund;

class Recovery
{
    private const MODELS = ['intent' => Intent::class, 'refund' => Refund::class, 'instrument' => Instrument::class,
        'method' => MethodObservation::class, 'operation' => Operation::class, 'receipt' => Receipt::class, 'event' => Observation::class];

    public function __construct(private readonly Context $context) {}

    public function names(string $selected = 'all'): array
    {
        if ($selected === 'all') {
            return array_keys(self::MODELS);
        }
        if (! isset(self::MODELS[$selected])) {
            throw new ConfigurationException('Unknown Payments recovery family.');
        }

        return [$selected];
    }

    public function query(string $family, bool $one = false): Builder
    {
        $class = self::MODELS[$family] ?? throw new ConfigurationException('Unknown Payments recovery family.');
        $query = $class::query()->where($this->context->attributes());
        if (! $one) {
            match ($family) {
                'intent', 'refund' => $query->whereNotNull('remote_id'),
                'operation' => $query->whereIn('status', ['in_flight', 'ambiguous', 'acknowledged']),
                'receipt' => $query->whereNotNull('body')->where(fn ($query) => $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(fn ($query) => $query->where('status', 'processing')->where('lease_until', '<=', now()->utc()))),
                'event' => $query->whereNull('dispatched_at')->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()->utc())),
                default => $query,
            };
        }

        return $query;
    }

    public function process(string $family, Model $row, ?string $candidate = null): bool
    {
        if (! isset(self::MODELS[$family]) || ! $row instanceof PaymentModel) {
            throw new ConflictException('Payments recovery row does not match its selected family.');
        }
        $this->context->assertModel($row);
        if ($row instanceof Receipt) {
            return app(Receipts::class)->dispatch($row);
        }
        if ($row instanceof Observation) {
            return app(ObservationDispatcher::class)->dispatch($row->id);
        }
        if ($row instanceof Operation && in_array($row->status, ['succeeded', 'failed'], true)) {
            return true;
        }
        if ($row instanceof Operation && $row->status === 'prepared') {
            throw new ConflictException('Recovery never dispatches a prepared Payments command.');
        }
        $customer = Customer::query()->where($this->context->attributes())->findOrFail($row->getAttribute('customer_id'));
        if ($row instanceof Operation) {
            match (true) {
                str_starts_with($row->kind, 'intent.') => app(Intents::class)->recover($customer, $row, $candidate),
                str_starts_with($row->kind, 'refund.') => app(Refunds::class)->recover($customer, $row, $candidate),
                str_starts_with($row->kind, 'consent.') => app(Instruments::class)->recover($row, $candidate),
                str_starts_with($row->kind, 'customer.') => app(Customers::class)->recover($row, $candidate),
                default => throw new ConflictException('Unsupported Payments recovery operation.'),
            };

            return $row->fresh()->status === 'succeeded';
        }
        match (true) {
            $row instanceof Intent => app(Intents::class)->reconcile($customer, $row),
            $row instanceof Refund => app(Refunds::class)->reconcile($customer, $row),
            $row instanceof Instrument => app(Instruments::class)->complete($customer, $row->remote_id),
            $row instanceof MethodObservation => app(Instruments::class)->reconcileMethod($customer, $row->remote_id),
            default => throw new ConflictException('Unsupported Payments recovery resource.'),
        };

        return true;
    }

    /** The independent recovery foundation supplies the common fair engine when installed. */
    public function sweepFamilies(string $selected = 'all'): array
    {
        $familyClass = 'Jojeringnax\\AirwallexLaravel\\Support\\SweepFamily';
        if (! class_exists($familyClass)) {
            throw new ConfigurationException('Durable Payments sweeps require the fair recovery foundation.');
        }
        $families = [];
        foreach ($this->names($selected) as $name) {
            $families[] = new $familyClass('payments_'.$name, $this->context->environment, 'payments', $this->context->accountId,
                fn () => $this->query($name), fn (Model $row) => $this->process($name, $row));
        }

        return $families;
    }
}
