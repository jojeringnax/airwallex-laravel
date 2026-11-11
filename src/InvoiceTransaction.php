<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use JsonSerializable;
use LogicException;

/** An owned provider observation; no inferred cash allocation or settlement. */
final readonly class InvoiceTransaction implements JsonSerializable
{
    /** @internal Created only after fresh invoice and transaction ownership verification. */
    public function __construct(
        public string $id,
        public string $invoiceId,
        public string $billingCustomerId,
        public string $amount,
        public string $currency,
        public string $status,
        public ?string $type,
        public ?string $externalId,
        public ?string $linkedPaymentAccountId,
        public ?bool $outOfBand,
        private BillingContext $context,
        private array $data,
    ) {}

    /** Stable observation identity across different InvoiceObserved/webhook deliveries, not payment proof. */
    public function identityKey(): string
    {
        return hash('sha256', CanonicalJson::encode(['airwallex', 'billing', $this->context->attributes(), $this->id, $this->type]));
    }

    /** Explicit server-side access to sanitized SDK-decoded fields, including unknown values. */
    public function toArray(): array
    {
        return self::copy($this->data);
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'invoice_id' => $this->invoiceId, 'billing_customer_id' => $this->billingCustomerId,
            'amount' => $this->amount, 'currency' => $this->currency, 'status' => $this->status, 'type' => $this->type,
            'external_id' => $this->externalId, 'linked_payment_account_id' => $this->linkedPaymentAccountId,
            'out_of_band' => $this->outOfBand];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('Invoice transaction observations must be retrieved through an authorized invoice.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Invoice transaction observations cannot be restored from serialized data.');
    }

    private static function copy(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $copy = new \stdClass;
            foreach (get_object_vars($value) as $key => $member) {
                $copy->{$key} = self::copy($member);
            }

            return $copy;
        }

        return is_array($value) ? array_map(self::copy(...), $value) : $value;
    }
}
