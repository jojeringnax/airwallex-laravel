<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;
use SensitiveParameter;

/** Subscription-level Coupon terms and original Discount proof; provider-owned calculations. */
class CouponEvidence extends FreePeriodEvidence
{
    public function coupon(string $id): array
    {
        $coupon = SdkResponseShape::normalize(app(Client::class)->billing()->coupons()->retrieve($id));
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $coupon) && $coupon[$key] !== $expected) {
                throw new OwnershipException('Coupon belongs to a different Billing context.');
            }
        }
        if (($coupon['id'] ?? null) !== $id || ($coupon['active'] ?? null) !== true) {
            throw new EvidenceException('An active owned Coupon is required.');
        }
        $terms = $this->terms($coupon);
        $expires = $coupon['expires_at'] ?? null;
        if ($expires !== null && (! is_string($expires) || Timestamp::compare($expires, now()->format('Y-m-d\TH:i:s.uP')) <= 0)) {
            throw new EvidenceException('Coupon redemption expiry is unavailable or elapsed.');
        }

        return ['id' => $id, ...$terms, 'expires_at' => $expires];
    }

    public function assertGeneration(Subscription $subscription): void
    {
        $this->context->assertModel($subscription);
        $slot = ModelRegistry::query('subscription_slot')->where('customer_id', $subscription->customer_id)->where('name', $subscription->name)->first();
        if ($slot?->subscription_id !== $subscription->id || ! in_array($subscription->status, ['PENDING', 'IN_TRIAL', 'ACTIVE'], true)
            || $subscription->cancel_at_period_end !== false || $subscription->scheduled_ends_at !== null
            || ! in_array($subscription->snapshot['collection_method'] ?? null, ['AUTO_CHARGE', 'CHARGE_ON_CHECKOUT', 'OUT_OF_BAND'], true)) {
            throw new EvidenceException('Coupon changes require a current unscheduled supported Subscription generation.');
        }
    }

    public function assertCurrent(Subscription $subscription): void
    {
        if ($subscription->status !== 'ACTIVE') {
            throw new EvidenceException('Applying a Coupon requires an ACTIVE Subscription.');
        }
        parent::assertCurrent($subscription);
        $this->assertNoItemDiscounts($subscription);
    }

    public function assertCompatible(Subscription $subscription, array $terms): void
    {
        $this->assertCurrent($subscription);
        if ($terms['duration_type'] === 'ONCE') {
            throw new EvidenceException('ONCE redemption on an existing ACTIVE Subscription is not established by the documented first-invoice contract.');
        }
        $this->currency($terms, $subscription->snapshot['currency'] ?? null);
    }

    public function assertCreation(#[SensitiveParameter] array $payload, array $terms): void
    {
        $this->currency($terms, $payload['currency'] ?? null);
        if ($terms['discount_model'] === 'FLAT') {
            foreach ($payload['items'] ?? $payload['line_items'] as $item) {
                $price = SdkResponseShape::normalize(app(Client::class)->billing()->prices()->retrieve($item['price_id']));
                if (($price['id'] ?? null) !== $item['price_id']) {
                    throw new OwnershipException('Coupon price identity is not confirmed.');
                }
                $this->currency($terms, $price['currency'] ?? null);
            }
        }
    }

    public function proof(Subscription $subscription, #[SensitiveParameter] array $resource, array $terms): array
    {
        $customer = ModelRegistry::query('customer')->findOrFail($subscription->customer_id);
        $this->currency($terms, $resource['currency'] ?? null);
        $discounts = $this->discounts($resource);
        if (count($discounts) !== 1) {
            throw new EvidenceException('Exactly one owned subscription-level Discount is required.');
        }
        $discount = $discounts[0];
        if (($discount['applied_to'] ?? null) !== 'SUBSCRIPTION' || ($discount['applied_to_id'] ?? null) !== $subscription->remote_id
            || ($discount['billing_customer_id'] ?? null) !== $customer->remote_id || ($discount['source'] ?? null) !== 'COUPON'
            || ($discount['source_id'] ?? null) !== $terms['id']
            || $this->terms($discount) !== array_intersect_key($terms, array_flip(['discount_model', 'duration_type', 'duration', 'percentage_off', 'amount_off', 'currency']))
            || ! is_string($discount['id'] ?? null) || $discount['id'] === ''
            || ! is_string($discount['created_at'] ?? null) || ! is_string($discount['starts_at'] ?? null)) {
            throw new EvidenceException('The original owned Discount and exact Coupon terms are unproven.');
        }
        Timestamp::compare($discount['created_at'], $discount['starts_at']);
        $end = $discount['ends_at'] ?? null;
        if ($terms['duration_type'] === 'CUSTOM') {
            if (! is_string($end) || Timestamp::compare($end, $discount['starts_at']) <= 0 || Timestamp::compare($end, $discount['created_at']) <= 0) {
                throw new EvidenceException('The custom Discount has no confirmed positive duration.');
            }
        } elseif ($end !== null) {
            throw new EvidenceException('An unbounded or ONCE Discount has an unexpected end.');
        }

        return ['id' => $discount['id'], 'created_at' => $discount['created_at'], 'starts_at' => $discount['starts_at'], 'ends_at' => $end];
    }

    public function sameProof(array $left, array $right): bool
    {
        return ($left['id'] ?? null) === ($right['id'] ?? null)
            && is_string($left['created_at'] ?? null) && is_string($right['created_at'] ?? null)
            && is_string($left['starts_at'] ?? null) && is_string($right['starts_at'] ?? null)
            && Timestamp::compare($left['created_at'], $right['created_at']) === 0
            && Timestamp::compare($left['starts_at'], $right['starts_at']) === 0
            && (($left['ends_at'] ?? null) === null ? ($right['ends_at'] ?? null) === null
                : is_string($right['ends_at'] ?? null) && Timestamp::compare($left['ends_at'], $right['ends_at']) === 0);
    }

    public function assertNoItemDiscounts(Subscription $subscription): void
    {
        foreach ($subscription->items()->get() as $item) {
            if (($item->snapshot['applied_discounts'] ?? null) !== []) {
                throw new EvidenceException('Coupon stacking with item discounts is not qualified.');
            }
        }
    }

    private function currency(array $terms, #[SensitiveParameter] mixed $currency): void
    {
        if ($terms['discount_model'] === 'FLAT' && (! is_string($currency) || $currency !== $terms['currency'])) {
            throw new EvidenceException('A fixed Coupon must match the confirmed Subscription and Price currency.');
        }
    }

    private function terms(#[SensitiveParameter] array $resource): array
    {
        $model = $resource['discount_model'] ?? null;
        $type = $resource['duration_type'] ?? null;
        if (! in_array($model, ['PERCENTAGE', 'FLAT'], true) || ! in_array($type, ['ONCE', 'CUSTOM', 'INDEFINITELY'], true)) {
            throw new EvidenceException('The Coupon model or duration is unsupported.');
        }
        $duration = null;
        if ($type === 'CUSTOM') {
            $value = $resource['duration'] ?? null;
            $period = is_array($value) ? $value['period'] ?? null : null;
            if ((! is_int($period) && ! is_string($period)) || preg_match('/^[1-9][0-9]*$/D', (string) $period) !== 1
                || ! in_array($value['period_unit'] ?? null, ['DAY', 'WEEK', 'MONTH', 'YEAR'], true)) {
                throw new EvidenceException('A CUSTOM Coupon requires an exact positive calendar duration.');
            }
            $duration = ['period' => (string) $period, 'period_unit' => $value['period_unit']];
        } elseif (($resource['duration'] ?? null) !== null) {
            throw new EvidenceException('ONCE and INDEFINITELY Coupons cannot carry CUSTOM duration.');
        }
        $percentage = $model === 'PERCENTAGE' ? FirstChargeDiscountEvidence::amount($resource['percentage_off'] ?? null) : null;
        if ($percentage !== null && preg_match('/^(?:[0-9](?:\.[0-9]+)?|[1-9][0-9](?:\.[0-9]+)?|100)$/D', $percentage) !== 1) {
            throw new EvidenceException('Coupon percentage must be an exact value from zero to one hundred.');
        }
        $amount = $model === 'FLAT' ? FirstChargeDiscountEvidence::amount($resource['amount_off'] ?? null) : null;
        $currency = $model === 'FLAT' ? $resource['currency'] ?? null : null;
        if ($model === 'FLAT' && (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
            throw new EvidenceException('A fixed Coupon requires its explicit currency.');
        }

        return ['discount_model' => $model, 'duration_type' => $type, 'duration' => $duration, 'percentage_off' => $percentage, 'amount_off' => $amount, 'currency' => $currency];
    }
}
