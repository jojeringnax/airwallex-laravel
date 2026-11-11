<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Instrument;
use JsonSerializable;
use SensitiveParameter;

/** Ephemeral browser handoff. Normal JSON/debug never exposes secrets or next actions. */
final readonly class BrowserSetup implements JsonSerializable
{
    public function __construct(public Instrument $instrument, #[SensitiveParameter] private ?string $secret = null, #[SensitiveParameter] private ?array $action = null) {}

    public function hasClientSecret(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /** Call only in an endpoint that has authorized this billable for browser setup. */
    public function expose(): array
    {
        if (! $this->hasClientSecret() && $this->action === null) {
            throw new ConflictException('Browser setup secret is unavailable after recovery; a separate explicit setup action is required.');
        }

        return ['consent_id' => $this->instrument->remote_id, 'client_secret' => $this->secret, 'next_action' => $this->action];
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->instrument->id, 'consent_id' => $this->instrument->remote_id, 'status' => $this->instrument->status, 'bootstrap_available' => $this->hasClientSecret()];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Browser setup secrets cannot be serialized or queued.');
    }
}
