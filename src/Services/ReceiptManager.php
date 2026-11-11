<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\Airwallex\Webhook\WebhookVerifier;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Jobs\ProcessWebhookReceipt;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Support\BillingWebhookEnvelope;
use Jojeringnax\AirwallexLaravel\Support\QueueRouting;
use SensitiveParameter;
use Throwable;

class ReceiptManager
{
    private const TERMINAL = ['processed', 'ignored', 'quarantined'];

    public function __construct(private readonly BillingContext $context) {}

    public function accept(#[SensitiveParameter] string $rawBody, #[SensitiveParameter] string $timestamp, #[SensitiveParameter] string $signature): WebhookReceipt
    {
        $secret = config('airwallex.webhook.secret');
        $tolerance = config('airwallex.webhook.tolerance_milliseconds');
        if (! is_string($secret) || ! is_int($tolerance)) {
            throw new ConfigurationException('Webhook secret and integer millisecond tolerance must be configured.');
        }
        $version = $this->receiverVersion();
        (new WebhookVerifier($secret, $tolerance, fn () => now()->getTimestampMs()))->verify($rawBody, $timestamp, $signature);
        $digest = hash('sha256', $rawBody);
        $metadata = $this->inspect($rawBody, $version);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use ($metadata, $digest, $rawBody): WebhookReceipt {
                    /** @var WebhookReceipt|null $existing */
                    $existing = $this->query()->where('event_id', $metadata['eventId'])->first();
                    if ($existing) {
                        if (! hash_equals($existing->digest, $digest)) {
                            $this->query()->whereKey($existing->id)->update(['status' => 'quarantined', 'last_error' => 'event_digest_conflict', 'lease_token' => null, 'lease_until' => null]);
                        }

                        return $this->load($existing);
                    }
                    /** @var WebhookReceipt $receipt */
                    $receipt = ModelRegistry::make('webhook_receipt', [...$this->context->attributes(), 'event_id' => $metadata['eventId'], 'digest' => $digest, 'event_name' => $metadata['eventName'], 'version' => $metadata['version'], 'resource_id' => $metadata['resourceId'], 'body' => $rawBody, 'status' => $metadata['status'], 'last_error' => $this->reason($metadata['reason']), 'attempts' => 0]);
                    $receipt->save();

                    return $receipt;
                });
            } catch (QueryException) {
                // The unique identity can have been committed by another receiver.
                // One re-read converges; a busy/unavailable store remains retryable.
            } catch (Throwable) {
                throw new EvidenceException('Webhook receipt acceptance could not be persisted.');
            }
        }

        throw new EvidenceException('Webhook receipt acceptance could not be persisted.');
    }

    public function dispatch(WebhookReceipt|string $receipt): bool
    {
        $stored = $this->load($receipt);
        if (! $this->eligible()->whereKey($stored->id)->exists()) {
            return false;
        }
        try {
            $this->leaseSeconds();
            $routing = QueueRouting::for('webhook');
            $job = new ProcessWebhookReceipt($stored->id, $this->context->environment, $this->context->organizationId);
            if ($routing['queue'] !== null) {
                $job->onQueue($routing['queue']);
            }
            if ($routing['connection'] !== null) {
                $job->onConnection($routing['connection']);
            }
            app(Dispatcher::class)->dispatch($job);

            return true;
        } catch (Throwable $error) {
            try {
                $this->query()->whereKey($stored->id)->whereIn('status', ['pending', 'failed'])->update(['last_error' => $error instanceof ConfigurationException ? 'dispatch_configuration' : 'dispatch_failed']);
            } catch (Throwable) {
                // Durable pending work remains discoverable even if diagnostics fail.
            }

            return false;
        }
    }

    public function process(string $receiptId): WebhookReceipt
    {
        $receipt = $this->load($receiptId);
        $token = (string) Str::uuid();
        $changed = $this->eligible()->whereKey($receiptId)->update(['status' => 'processing', 'lease_token' => $token, 'lease_until' => now()->utc()->addSeconds($this->leaseSeconds()), 'attempts' => DB::raw('attempts + 1'), 'last_error' => null]);
        if ($changed !== 1) {
            return $this->load($receipt);
        }
        $receipt = $this->load($receipt);
        try {
            $this->assertLease($receipt->id, $token, $receipt->digest);
            $processor = app(BillingWebhookProcessor::class);
            $outcome = app(CommitGuard::class)->during(fn () => $this->assertLease($receipt->id, $token, $receipt->digest), fn () => $processor->process($receipt));
            if (! in_array($outcome->status, [...self::TERMINAL, 'failed'], true)) {
                throw new EvidenceException('Receipt processing returned an unsupported classification.');
            }
            DB::transaction(function () use ($receipt, $token, $processor, $outcome): void {
                $this->assertLease($receipt->id, $token, $receipt->digest);
                $changed = $this->lease($receipt->id, $token, $receipt->digest)->update(['status' => $outcome->status, 'last_error' => $this->reason($outcome->reason), 'lease_token' => null, 'lease_until' => null]);
                if ($changed !== 1) {
                    throw new ConflictException('Receipt processing lease was superseded.');
                }
                $processor->recordOutcome($this->load($receipt), $outcome);
            });
        } catch (Throwable) {
            try {
                $this->lease($receipt->id, $token, $receipt->digest)->update(['status' => 'failed', 'last_error' => 'processing_failed', 'lease_token' => null, 'lease_until' => null]);
            } catch (Throwable) {
                throw new EvidenceException('Webhook processing could not persist its retryable outcome.');
            }
        }

        return $this->load($receipt);
    }

    public function requestReplay(WebhookReceipt|string $receipt): WebhookReceipt
    {
        $stored = $this->load($receipt);
        if ($stored->body === null || $stored->last_error === 'event_digest_conflict') {
            throw new ConflictException('Purged or conflicting webhook evidence cannot be replayed.');
        }
        if ($stored->lease_token !== null && $stored->lease_until !== null && $stored->lease_until->isAfter(now())) {
            throw new ConflictException('Receipt has an active processing lease.');
        }
        if (! hash_equals($stored->digest, hash('sha256', $stored->body))) {
            throw new EvidenceException('Retained webhook evidence does not match its accepted digest.');
        }
        $metadata = $this->inspect($stored->body, $this->receiverVersion());
        if ($metadata['eventId'] !== $stored->event_id || $metadata['eventName'] !== $stored->event_name || $metadata['version'] !== $stored->version || $metadata['resourceId'] !== $stored->resource_id) {
            throw new EvidenceException('Replay envelope conflicts with its accepted receipt identity.');
        }
        $changed = $this->query()->whereKey($stored->id)->where('digest', $stored->digest)->where('status', $stored->status)->where('last_error', $stored->last_error)->where('lease_token', $stored->lease_token)->where('body', $stored->getRawOriginal('body'))->update(['status' => $metadata['status'], 'last_error' => $this->reason($metadata['reason']), 'lease_token' => null, 'lease_until' => null]);
        if ($changed !== 1) {
            throw new ConflictException('Receipt changed while replay was requested.');
        }

        return $this->load($stored);
    }

    public function replay(int $limit = 100): int
    {
        $this->batchLimit($limit);
        $count = 0;
        foreach ($this->eligible()->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $count += $this->dispatch($id) ? 1 : 0;
        }

        return $count;
    }

    public function prune(int $limit = 100): int
    {
        $this->batchLimit($limit);
        $days = config('airwallex.webhook.retention_days');
        if (! is_int($days) || $days < 0) {
            throw new ConfigurationException('Webhook retention days must be a non-negative integer.');
        }
        $cutoff = now()->utc()->subDays($days);
        $ids = $this->query()->whereIn('status', self::TERMINAL)->whereNotNull('body')->where('updated_at', '<=', $cutoff)->orderBy('id')->limit($limit)->pluck('id');

        return $this->query()->whereIn('id', $ids)->whereIn('status', self::TERMINAL)->whereNotNull('body')->where('updated_at', '<=', $cutoff)->update(['body' => null]);
    }

    private function query(): Builder
    {
        return ModelRegistry::query('webhook_receipt')->where($this->context->attributes());
    }

    private function load(WebhookReceipt|string $receipt): WebhookReceipt
    {
        if ($receipt instanceof WebhookReceipt) {
            $this->context->assertModel($receipt);
        }
        /** @var WebhookReceipt $stored */
        $stored = $this->query()->findOrFail($receipt instanceof WebhookReceipt ? $receipt->id : $receipt);
        $this->context->assertModel($stored);

        return $stored;
    }

    private function eligible(): Builder
    {
        $eligible = $this->query();
        $eligible->whereNotNull('body')->where(function (Builder $query): void {
            $query->whereIn('status', ['pending', 'failed'])->orWhere(function (Builder $processing): void {
                $processing->where('status', 'processing')->where('lease_until', '<=', now()->utc());
            });
        });

        return $eligible;
    }

    private function lease(string $receiptId, string $token, string $digest): Builder
    {
        return $this->query()->whereKey($receiptId)->where('status', 'processing')->where('lease_token', $token)->where('digest', $digest)->where('lease_until', '>', now()->utc());
    }

    private function assertLease(string $receiptId, string $token, string $digest): void
    {
        if ($this->lease($receiptId, $token, $digest)->lockForUpdate()->first() === null) {
            throw new ConflictException('Receipt processing lease was superseded.');
        }
    }

    /** @return array{eventId:string,eventName:?string,version:?string,resourceId:?string,family:?string,resource:?array,status:string,reason:?string} */
    private function inspect(#[SensitiveParameter] string $body, string $version): array
    {
        $envelope = WebhookEvent::fromJson($body)->toArray();
        $metadata = BillingWebhookEnvelope::inspect($envelope, $this->context, $version);
        if (str_starts_with($metadata['eventId'], 'invalid/') && ($envelope['id'] ?? null) !== $metadata['eventId']) {
            $metadata['eventId'] = 'invalid/'.hash('sha256', $body);
        }

        return $metadata;
    }

    private function receiverVersion(): string
    {
        if (config('airwallex.webhook.version') !== '2026-08-21') {
            throw new ConfigurationException('Webhook receiver must explicitly select the supported contract version.');
        }

        return '2026-08-21';
    }

    private function leaseSeconds(): int
    {
        $seconds = config('airwallex.webhook.lease_seconds');
        if (! is_int($seconds) || $seconds < 1) {
            throw new ConfigurationException('Webhook lease seconds must be a positive integer.');
        }

        return $seconds;
    }

    private function batchLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new ConfigurationException('Receipt batch limit must be between one and one thousand.');
        }
    }

    private function reason(?string $reason): ?string
    {
        return $reason === null || preg_match('/^[a-z0-9_]{1,80}$/D', $reason) ? $reason : 'unclassified_receipt_outcome';
    }
}
