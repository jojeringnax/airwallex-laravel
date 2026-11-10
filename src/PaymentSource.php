<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use JsonSerializable;
use stdClass;

final readonly class PaymentSource implements JsonSerializable
{
    private array $data;

    public function __construct(public string $id, public string $billingCustomerId, public string $linkedPaymentAccountId, array $data)
    {
        $this->data = self::copy($data);
    }

    /** Explicit server-side access to sanitized provider fields. */
    public function toArray(): array
    {
        return self::copy($this->data);
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'billing_customer_id' => $this->billingCustomerId, 'linked_payment_account_id' => $this->linkedPaymentAccountId];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    private static function copy(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass;
            foreach (get_object_vars($value) as $key => $member) {
                $copy->{$key} = self::copy($member);
            }

            return $copy;
        }

        return is_array($value) ? array_map(self::copy(...), $value) : $value;
    }
}
