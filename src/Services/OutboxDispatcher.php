<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Events;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Throwable;

class OutboxDispatcher
{
    public function __construct(private readonly BillingContext $context, private readonly Dispatcher $events) {}

    /** False means already delivered or leased elsewhere. Listener failures remain retryable. */
    public function dispatch(string $eventId): bool
    {
        $seconds = config('airwallex.events.lease_seconds', 300);
        if (! is_int($seconds) || $seconds < 1) {
            throw new ConfigurationException('Domain event lease must be a positive integer.');
        }
        $token = (string) Str::uuid();
        $claim = ModelRegistry::query('domain_event')->where($this->context->attributes())->whereKey($eventId)->whereNull('dispatched_at')
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()));
        if ($claim->update(['lease_token' => $token, 'lease_until' => now()->addSeconds($seconds), 'attempts' => DB::raw('attempts + 1')]) !== 1) {
            return false;
        }
        try {
            $row = ModelRegistry::query('domain_event')->where($this->context->attributes())->findOrFail($eventId);
            $class = self::eventClass($row->getAttribute('event_name'));
            $this->events->dispatch(new $class($row->id, $row->environment, $row->organization_id, $row->getAttribute('resource_type'), $row->getAttribute('resource_id'), $row->getAttribute('payload')));

            return ModelRegistry::query('domain_event')->where($this->context->attributes())->whereKey($eventId)->where('lease_token', $token)->whereNull('dispatched_at')
                ->update(['dispatched_at' => now(), 'lease_token' => null, 'lease_until' => null]) === 1;
        } catch (Throwable $exception) {
            ModelRegistry::query('domain_event')->where($this->context->attributes())->whereKey($eventId)->where('lease_token', $token)
                ->update(['lease_token' => null, 'lease_until' => null]);
            throw $exception;
        }
    }

    /** @return class-string<Events\BillingEvent> */
    public static function eventClass(string $name): string
    {
        return match ($name) {
            'CreationRetryObserved' => Events\CreationRetryObserved::class,
            'SubscriptionReconciled' => Events\SubscriptionReconciled::class,
            'CheckoutReconciled' => Events\CheckoutReconciled::class,
            'InvoiceObserved' => Events\InvoiceObserved::class,
            'WebhookProcessed' => Events\WebhookProcessed::class,
            default => throw new EvidenceException('Unsupported domain event name.'),
        };
    }
}
