<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Illuminate\Support\Collection;
use Jojeringnax\AirwallexLaravel\Services\InvoiceManager;
use JsonSerializable;
use LogicException;
use stdClass;

/** An owned, immutable SDK-decoded observation, never a local invoice ledger. */
final readonly class Invoice implements JsonSerializable
{
    private array $data;

    /** @internal Constructed after InvoiceManager verifies ownership. */
    public function __construct(
        public string $id,
        public string $billingCustomerId,
        public ?string $subscriptionId,
        private BillingContext $context,
        private string $customerId,
        private ?string $subscriptionLocalId,
        array $data,
    ) {
        $this->data = self::copy($data);
    }

    public function pdfUrl(): ?string
    {
        return $this->data['pdf_url'] ?? null;
    }

    public function hostedUrl(): ?string
    {
        return $this->data['hosted_url'] ?? null;
    }

    /** @return Collection<int,array<array-key,mixed>> */
    public function lineItems(array $query = []): Collection
    {
        return app(InvoiceManager::class)->lineItems($this, $query);
    }

    /** @return array<array-key,mixed> */
    public function lineItem(string $id): array
    {
        return app(InvoiceManager::class)->lineItem($this, $id);
    }

    /** Explicit server-side access; includes provider links and customer snapshot. */
    public function toArray(): array
    {
        return self::copy($this->data);
    }

    /** @internal Local mapping and immutable context for fresh owned reads.
     * @return array{BillingContext,string,?string}
     */
    public function scope(): array
    {
        return [$this->context, $this->customerId, $this->subscriptionLocalId];
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'billing_customer_id' => $this->billingCustomerId,
            'subscription_id' => $this->subscriptionId,
            'status' => $this->data['status'],
            'payment_status' => $this->data['payment_status'],
            'currency' => $this->data['currency'],
        ];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('Invoice snapshots cannot be serialized; retrieve them through an authorized billable.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Invoice snapshots cannot be restored from serialized data.');
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
