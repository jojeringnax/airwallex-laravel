<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;

/** @internal Provisional high-level routing boundary, question #39. */
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

    public static function assertSnapshot(array $snapshot): void
    {
        foreach (self::defaults() as $key => $expected) {
            if (! isset($snapshot[$key]) || ! is_string($snapshot[$key])) {
                throw new EvidenceException('Financial action requires confirmed entity and collector identity.');
            }
            if ($snapshot[$key] !== $expected) {
                throw new OwnershipException('Resource routing conflicts with the configured entity or collector.');
            }
        }
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
