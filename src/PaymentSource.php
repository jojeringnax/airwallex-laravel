<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use JsonSerializable;

final readonly class PaymentSource implements JsonSerializable
{
    public function __construct(public string $id, public string $billingCustomerId, public string $linkedPaymentAccountId, private array $data) {}

    /** Explicit server-side access to sanitized provider fields. */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'billing_customer_id' => $this->billingCustomerId, 'linked_payment_account_id' => $this->linkedPaymentAccountId];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
