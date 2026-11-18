<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Dispute;
use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;

class Disputes
{
    public function __construct(private readonly Context $context, private readonly Intents $intents, private readonly Observations $observations) {}

    public function reconcile(Customer $customer, Intent $intent, string $disputeId): Dispute
    {
        $intent = $this->confirmedIntent($customer, $intent);

        return $this->observe($disputeId, $intent) ?? throw new OwnershipException('Payment dispute has no owned intent.');
    }

    /** @return array{items:list<Dispute>,nextCursor:?string} */
    public function page(Customer $customer, Intent $intent, string $fromUpdatedAt, string $toUpdatedAt, ?string $cursor = null, int $pageSize = 100): array
    {
        $intent = $this->confirmedIntent($customer, $intent);
        if ($pageSize < 1 || $pageSize > 1000 || Timestamp::compare($fromUpdatedAt, $toUpdatedAt) > 0) {
            throw new EvidenceException('Payment dispute recovery window or page size is invalid.');
        }
        $page = SdkResponseShape::normalize(app(Client::class)->sdk->payments()->paymentDisputes()->list([
            'payment_intent_id' => $intent->remote_id,
            'from_updated_at' => $fromUpdatedAt,
            'to_updated_at' => $toUpdatedAt,
            'page' => $cursor ?? '0',
            'size' => $pageSize,
        ]));
        $next = $page['page_after'] ?? null;
        if ($next !== null && (! is_string($next) || $next === '' || $next === ($cursor ?? '0'))) {
            throw new EvidenceException('Payment dispute recovery cursor is malformed or repeated.');
        }

        $items = Proof::pageItems($page);
        foreach ($items as $item) {
            Proof::identity($item, $item['id'], $this->context);
        }

        return [
            'items' => array_map(fn (array $item) => $this->observe($item['id'], $intent)
                ?? throw new OwnershipException('Payment dispute has no owned intent.'), $items),
            'nextCursor' => $next,
        ];
    }

    /** @internal Canonical association for the configured account, with no caller-provided owner. */
    public function observe(string $id, ?Intent $expected = null): ?Dispute
    {
        $sdk = app(Client::class)->sdk->payments();
        $remote = SdkResponseShape::normalize($sdk->paymentDisputes()->retrieve($id));
        Proof::identity($remote, $id, $this->context);

        $intentId = Proof::identifier($remote['payment_intent_id'] ?? null);
        $attemptId = Proof::identifier($remote['payment_attempt_id'] ?? null);
        $attempt = null;
        if ($attemptId !== null) {
            $attempt = SdkResponseShape::normalize($sdk->paymentAttempts()->retrieve($attemptId));
            Proof::identity($attempt, $attemptId, $this->context);
            $attemptIntentId = Proof::identifier($attempt['payment_intent_id'] ?? null);
            if ($attemptIntentId === null || ($intentId !== null && $intentId !== $attemptIntentId)) {
                throw new OwnershipException('Payment dispute attempt has a contradictory original intent.');
            }
            $intentId = $attemptIntentId;
        }
        if ($intentId === null) {
            throw new EvidenceException('Payment dispute has no canonical intent or linked attempt.');
        }
        $intent = Intent::query()->where($this->context->attributes())->where('remote_id', $intentId)->first();
        if ($expected !== null && $intent?->id !== $expected->id) {
            throw new OwnershipException('Payment dispute belongs to another intent.');
        }
        if ($intent === null) {
            return null;
        }
        if (isset($attempt['currency']) && $attempt['currency'] !== $intent->currency) {
            throw new OwnershipException('Payment dispute attempt has a contradictory currency.');
        }
        $customer = Customer::query()->where($this->context->attributes())->findOrFail($intent->customer_id);
        if (isset($remote['customer_id']) && $remote['customer_id'] !== $customer->remote_id) {
            throw new OwnershipException('Payment dispute has a contradictory customer.');
        }
        $status = Proof::identifier($remote['status'] ?? null)
            ?? throw new EvidenceException('Payment dispute has no canonical status.');
        $reason = $remote['reason'] ?? null;
        $details = [
            ...Proof::exactAmount($remote['amount'] ?? null, $remote['currency'] ?? null),
            'payment_attempt_id' => $attemptId,
            'status' => $status,
            'stage' => Proof::identifier($remote['stage'] ?? null),
            'due_at' => isset($remote['due_at']) && $remote['due_at'] !== '' ? Proof::timestamp($remote['due_at']) : null,
            'provider_updated_at' => Proof::timestamp($remote['updated_at'] ?? null),
            'reason' => is_array($reason) ? [
                'description' => is_string($reason['description'] ?? null) ? $reason['description'] : null,
                'original_code' => is_string($reason['original_code'] ?? null) ? $reason['original_code'] : null,
                'type' => is_string($reason['type'] ?? null) ? $reason['type'] : null,
            ] : null,
        ];

        return $this->persist($intent, $id, $details);
    }

    private function confirmedIntent(Customer $customer, Intent $intent): Intent
    {
        $intent = $this->intents->owned($customer, $intent);
        if ($intent->remote_id === null) {
            throw new ConflictException('Recover the original intent creation before reading disputes.');
        }

        return $intent;
    }

    private function persist(Intent $intent, string $id, array $details): Dispute
    {
        return DB::transaction(function () use ($intent, $id, $details): Dispute {
            app(CommitGuard::class)->assertMayCommit();
            $identity = [...$this->context->attributes(), 'remote_id' => $id];
            $query = Dispute::query()->where($identity);
            $query->lockForUpdate();
            $stored = $query->first();
            if ($stored === null) {
                $created = Dispute::query()->createOrFirst($identity, [
                    'intent_id' => $intent->id, 'customer_id' => $intent->customer_id, ...$details,
                ]);
                if ($created->wasRecentlyCreated) {
                    $this->record($created, $intent);

                    return $created;
                }
                $stored = $query->firstOrFail();
            }
            if ($stored->intent_id !== $intent->id || ($stored->payment_attempt_id !== null
                && $details['payment_attempt_id'] !== null && $stored->payment_attempt_id !== $details['payment_attempt_id'])) {
                throw new OwnershipException('Payment dispute has a contradictory original payment.');
            }
            $version = Timestamp::compare($details['provider_updated_at'], $stored->provider_updated_at);
            if ($version < 0) {
                return $stored;
            }
            $details['payment_attempt_id'] ??= $stored->payment_attempt_id;
            if ($version === 0) {
                foreach (['status', 'stage', 'amount', 'currency', 'payment_attempt_id', 'due_at', 'reason'] as $key) {
                    if ($stored->getAttribute($key) !== $details[$key]) {
                        throw new EvidenceException('Equal-version dispute observations contradict retained evidence.');
                    }
                }

                return $stored;
            }
            $stored->forceFill($details)->save();
            $this->record($stored, $intent);

            return $stored;
        }, 3);
    }

    private function record(Dispute $dispute, Intent $intent): void
    {
        $this->observations->record($dispute, [
            'remote_id' => $dispute->remote_id,
            'payment_intent_id' => $intent->remote_id,
            'payment_attempt_id' => $dispute->payment_attempt_id,
            'status' => $dispute->status,
            'stage' => $dispute->stage,
            'amount' => $dispute->amount,
            'currency' => $dispute->currency,
            'due_at' => $dispute->due_at,
            'provider_updated_at' => $dispute->provider_updated_at,
            'financial_identity' => 'payment_dispute/'.$dispute->remote_id,
        ]);
    }
}
