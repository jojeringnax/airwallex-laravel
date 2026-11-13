<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jojeringnax\AirwallexLaravel\Contracts\InstrumentRevocationHandler;
use Jojeringnax\AirwallexLaravel\Events\PaymentObserved;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Payments\Jobs\DispatchObservation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Throwable;

class ObservationDispatcher
{
    public function __construct(private readonly Context $context, private readonly Events $events) {}

    public function enqueue(string $id): bool
    {
        try {
            $row = Observation::query()->where($this->context->attributes())->findOrFail($id);
            if ($row->getAttribute('dispatched_at') !== null) {
                return false;
            }
            $routing = QueueRouting::for('events');
            app(Bus::class)->dispatch((new DispatchObservation($id, $this->context->environment, $this->context->accountId))->onQueue($routing['queue'])->onConnection($routing['connection']));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function dispatch(string $id): bool
    {
        $seconds = config('airwallex.payments.events.lease_seconds', 300);
        if (! is_int($seconds) || $seconds < 1) {
            throw new ConfigurationException('Payments observation lease must be a positive integer.');
        }
        $token = (string) Str::uuid();
        $query = Observation::query()->where($this->context->attributes())->whereKey($id)->whereNull('dispatched_at');
        if ((clone $query)->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()->utc()))
            ->update(['lease_token' => $token, 'lease_until' => now()->utc()->addSeconds($seconds), 'attempts' => DB::raw('attempts + 1')]) !== 1) {
            return false;
        }
        try {
            $row = Observation::query()->where($this->context->attributes())->findOrFail($id);
            $event = new PaymentObserved($row->id, $row->environment, $this->context->accountId, $row->getAttribute('resource_type'), $row->getAttribute('resource_id'), $row->getAttribute('payload'));
            $this->events->dispatch($event);
            if ($event->resourceType === (new Instrument)->getTable()
                && (($event->payload['revoked_at'] ?? null) !== null || ($event->payload['status'] ?? null) === 'DISABLED' || ($event->payload['method_status'] ?? null) === 'DISABLED')) {
                $handlerClass = config('airwallex.payments.events.instrument_revoked_handler');
                if ($handlerClass !== null) {
                    if (! is_string($handlerClass) || ! is_a($handlerClass, InstrumentRevocationHandler::class, true)) {
                        throw new ConfigurationException('Instrument revocation handler must implement InstrumentRevocationHandler.');
                    }
                    app($handlerClass)->handle($event);
                }
            }

            return $query->where('lease_token', $token)->where('lease_until', '>', now()->utc())->update(['dispatched_at' => now()->utc(), 'lease_token' => null, 'lease_until' => null]) === 1;
        } catch (Throwable $error) {
            Observation::query()->where($this->context->attributes())->whereKey($id)->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null]);
            throw $error;
        }
    }
}
