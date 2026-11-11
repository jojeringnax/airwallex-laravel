<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Payments\Models\Intent;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Authorized, ephemeral client handoff. Never persisted in a model snapshot. */
final readonly class BrowserIntent implements JsonSerializable
{
    public function __construct(public Intent $intent, #[SensitiveParameter] private string $secret, private mixed $nextAction) {}

    public function expose(): array
    {
        return ['payment_intent_id' => $this->intent->remote_id, 'client_secret' => $this->secret, 'next_action' => $this->nextAction];
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->intent->id, 'status' => $this->intent->status];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('Authorize and retrieve fresh Payments bootstrap for each client handoff.');
    }
}
