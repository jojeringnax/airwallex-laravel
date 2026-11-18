<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments\Models;

use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $intent_id
 * @property ?string $operation_id
 * @property ?string $remote_id
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property int $revision
 * @property array $snapshot
 */
class Refund extends PaymentModel
{
    protected $table = 'airwallex_payment_refunds';

    protected $casts = ['snapshot' => LosslessJson::class.':encrypted', 'revision' => 'integer'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'intent_id', 'operation_id', 'remote_id', 'amount', 'currency'];
    }

    public function assertIdentity(): void
    {
        parent::assertIdentity();
        if ($this->exists && $this->isDirty('operation_id')) {
            throw new OwnershipException('Refund origin cannot be reassigned.');
        }
    }

    public function origin(): string
    {
        return $this->operation_id === null ? 'provider_created' : 'package_initiated';
    }

    public function reason(): ?string
    {
        $value = $this->snapshot['reason'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function paymentAttemptId(): ?string
    {
        $value = $this->snapshot['payment_attempt_id'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function completed(): bool
    {
        return in_array($this->status, ['ACCEPTED', 'SETTLED'], true);
    }
}
