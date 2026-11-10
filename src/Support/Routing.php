<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;

/** @internal Configured routing and per-resource response contracts, issue #39. */
final class Routing
{
    /** @return array{legal_entity_id:string,linked_payment_account_id:string} */
    public static function defaults(array $options = []): array
    {
        $routing = ['legal_entity_id' => self::configured('legal_entity_id'), 'linked_payment_account_id' => self::configured('linked_payment_account_id')];
        foreach ($routing as $key => $value) {
            if (array_key_exists($key, $options) && $options[$key] !== $value) {
                throw new OwnershipException('High-level billing routing must match its configured entity and collector.');
            }
        }

        return $routing;
    }

    public static function collector(): string
    {
        return self::configured('linked_payment_account_id');
    }

    public static function assertSubscription(array $snapshot): void
    {
        self::assertSnapshot($snapshot, []);
    }

    public static function assertCheckout(array $snapshot): void
    {
        self::assertSnapshot($snapshot, ['linked_payment_account_id']);
    }

    private static function assertSnapshot(array $snapshot, array $required): void
    {
        foreach (self::defaults() as $key => $expected) {
            if (! array_key_exists($key, $snapshot) && ! in_array($key, $required, true)) {
                continue;
            }
            if (! is_string($snapshot[$key] ?? null) || $snapshot[$key] === '') {
                throw new EvidenceException('Resource routing is missing a required value or contains a malformed value.');
            }
            if ($snapshot[$key] !== $expected) {
                throw new OwnershipException('Resource routing conflicts with the configured entity or collector.');
            }
        }
    }

    /** Retain only bounded IDs or a safe malformed-value sentinel. */
    public static function responseEvidence(array $response): array
    {
        $evidence = [];
        foreach (['legal_entity_id', 'linked_payment_account_id'] as $key) {
            if (array_key_exists($key, $response)) {
                $value = $response[$key];
                $evidence[$key] = is_string($value) && $value !== '' && strlen($value) <= 255 ? $value : null;
            }
        }

        return $evidence;
    }

    private static function configured(string $key): string
    {
        $value = config('airwallex.'.$key);
        if (! is_string($value) || $value === '') {
            throw new ConfigurationException('Billing entity and collection account must be explicitly configured.');
        }

        return $value;
    }
}
