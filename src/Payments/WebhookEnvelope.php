<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Timestamp;
use Throwable;

/** Selected-version generic Payments envelope; aliases/direct-data samples are unverified. */
final class WebhookEnvelope
{
    private const EVENTS = [
        'payment_intent' => ['created', 'updated', 'cancelled', 'payment_failed', 'pending', 'pending_review', 'requires_capture', 'requires_customer_action', 'requires_payment_method', 'succeeded'],
        'refund' => ['accepted', 'failed', 'received', 'settled'],
        'payment_consent' => ['created', 'disabled', 'paused', 'pending', 'requires_customer_action', 'requires_payment_method', 'updated', 'verification_failed', 'verified'],
        'payment_method' => ['attached', 'created', 'detached', 'disabled', 'updated'],
    ];

    public static function inspect(array $envelope, Context $context, string $version): array
    {
        if ($version !== '2026-08-21') {
            throw new ConfigurationException('Payments webhook handling requires the supported version.');
        }
        $id = self::identifier($envelope['id'] ?? null);
        $name = self::identifier($envelope['name'] ?? null);
        $result = ['eventId' => $id ?? 'invalid/'.hash('sha256', CanonicalJson::encode($envelope)), 'eventName' => $name,
            'version' => self::identifier($envelope['version'] ?? null), 'resourceId' => null, 'family' => null, 'resource' => null, 'status' => 'quarantined', 'reason' => 'malformed_envelope'];
        if ($id === null || $name === null) {
            return $result;
        }
        if ($result['version'] !== $version) {
            return [...$result, 'reason' => 'unsupported_version'];
        }
        if (($envelope['account_id'] ?? null) !== $context->accountId || ! in_array($envelope['org_id'] ?? null, [null, ''], true)
            || array_key_exists('accountId', $envelope) || array_key_exists('organization_id', $envelope)
            || (array_key_exists('environment', $envelope) && $envelope['environment'] !== $context->environment)) {
            return [...$result, 'reason' => 'foreign_context'];
        }
        try {
            if (! is_string($envelope['created_at'] ?? null)) {
                return $result;
            }
            Timestamp::compare($envelope['created_at'], $envelope['created_at']);
        } catch (Throwable) {
            return $result;
        }
        [$family, $event] = array_pad(explode('.', $name, 2), 2, '');
        if (! isset(self::EVENTS[$family]) || ! in_array($event, self::EVENTS[$family], true)) {
            return [...$result, 'status' => 'ignored', 'reason' => 'unsupported_event'];
        }
        $data = $envelope['data'] ?? null;
        $resource = is_array($data) && array_key_exists('object', $data) ? $data['object'] : null;
        if (! is_array($resource) || array_is_list($resource) || self::identifier($resource['id'] ?? null) === null) {
            return [...$result, 'family' => $family, 'reason' => 'malformed_resource'];
        }

        return [...$result, 'family' => $family, 'resourceId' => $resource['id'], 'resource' => $resource, 'status' => 'pending', 'reason' => null];
    }

    private static function identifier(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 && ! preg_match('/[\x00-\x20\x7f]/', $value) ? $value : null;
    }
}
