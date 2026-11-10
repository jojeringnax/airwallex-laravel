<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Timestamp;

/** @internal Selected-version identity adapter; resource state is never applied from it. */
final class BillingWebhookEnvelope
{
    private const EVENTS = [
        'subscription' => ['created', 'in_trial', 'active', 'unpaid', 'modified', 'cancelled'],
        'billing_checkout' => ['created', 'completed', 'cancelled'],
        'invoice' => ['created', 'finalized', 'updated', 'payment.paid', 'voided'],
    ];

    /** @return array{eventId:string,eventName:?string,version:?string,resourceId:?string,family:?string,resource:?array,status:string,reason:?string} */
    public static function inspect(array $envelope, BillingContext $context, string $version = '2026-08-21'): array
    {
        if ($version !== '2026-08-21') {
            throw new ConfigurationException('Webhook handling requires the supported version.');
        }
        $id = self::identifier($envelope['id'] ?? null);
        $name = self::identifier($envelope['name'] ?? null);
        $suppliedVersion = self::identifier($envelope['version'] ?? null);
        $result = ['eventId' => $id ?? 'invalid/'.hash('sha256', CanonicalJson::encode($envelope)), 'eventName' => $name, 'version' => $suppliedVersion, 'resourceId' => null, 'family' => null, 'resource' => null, 'status' => 'quarantined', 'reason' => null];
        if ($id === null || $name === null) {
            return [...$result, 'reason' => 'malformed_envelope'];
        }
        if ($suppliedVersion !== $version) {
            return [...$result, 'reason' => 'unsupported_version'];
        }
        if (($envelope['org_id'] ?? null) !== $context->organizationId
            || ! in_array($envelope['account_id'] ?? null, [null, ''], true)
            || ! in_array($envelope['accountId'] ?? null, [null, ''], true)
            || (array_key_exists('organization_id', $envelope) && $envelope['organization_id'] !== $context->organizationId)
            || (array_key_exists('environment', $envelope) && $envelope['environment'] !== $context->environment)) {
            return [...$result, 'reason' => 'foreign_context'];
        }
        try {
            if (! is_string($envelope['created_at'] ?? null)) {
                return [...$result, 'reason' => 'malformed_envelope'];
            }
            Timestamp::compare($envelope['created_at'], $envelope['created_at']);
        } catch (EvidenceException) {
            return [...$result, 'reason' => 'malformed_envelope'];
        }
        [$family, $event] = array_pad(explode('.', $name, 2), 2, '');
        if (! isset(self::EVENTS[$family]) || ! in_array($event, self::EVENTS[$family], true)) {
            return [...$result, 'status' => 'ignored', 'reason' => 'unsupported_event'];
        }
        $data = $envelope['data'] ?? null;
        $resource = is_array($data) && array_key_exists('object', $data) ? $data['object'] : $data;
        if (! is_array($resource) || array_is_list($resource) || self::identifier($resource['id'] ?? null) === null) {
            return [...$result, 'family' => $family, 'reason' => 'malformed_resource'];
        }

        return [...$result, 'family' => $family, 'resourceId' => $resource['id'], 'resource' => $resource, 'status' => 'pending', 'reason' => null];
    }

    private static function identifier(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 && preg_match('/[\x00-\x20\x7f]/', $value) !== 1 ? $value : null;
    }
}
