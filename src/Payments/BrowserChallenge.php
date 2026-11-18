<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Ephemeral 3DS iframe handoff for the original saved-card attempt. */
final readonly class BrowserChallenge implements JsonSerializable
{
    public function __construct(public string $paymentIntentId, public string $attemptId, #[SensitiveParameter] private string $url) {}

    public function expose(): array
    {
        return [...$this->jsonSerialize(), 'type' => 'redirect_iframe', 'method' => 'GET', 'url' => $this->url];
    }

    public function jsonSerialize(): array
    {
        return ['payment_intent_id' => $this->paymentIntentId, 'payment_attempt_id' => $this->attemptId];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('Authorize and retrieve a fresh saved-card challenge for each client handoff.');
    }
}
