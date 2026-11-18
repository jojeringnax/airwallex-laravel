<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Deposit;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;

/** Operator reads for one explicitly selected bank-transfer receiving account. */
class Deposits
{
    public function __construct(private readonly Context $context, private readonly Observations $observations) {}

    public function reconcile(string $depositId): Deposit
    {
        return $this->observe($depositId) ?? throw new OwnershipException('Deposit is outside the configured Global Account.');
    }

    /** @return array{items:list<Deposit>,nextPage:?int} */
    public function page(string $fromCreatedAt, string $toCreatedAt, int $page = 0, int $pageSize = 100): array
    {
        $globalAccountId = $this->globalAccountId();
        if ($page < 0 || $page === PHP_INT_MAX || $pageSize < 10 || $pageSize > 500 || Timestamp::compare($fromCreatedAt, $toCreatedAt) > 0) {
            throw new EvidenceException('Deposit recovery window or pagination is invalid.');
        }
        $response = SdkResponseShape::normalize(app(Client::class)->sdk->deposits()->list([
            'from_created_at' => $fromCreatedAt,
            'to_created_at' => $toCreatedAt,
            'page_num' => $page,
            'page_size' => $pageSize,
        ]));
        $items = Proof::pageItems($response);
        $more = $response['has_more'] ?? null;
        if (! is_bool($more) || ($more && $items === [])) {
            throw new EvidenceException('Deposit list has incomplete pagination evidence.');
        }
        foreach ($items as $item) {
            Proof::identity($item, $item['id'], $this->context);
        }
        $deposits = [];
        foreach ($items as $item) {
            if ($this->receives($item, $globalAccountId)) {
                $deposits[] = $this->reconcile($item['id']);
            }
        }

        return ['items' => $deposits, 'nextPage' => $more ? $page + 1 : null];
    }

    /** @internal Webhook and recovery entry point. */
    public function observe(string $id): ?Deposit
    {
        $globalAccountId = $this->globalAccountId();
        $remote = SdkResponseShape::normalize(app(Client::class)->sdk->deposits()->retrieve($id));
        Proof::identity($remote, $id, $this->context);
        if (! $this->receives($remote, $globalAccountId)) {
            if (Deposit::query()->where($this->context->attributes())->where('global_account_id', $globalAccountId)->where('remote_id', $id)->exists()) {
                throw new EvidenceException('Previously observed deposit no longer proves its receiving account.');
            }

            return null;
        }
        $status = Proof::identifier($remote['status'] ?? null)
            ?? throw new EvidenceException('Deposit has no canonical status.');
        $amount = Proof::exactAmount($remote['amount'] ?? null, $remote['currency'] ?? null);
        if ($amount['amount'] === '0') {
            throw new EvidenceException('Inbound bank transfer has a zero amount.');
        }
        $payer = is_array($remote['payer'] ?? null) ? $remote['payer'] : [];
        $details = [
            ...$amount,
            'global_account_id' => $globalAccountId,
            'status' => $status,
            'provider_created_at' => Proof::timestamp($remote['created_at'] ?? null),
            'settled_at' => isset($remote['settled_at']) && $remote['settled_at'] !== '' ? Proof::timestamp($remote['settled_at']) : null,
            'snapshot' => [
                'reference' => is_string($remote['reference'] ?? null) ? $remote['reference'] : null,
                'sender_name' => is_string($payer['name'] ?? null) ? $payer['name'] : null,
                'sender_country' => is_string($payer['country_code'] ?? null) ? $payer['country_code'] : null,
            ],
        ];

        return $this->persist($id, $details);
    }

    private function globalAccountId(): string
    {
        return Proof::identifier(config('airwallex.payments.deposit_global_account_id'))
            ?? throw new ConfigurationException('Deposit reads require an explicitly configured Global Account.');
    }

    private function receives(array $remote, string $globalAccountId): bool
    {
        return ($remote['type'] ?? null) === 'BANK_TRANSFER' && ($remote['global_account_id'] ?? null) === $globalAccountId;
    }

    private function persist(string $id, array $details): Deposit
    {
        return DB::transaction(function () use ($id, $details): Deposit {
            app(CommitGuard::class)->assertMayCommit();
            $identity = [...$this->context->attributes(), 'remote_id' => $id];
            $query = Deposit::query()->where($identity);
            $query->lockForUpdate();
            $stored = $query->first();
            if ($stored === null) {
                $created = Deposit::query()->createOrFirst($identity, $details);
                if ($created->wasRecentlyCreated) {
                    $this->record($created);

                    return $created;
                }
                $stored = $query->firstOrFail();
            }
            if ($stored->global_account_id !== $details['global_account_id'] || $stored->amount !== $details['amount']
                || $stored->currency !== $details['currency'] || Timestamp::compare($stored->provider_created_at, $details['provider_created_at']) !== 0) {
                throw new EvidenceException('Deposit observation changed its original receipt facts.');
            }
            $status = $details['status'];
            if ($status !== $stored->status) {
                if ($stored->status === 'REVERSED' || ($status === 'PENDING' && in_array($stored->status, ['SETTLED', 'REJECTED'], true))) {
                    return $stored;
                }
                if ($status !== 'REVERSED' && in_array($stored->status, ['SETTLED', 'REJECTED'], true)) {
                    throw new EvidenceException('Deposit observation contradicts the retained receipt status.');
                }
            }
            $details['provider_created_at'] = $stored->provider_created_at;
            $details['settled_at'] = $stored->settled_at ?? $details['settled_at'];
            foreach ($details['snapshot'] as $key => $value) {
                $details['snapshot'][$key] = $value ?? ($stored->snapshot[$key] ?? null);
            }
            $statusChanged = $stored->status !== $status;
            if ($stored->snapshot === $details['snapshot']) {
                unset($details['snapshot']);
            }
            $stored->forceFill($details);
            if ($stored->isDirty()) {
                $stored->save();
            }
            if ($statusChanged) {
                $this->record($stored);
            }

            return $stored;
        }, 3);
    }

    private function record(Deposit $deposit): void
    {
        $this->observations->record($deposit, [
            'remote_id' => $deposit->remote_id,
            'global_account_id' => $deposit->global_account_id,
            'amount' => $deposit->amount,
            'currency' => $deposit->currency,
            'status' => $deposit->status,
            'provider_created_at' => $deposit->provider_created_at,
            'settled_at' => $deposit->settled_at,
            'financial_identity' => 'deposit/'.$deposit->remote_id,
        ]);
    }
}
