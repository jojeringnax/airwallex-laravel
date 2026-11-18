<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Timestamp;

/** Ownership checks over SDK-decoded observations, not a second API validator. */
final class Proof
{
    public static function identity(array $remote, string $id, Context $context, string $identityField = 'id'): void
    {
        if ($id === '' || ($remote[$identityField] ?? null) !== $id) {
            throw new OwnershipException('Payments resource identity does not match.');
        }
        foreach (['account_id' => $context->accountId, 'environment' => $context->environment] as $key => $expected) {
            if (array_key_exists($key, $remote) && $remote[$key] !== $expected) {
                throw new OwnershipException('Payments resource has contradictory merchant context.');
            }
        }
        // Launch uses login account selection, never platform delegation.
        if (isset($remote['connected_account_id']) && $remote['connected_account_id'] !== $context->accountId) {
            throw new OwnershipException('Payments resource has a different connected account.');
        }
    }

    public static function identifier(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 && ! preg_match('/[\x00-\x20\x7f]/', $value) ? $value : null;
    }

    public static function timestamp(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 255) {
            throw new EvidenceException('Canonical observation has no valid timestamp.');
        }
        Timestamp::compare($value, $value);

        return $value;
    }

    /** @return array{amount:string,currency:string} */
    public static function exactAmount(mixed $amount, mixed $currency): array
    {
        if (! is_string($amount) || ! preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $amount)
            || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new EvidenceException('Canonical observation has no exact amount and currency.');
        }

        return ['amount' => str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount, 'currency' => $currency];
    }

    /** @return list<array> */
    public static function pageItems(array $page): array
    {
        if (! is_array($page['items'] ?? null) || ! array_is_list($page['items'])) {
            throw new EvidenceException('Canonical observation list has no complete item list.');
        }
        $seen = [];
        foreach ($page['items'] as $item) {
            $id = is_array($item) ? self::identifier($item['id'] ?? null) : null;
            if ($id === null || isset($seen[$id])) {
                throw new EvidenceException('Canonical observation list has invalid or repeated identity.');
            }
            $seen[$id] = true;
        }

        return $page['items'];
    }
}
