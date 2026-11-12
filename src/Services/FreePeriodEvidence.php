<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;

/** Bounded native Coupon evidence; commercial eligibility and invoice accounting belong to the application. */
class FreePeriodEvidence
{
    public function __construct(private readonly BillingContext $context) {}

    public function coupon(string $id): array
    {
        $resource = $this->couponsResource(app(Client::class)->billing());
        $coupon = SdkResponseShape::normalize($resource->retrieve($id));
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $coupon) && $coupon[$key] !== $expected) {
                throw new OwnershipException('Coupon belongs to a different Billing context.');
            }
        }
        if (($coupon['id'] ?? null) !== $id || ($coupon['active'] ?? null) !== true
            || ($coupon['discount_model'] ?? null) !== 'PERCENTAGE' || ! $this->hundred($coupon['percentage_off'] ?? null)
            || ($coupon['duration_type'] ?? null) !== 'CUSTOM' || ! is_array($coupon['duration'] ?? null)
            || ! $this->duration($coupon['duration'])) {
            throw new EvidenceException('Free periods require an active owned 100 percent CUSTOM Coupon.');
        }
        $expires = $coupon['expires_at'] ?? null;
        if ($expires !== null && (! is_string($expires) || Timestamp::compare($expires, now()->format('Y-m-d\TH:i:s.uP')) <= 0)) {
            throw new EvidenceException('Coupon redemption expiry is unavailable or elapsed.');
        }

        return ['id' => $id, 'duration' => $this->normalizedDuration($coupon['duration']), 'expires_at' => $expires];
    }

    public function assertCurrent(Subscription $subscription): void
    {
        $this->assertGeneration($subscription);
        if ($this->discounts($subscription->snapshot) !== []) {
            throw new EvidenceException('An existing discount requires an explicit separate decision before a free period.');
        }
    }

    public function assertGeneration(Subscription $subscription): void
    {
        $this->context->assertModel($subscription);
        $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $subscription->customer_id)->where('name', $subscription->name)->first();
        if ($slot?->subscription_id !== $subscription->id || $subscription->status !== 'ACTIVE' || $subscription->cancel_at_period_end !== false
            || $subscription->scheduled_ends_at !== null) {
            throw new EvidenceException('An earned free period requires the current ACTIVE generation without pending cancellation.');
        }
    }

    public function proof(Subscription $subscription, array $resource, array $terms): array
    {
        $customer = ModelRegistry::query('customer')->findOrFail($subscription->customer_id);
        if (count($this->discounts($resource)) !== 1) {
            throw new EvidenceException('The selected free-period strategy requires one exclusive Discount.');
        }
        $matches = [];
        foreach ($this->discounts($resource) as $discount) {
            if (($discount['applied_to'] ?? null) === 'SUBSCRIPTION') {
                if (($discount['applied_to_id'] ?? null) !== $subscription->remote_id || ($discount['billing_customer_id'] ?? null) !== $customer->remote_id
                    || ($discount['source'] ?? null) !== 'COUPON' || ($discount['source_id'] ?? null) !== ($terms['id'] ?? null)
                    || ($discount['discount_model'] ?? null) !== 'PERCENTAGE' || ! $this->hundred($discount['percentage_off'] ?? null)
                    || ($discount['duration_type'] ?? null) !== 'CUSTOM' || ! is_array($discount['duration'] ?? null)
                    || $this->normalizedDuration($discount['duration']) !== ($terms['duration'] ?? null)
                    || ! is_string($discount['id'] ?? null) || $discount['id'] === ''
                    || ! is_string($discount['ends_at'] ?? null) || ! is_string($discount['created_at'] ?? null)
                    || Timestamp::compare($discount['ends_at'], $discount['created_at']) <= 0) {
                    throw new EvidenceException('The owned Coupon discount and its exact duration/end are not confirmed.');
                }
                $matches[] = ['id' => $discount['id'], 'created_at' => $discount['created_at'], 'ends_at' => $discount['ends_at']];
            }
        }
        if (count($matches) !== 1) {
            throw new EvidenceException('Exactly one owned subscription-level free-period Discount is required.');
        }

        return $matches[0];
    }

    public function sameProof(array $left, array $right): bool
    {
        return ($left['id'] ?? null) === ($right['id'] ?? null)
            && is_string($right['created_at'] ?? null) && is_string($right['ends_at'] ?? null)
            && Timestamp::compare($left['created_at'], $right['created_at']) === 0
            && Timestamp::compare($left['ends_at'], $right['ends_at']) === 0;
    }

    public function assertRemoved(Subscription $subscription): void
    {
        if ($this->discounts($subscription->snapshot) !== []) {
            throw new EvidenceException('Removal of the original exclusive discount is not confirmed.');
        }
    }

    private function discounts(array $resource): array
    {
        $discounts = $resource['applied_discounts'] ?? null;
        if (! is_array($discounts) || ! array_is_list($discounts)) {
            throw new EvidenceException('Canonical applied discounts are unavailable.');
        }
        foreach ($discounts as $discount) {
            if (! is_array($discount)) {
                throw new EvidenceException('A discount observation is malformed.');
            }
        }

        return $discounts;
    }

    /** Resolve the optional public resource across the supported SDK baseline and selected extensions. */
    private function couponsResource(object $billing): object
    {
        if (! method_exists($billing, 'coupons')) {
            throw new ConfigurationException('Free periods require an explicitly selected SDK with Billing Coupon retrieval.');
        }

        return call_user_func([$billing, 'coupons']);
    }

    private function hundred(mixed $value): bool
    {
        return is_string($value) && preg_match('/^100(?:\.0+)?$/D', $value) === 1;
    }

    private function duration(array $duration): bool
    {
        $period = $duration['period'] ?? null;

        return ((is_int($period) && $period > 0) || (is_string($period) && preg_match('/^[1-9][0-9]*$/D', $period) === 1))
            && in_array($duration['period_unit'] ?? null, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true);
    }

    private function normalizedDuration(array $duration): array
    {
        if (! $this->duration($duration)) {
            throw new EvidenceException('The exact Coupon duration is unavailable.');
        }

        return ['period' => (string) $duration['period'], 'period_unit' => $duration['period_unit']];
    }
}
