<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Exception;

class BillingException extends \RuntimeException
{
    public function __toString(): string
    {
        return static::class.': '.$this->getMessage().' in '.$this->getFile().':'.$this->getLine();
    }

    /** @return array<string,int|string> */
    public function __debugInfo(): array
    {
        return ['message' => $this->getMessage(), 'file' => $this->getFile(), 'line' => $this->getLine()];
    }

    /** Persist safe operation IDs instead of serializing exception traces. */
    public function __serialize(): array
    {
        throw new BillingException('Billing exceptions cannot be serialized; persist the local operation ID.');
    }
}
