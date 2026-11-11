<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Application-owned adapters, separate from provider HTTP or financial correctness proof.
// Supply a verified/versioned exponent table, immutable catalogue aliases and an application ledger table.
// Never construct allocation evidence from a browser status, Invoice PAID or webhook alone.
return static function (array $currencyExponents, array $catalogueAliases, string $ledgerTable): array {
    $exponent = static function (string $currency) use ($currencyExponents): int {
        $value = $currencyExponents[$currency] ?? null;
        if (! is_int($value) || $value < 0 || $value > 9) {
            throw new InvalidArgumentException('A verified currency exponent is required.');
        }

        return $value;
    };
    $major = static function (mixed $minor, string $currency) use ($exponent): string {
        if (! is_string($minor) || preg_match('/^[0-9]+$/D', $minor) !== 1) {
            throw new InvalidArgumentException('Unsigned integer minor units are required.');
        }
        $scale = $exponent($currency);
        $digits = ltrim($minor, '0') ?: '0';
        if ($scale === 0) {
            return $digits;
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    };
    $minor = static function (mixed $amount, string $currency) use ($exponent): string {
        if (! is_string($amount) || preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $amount, $parts) !== 1) {
            throw new InvalidArgumentException('Exact unsigned decimal money is required.');
        }
        $scale = $exponent($currency);
        $fraction = $parts[2] ?? '';
        if (trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException('Amount exceeds the currency precision; no rounding is permitted.');
        }

        return ltrim($parts[1].str_pad(substr($fraction, 0, $scale), $scale, '0'), '0') ?: '0';
    };
    $price = static function (string $environment, string $alias, string $currency) use ($catalogueAliases): string {
        $id = $catalogueAliases[$environment][$alias][$currency] ?? null;
        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('A verified catalogue alias is required.');
        }

        return $id;
    };
    $cash = static function (array $observation, ?array $applicationAllocation) use ($ledgerTable, $minor): string {
        // The allocation is trusted server-side reconciliation evidence owned by the application.
        // It must independently prove this intent's exclusive cash allocation to this invoice.
        if (($observation['status'] ?? null) !== 'SUCCEEDED' || ($observation['type'] ?? null) !== 'PAYMENT'
            || ($observation['out_of_band'] ?? null) !== false || $applicationAllocation === null
            || ($applicationAllocation['exclusive_to_invoice'] ?? null) !== true) {
            return 'unallocated';
        }
        foreach (['environment', 'account_id', 'invoice_id', 'payment_intent_id', 'currency', 'amount_minor', 'proof_reference', 'clearing_account'] as $key) {
            if (! is_string($applicationAllocation[$key] ?? null) || $applicationAllocation[$key] === '') {
                throw new InvalidArgumentException('Complete independent application allocation evidence is required.');
            }
        }
        if (($observation['linked_payment_account_id'] ?? null) !== $applicationAllocation['account_id']
            || ($observation['invoice_id'] ?? null) !== $applicationAllocation['invoice_id']
            || ($observation['external_id'] ?? null) !== $applicationAllocation['payment_intent_id']
            || ($observation['currency'] ?? null) !== $applicationAllocation['currency']
            || ! is_string($observation['amount'] ?? null)
            || $minor($observation['amount'], $applicationAllocation['currency']) !== $applicationAllocation['amount_minor']) {
            throw new InvalidArgumentException('The canonical financial identity or exact allocation amount does not match.');
        }
        if ($applicationAllocation['amount_minor'] === '0') {
            return 'unallocated';
        }
        $identity = hash('sha256', json_encode(['airwallex', $applicationAllocation['environment'], $applicationAllocation['account_id'], 'payment_intent', $applicationAllocation['payment_intent_id'], 'cash_received'], JSON_THROW_ON_ERROR));
        $inserted = DB::table($ledgerTable)->insertOrIgnore([
            'financial_identity' => $identity, 'provider' => 'airwallex',
            'environment' => $applicationAllocation['environment'], 'account_id' => $applicationAllocation['account_id'],
            'resource_id' => $applicationAllocation['payment_intent_id'], 'invoice_id' => $applicationAllocation['invoice_id'],
            'currency' => $applicationAllocation['currency'], 'amount_minor' => $applicationAllocation['amount_minor'],
            'proof_reference' => $applicationAllocation['proof_reference'], 'clearing_account' => $applicationAllocation['clearing_account'],
        ]);
        if ($inserted === 0) {
            $recorded = DB::table($ledgerTable)->where('financial_identity', $identity)->first();
            if ($recorded === null || $recorded->amount_minor !== $applicationAllocation['amount_minor']
                || $recorded->invoice_id !== $applicationAllocation['invoice_id'] || $recorded->currency !== $applicationAllocation['currency']) {
                throw new LogicException('An existing financial identity has a contradictory allocation.');
            }
        }

        return $inserted === 1 ? 'recorded' : 'duplicate';
    };

    return ['major' => $major, 'minor' => $minor, 'price' => $price, 'cash' => $cash];
};
