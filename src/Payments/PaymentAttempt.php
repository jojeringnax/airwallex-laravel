<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

/** Canonical attempt details for one account-owned PaymentIntent. */
final readonly class PaymentAttempt
{
    public function __construct(
        public string $id,
        public string $paymentIntentId,
        public string $status,
        public ?string $failureCode,
        public ?string $declineCode,
        public ?string $reason,
        public ?string $issuerCode,
        public ?string $cardBrand,
        public ?string $consentId,
        public ?string $methodId,
        public ?string $capturedAmount = null,
    ) {}

    public function outcome(): string
    {
        return match ($this->status) {
            'AUTHENTICATION_REDIRECTED' => 'customer_action_required',
            'RECEIVED', 'PENDING_AUTHORIZATION' => 'pending',
            'AUTHORIZED' => 'authorized',
            'CAPTURE_REQUESTED' => 'capture_requested',
            'SETTLED', 'PAID' => 'captured',
            'FAILED' => $this->failureOutcome(),
            'CANCELLED', 'EXPIRED' => 'ended',
            default => 'unrecognized',
        };
    }

    private function failureOutcome(): string
    {
        $commonCardCodes = in_array(strtolower($this->cardBrand ?? ''), ['visa', 'mastercard'], true);

        return match (true) {
            $this->declineCode === 'card_brand_not_supported',
            $commonCardCodes && in_array($this->issuerCode, ['14', '54'], true) => 'unusable_card',
            in_array($this->failureCode, ['provider_unavailable', 'system_unavailable'], true),
            $commonCardCodes && $this->issuerCode === '51' => 'temporary_decline',
            default => 'failed',
        };
    }
}
