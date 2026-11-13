<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use Jojeringnax\AirwallexLaravel\Timestamp;
use SensitiveParameter;

/** A consumer-selected first-charge amount; no future-phase or daily-rate calculation. */
class FirstChargeDiscountEvidence extends FreePeriodEvidence
{
    public function coupon(string $id): array
    {
        $coupon = SdkResponseShape::normalize($this->couponsResource(app(Client::class)->billing())->retrieve($id));
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $coupon) && $coupon[$key] !== $expected) {
                throw new OwnershipException('Coupon belongs to a different Billing context.');
            }
        }
        if (($coupon['id'] ?? null) !== $id || ($coupon['active'] ?? null) !== true
            || ($coupon['discount_model'] ?? null) !== 'FLAT' || ($coupon['duration_type'] ?? null) !== 'ONCE'
            || ($coupon['duration'] ?? null) !== null || ! is_string($coupon['currency'] ?? null) || $coupon['currency'] === ''
            || self::amount($coupon['amount_off'] ?? null) === '0') {
            throw new EvidenceException('First-charge discounts require an active owned positive FLAT/ONCE Coupon.');
        }
        $expires = $coupon['expires_at'] ?? null;
        if ($expires !== null && (! is_string($expires) || Timestamp::compare($expires, now()->format('Y-m-d\TH:i:s.uP')) <= 0)) {
            throw new EvidenceException('Coupon redemption expiry is unavailable or elapsed.');
        }

        return ['id' => $id, 'amount_off' => self::amount($coupon['amount_off']), 'currency' => $coupon['currency'], 'expires_at' => $expires];
    }

    public function assertCurrent(Subscription $subscription): void
    {
        parent::assertCurrent($subscription);
        $items = $subscription->items()->get();
        if ($items->isEmpty()) {
            throw new EvidenceException('First-charge discounts require a confirmed nonempty item set.');
        }
        foreach ($items as $item) {
            if (($item->snapshot['applied_discounts'] ?? null) !== []) {
                throw new EvidenceException('First-charge discounts require an unchanged undiscounted item set.');
            }
        }
    }

    public function proof(Subscription $subscription, #[SensitiveParameter] array $resource, array $terms): array
    {
        $customer = ModelRegistry::query('customer')->findOrFail($subscription->customer_id);
        $discounts = $this->discounts($resource);
        if (count($discounts) !== 1) {
            throw new EvidenceException('Exactly one original first-charge Discount is required.');
        }
        $discount = $discounts[0];
        if (($discount['applied_to'] ?? null) !== 'SUBSCRIPTION' || ($discount['applied_to_id'] ?? null) !== $subscription->remote_id
            || ($discount['billing_customer_id'] ?? null) !== $customer->remote_id || ($discount['source'] ?? null) !== 'COUPON'
            || ($discount['source_id'] ?? null) !== $terms['id'] || ($discount['discount_model'] ?? null) !== 'FLAT'
            || ($discount['duration_type'] ?? null) !== 'ONCE' || ($discount['duration'] ?? null) !== null
            || ($discount['currency'] ?? null) !== $terms['currency'] || self::amount($discount['amount_off'] ?? null) !== $terms['amount_off']
            || ! is_string($discount['id'] ?? null) || $discount['id'] === ''
            || ! is_string($discount['created_at'] ?? null) || ! is_string($discount['starts_at'] ?? null)) {
            throw new EvidenceException('The original owned first-charge Discount and its exact amount are unproven.');
        }
        Timestamp::compare($discount['created_at'], $discount['starts_at']);

        return ['id' => $discount['id'], 'created_at' => $discount['created_at'], 'starts_at' => $discount['starts_at'], 'amount_off' => $terms['amount_off'], 'currency' => $terms['currency']];
    }

    public function sameProof(array $left, array $right): bool
    {
        return ($left['id'] ?? null) === ($right['id'] ?? null)
            && ($left['amount_off'] ?? null) === ($right['amount_off'] ?? null) && ($left['currency'] ?? null) === ($right['currency'] ?? null)
            && is_string($right['created_at'] ?? null) && is_string($right['starts_at'] ?? null)
            && Timestamp::compare($left['created_at'], $right['created_at']) === 0
            && Timestamp::compare($left['starts_at'], $right['starts_at']) === 0;
    }

    public function invoice(Subscription $subscription, string $actionKey, string $invoiceId, mixed $expectedTotal): Invoice
    {
        $this->context->assertModel($subscription);
        if (self::amount($expectedTotal) === '0') {
            throw new EvidenceException('Paid-remainder verification requires an explicitly approved positive total.');
        }
        $original = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'subscription_id' => $subscription->id, 'action_key' => $actionKey, 'kind' => 'subscription.first_charge_discount'])->whereIn('status', ['in_flight', 'ambiguous', 'succeeded'])->first();
        if ($original === null || $original->remote_id !== $subscription->remote_id || ! is_array($original->observation['discount_proof'] ?? null)) {
            throw new EvidenceException('An original response Discount identity is required before Invoice attribution.');
        }
        Routing::assertSubscription($original->observed_routing ?? []);
        $invoice = app(InvoiceManager::class)->retrieve($subscription, $invoiceId);
        $data = $invoice->toArray();
        Routing::assertSubscription($data);
        $proof = $this->proof($subscription, $data, $original->intent['coupon_terms']);
        $amounts = $data['total_discount_amounts'] ?? null;
        if (($data['status'] ?? null) !== 'FINALIZED' || ! in_array($data['payment_status'] ?? null, ['UNPAID', 'PAID'], true)
            || ! $this->sameProof($proof, $original->observation['discount_proof'])
            || ($data['currency'] ?? null) !== $original->intent['coupon_terms']['currency']
            || ! is_array($amounts) || ! array_is_list($amounts) || count($amounts) !== 1
            || ($amounts[0]['applied_discount_id'] ?? null) !== $proof['id']
            || self::amount($amounts[0]['amount'] ?? null) !== $proof['amount_off']
            || self::amount($data['total_amount'] ?? null) !== self::amount($expectedTotal)) {
            throw new EvidenceException('The original Discount amount and approved Invoice total are not positively attributed.');
        }
        $lines = $invoice->lineItems();
        if ($lines->isEmpty()) {
            throw new EvidenceException('The Invoice has no complete original renewal line proof.');
        }
        foreach ($lines as $line) {
            if (($line['currency'] ?? null) !== $data['currency'] || ! is_string($line['period_starts_at'] ?? null) || ! is_string($line['period_ends_at'] ?? null)
                || Timestamp::compare($line['period_starts_at'], $original->intent['discount_boundary']) !== 0
                || Timestamp::compare($line['period_ends_at'], $line['period_starts_at']) <= 0) {
                throw new EvidenceException('The Invoice service period does not match the original intended renewal boundary.');
            }
        }
        $final = app(InvoiceManager::class)->retrieve($subscription, $invoiceId);
        Routing::assertSubscription($final->toArray());
        $fields = array_flip(['id', 'billing_customer_id', 'subscription_id', 'currency', 'status', 'total_amount', 'total_discount_amounts', 'applied_discounts', 'total_tax_amount']);
        if (! in_array($final->toArray()['payment_status'] ?? null, ['UNPAID', 'PAID'], true)
            || CanonicalJson::encode(array_intersect_key($data, $fields)) !== CanonicalJson::encode(array_intersect_key($final->toArray(), $fields))) {
            throw new EvidenceException('Invoice financial evidence changed during complete attribution reads.');
        }
        DB::transaction(function () use ($original, $subscription, $final): void {
            app(CommitGuard::class)->assertMayCommit();
            ModelRegistry::query('customer')->whereKey($original->customer_id)->lockForUpdate()->firstOrFail();
            $customer = ModelRegistry::query('customer')->findOrFail($original->customer_id);
            $this->context->assertModel($customer);
            $current = ModelRegistry::query('subscription')->findOrFail($subscription->id);
            $this->context->assertModel($current);
            $operation = ModelRegistry::query('operation')->findOrFail($original->id);
            $this->context->assertModel($operation);
            if ($current->customer_id !== $customer->id || $customer->remote_id !== $final->billingCustomerId
                || $current->remote_id !== $original->remote_id || $operation->remote_id !== $current->remote_id
                || $operation->customer_id !== $customer->id || $operation->subscription_id !== $current->id
                || $operation->kind !== 'subscription.first_charge_discount' || $operation->action_key !== $original->action_key
                || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)
                || ! is_array($operation->observation['discount_proof'] ?? null)
                || ! $this->sameProof($operation->observation['discount_proof'], $original->observation['discount_proof'])) {
                throw new OwnershipException('Original Discount operation or owning generation changed during Invoice verification.');
            }
            app(OperationManager::class)->succeed($operation);
        });

        return $final;
    }

    public static function amount(#[SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $value, $parts)) {
            throw new EvidenceException('Discount amounts require exact unsigned decimal strings.');
        }
        $fraction = rtrim($parts[2] ?? '', '0');

        return (ltrim($parts[1], '0') ?: '0').($fraction === '' ? '' : '.'.$fraction);
    }
}
