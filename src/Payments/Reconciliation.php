<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;

/** Read-only accounting inputs for the configured merchant. Authorize access in the application. */
final readonly class Reconciliation
{
    private const array SETTLEMENT = [
        'settlement_id', 'amount', 'fee', 'currency', 'status', 'created_at', 'estimated_settled_at', 'settled_at',
    ];

    private const array SETTLEMENT_RECORD = [
        'transaction_id', 'transaction_type', 'settlement_batch_id', 'payment_intent_id', 'payment_attempt_ids',
        'source_id', 'source_entity', 'order_id', 'request_id', 'acquirer_reference_number', 'subscription_id',
        'dispute_status', 'dispute_reason_code', 'created_at', 'settled_at', 'payment_created_time',
        'transaction_amount', 'transaction_currency', 'exchange_rate', 'settlement_currency',
        'gross_amount', 'fees', 'taxes_on_fees', 'net_amount',
    ];

    private const array FEE = [
        'fee_type', 'fee_currency', 'fee_subtotal_amount', 'settlement_currency', 'settlement_subtotal_amount',
        'settlement_tax_amount', 'settlement_total_amount', 'exchange_rate', 'incurred_at', 'source_status',
    ];

    private const array WALLET = [
        'id', 'account_type', 'amount', 'balance', 'fee', 'currency', 'posted_at',
        'reference', 'source', 'source_type', 'transaction_type',
    ];

    private const array PAYOUT = [
        'id', 'batch_transfer_id', 'beneficiary_id', 'request_id', 'short_reference_id', 'reference', 'status',
        'transfer_method', 'source_amount', 'source_currency', 'transfer_amount', 'transfer_currency',
        'amount_payer_pays', 'amount_beneficiary_receives', 'fee_amount', 'fee_currency', 'fee_paid_by',
        'created_at', 'updated_at', 'transfer_date', 'dispatch_date',
    ];

    public function __construct(private Client $client, private Context $context) {}

    public function settlement(string $settlementId): array
    {
        $remote = $this->client->sdk->finance()->settlements()->retrieve($settlementId);

        return $this->settlementRow($remote, $settlementId);
    }

    /** @return array{items:list<array>,next_page:?int} */
    public function settlements(string $currency, string $fromSettledAt, string $toSettledAt, string $status, int $page = 0, int $pageSize = 100): array
    {
        $remote = $this->client->sdk->finance()->settlements()->list([
            'currency' => $currency,
            'from_settled_at' => $fromSettledAt,
            'to_settled_at' => $toSettledAt,
            'status' => $status,
            'page_num' => $page,
            'page_size' => $pageSize,
        ]);
        $items = $this->items($remote, 'settlement_id', function (array $row, string $id) use ($currency, $status): array {
            $settlement = $this->settlementRow($row, $id);
            if ($settlement['currency'] !== $currency || $settlement['status'] !== $status) {
                throw new EvidenceException('Settlement page returned a row outside its currency or status filter.');
            }

            return $settlement;
        });

        return ['items' => $items, 'next_page' => $this->hasMore($remote) ? $page + 1 : null];
    }

    /** @return array{items:list<array>,next_page:?string} */
    public function settlementRecords(string $settlementId, ?string $page = null, int $pageSize = 50): array
    {
        $query = ['settlement_id' => $settlementId, 'page_size' => $pageSize];
        if ($page !== null) {
            $query['page'] = $page;
        }
        $remote = $this->client->sdk->payments()->settlementRecords()->list($query);
        $items = $this->items($remote, 'transaction_id', function (array $row, string $id) use ($settlementId): array {
            if (($row['settlement_batch_id'] ?? null) !== $settlementId || ($row['account_id'] ?? null) !== $this->context->accountId) {
                throw new OwnershipException('Settlement record lacks the requested batch and merchant proof.');
            }
            $record = $this->owned($row, $id, 'transaction_id', self::SETTLEMENT_RECORD);
            self::field($row, 'settlement_currency');
            self::field($row, 'transaction_type');
            $record['direction'] = self::direction($row, 'net_amount');
            if (isset($row['fee_details_list'])) {
                $record['fee_details_list'] = $this->feeDetails($row['fee_details_list']);
            }

            return $record;
        });

        return ['items' => $items, 'next_page' => $this->nextCursor($remote, $page)];
    }

    /** @return array{items:list<array>,next_page:?string} */
    public function walletActivity(string $currency, string $page = '0', ?string $fromPostedAt = null, ?string $toPostedAt = null, int $pageSize = 100): array
    {
        $query = array_filter([
            'account_type' => 'cash',
            'currency' => $currency,
            'page' => $page,
            'page_size' => $pageSize,
            'from_post_at' => $fromPostedAt,
            'to_post_at' => $toPostedAt,
        ], static fn (mixed $value): bool => $value !== null);
        $remote = $this->client->sdk->finance()->balanceHistory()->list($query);
        $items = $this->items($remote, 'id', function (array $row, string $id) use ($currency): array {
            if (($row['currency'] ?? null) !== $currency || (isset($row['account_type']) && $row['account_type'] !== 'cash')) {
                throw new EvidenceException('Wallet page returned a row outside its currency or account type filter.');
            }
            $entry = $this->owned($row, $id, 'id', self::WALLET);
            $entry['direction'] = self::direction($row, 'amount', ($row['source_type'] ?? null) === 'PAYMENT_RESERVE');

            return $entry;
        });

        return ['items' => $items, 'next_page' => $this->nextCursor($remote, $page)];
    }

    public function bankPayout(string $transferId): array
    {
        $row = SdkResponseShape::normalize($this->client->sdk->finance()->transfers()->retrieve($transferId));
        $entry = $this->owned($row, $transferId, 'id', self::PAYOUT);
        if (! in_array($row['transfer_method'] ?? null, ['LOCAL', 'SWIFT'], true)) {
            throw new EvidenceException('Transfer does not identify a supported bank payout method.');
        }
        foreach (['status', 'source_currency', 'transfer_currency'] as $field) {
            self::field($row, $field);
        }
        self::direction($row, 'amount_payer_pays');
        self::direction($row, 'amount_beneficiary_receives');

        foreach ([
            'conversion' => ['currency_pair', 'rate'],
            'dispatch_info' => ['external_reference', 'external_reference_type'],
            'funding' => ['deposit_type', 'funding_source_id', 'status'],
        ] as $field => $fields) {
            if (isset($row[$field])) {
                $entry[$field] = self::select($row[$field], $fields);
            }
        }

        return [...$entry, 'direction' => 'debit'];
    }

    private function settlementRow(array $row, string $id): array
    {
        $entry = $this->owned($row, $id, 'settlement_id', self::SETTLEMENT);
        self::field($row, 'status');
        self::field($row, 'currency');

        return [...$entry, 'direction' => self::direction($row, 'amount')];
    }

    /** @param list<string> $fields */
    private function owned(array $row, string $id, string $identityField, array $fields): array
    {
        self::field($row, $identityField);
        Proof::identity($row, $id, $this->context, $identityField);

        return [...$this->context->attributes(), ...self::select($row, $fields)];
    }

    /** @param \Closure(array,string):array $project @return list<array> */
    private function items(array $remote, string $identityField, \Closure $project): array
    {
        $page = SdkResponseShape::normalize($remote);
        if (! isset($page['items']) || ! is_array($page['items']) || ! array_is_list($page['items'])) {
            throw new EvidenceException('Reconciliation page has no valid item list.');
        }
        $items = [];
        $seen = [];
        foreach ($page['items'] as $row) {
            if (! is_array($row)) {
                throw new EvidenceException('Reconciliation item is malformed.');
            }
            $id = self::field($row, $identityField);
            if (isset($seen[$id])) {
                throw new EvidenceException('Reconciliation page repeated an item identity.');
            }
            $seen[$id] = true;
            $items[] = $project($row, $id);
        }

        return $items;
    }

    private function hasMore(array $remote): bool
    {
        $more = $remote['has_more'] ?? null;
        if (! is_bool($more)) {
            throw new EvidenceException('Reconciliation page has no valid continuation flag.');
        }

        return $more;
    }

    private function nextCursor(array $remote, ?string $previous): ?string
    {
        $next = $remote['page_after'] ?? null;
        if ($this->hasMore($remote)) {
            if (! is_string($next) || $next === '' || $next === $previous) {
                throw new EvidenceException('Reconciliation page has no advancing continuation cursor.');
            }

            return $next;
        }

        return null;
    }

    private function feeDetails(mixed $fees): array
    {
        if (! is_array($fees) || ! array_is_list($fees)) {
            throw new EvidenceException('Settlement record has malformed fee details.');
        }

        return array_map(static fn (mixed $fee): array => self::select($fee, self::FEE), $fees);
    }

    /** @param list<string> $fields */
    private static function select(mixed $row, array $fields): array
    {
        if ($row instanceof \stdClass) {
            $row = get_object_vars($row);
        }
        if (! is_array($row) || ($row !== [] && array_is_list($row))) {
            throw new EvidenceException('Reconciliation object is malformed.');
        }

        return array_intersect_key($row, array_fill_keys($fields, true));
    }

    private static function field(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new EvidenceException('Reconciliation row is missing a required field.');
        }

        return $value;
    }

    private static function direction(array $row, string $field, bool $reverse = false): string
    {
        $amount = self::field($row, $field);
        if (! preg_match('/^(-?)([0-9]+(?:\.[0-9]+)?)(?:[eE][+-]?[0-9]+)?$/D', $amount, $parts)) {
            throw new EvidenceException('Reconciliation row has an invalid exact amount.');
        }
        if (trim($parts[2], '0.') === '') {
            return 'zero';
        }

        return ($parts[1] === '-') !== $reverse ? 'debit' : 'credit';
    }
}
