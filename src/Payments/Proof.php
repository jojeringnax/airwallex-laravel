<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;

/** Ownership checks over SDK-decoded observations, not a second API validator. */
final class Proof
{
    public static function identity(array $remote, string $id, Context $context): void
    {
        if ($id === '' || ($remote['id'] ?? null) !== $id) {
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
}
