<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;

// The application authorizes eligibility and selects an owned active 100% CUSTOM Coupon.
// Explicitly install/qualify an SDK with Coupon retrieval. Invoice effects need separate proof.
// This discount recipe does not calculate a partial free interval's paid remainder.
return static fn (): array => [
    'apply' => static fn (Subscription $subscription, string $actionKey, string $couponId): Subscription => $subscription->applyFreePeriod($actionKey, $couponId),
    'remove' => static fn (Subscription $subscription, string $actionKey, string $originalActionKey): Subscription => $subscription->removeFreePeriod($actionKey, $originalActionKey),
    // Already-billed service: the consumer selects exact credits and any native refund allocation.
    // Each stage is a separate durable decision; inspect the draft total/tax before finalizing.
    'create_credit' => static fn (Invoice $invoice, string $actionKey, string $type, string $reason, array $options = []): Operation => $invoice->createCreditNote($actionKey, $type, $reason, $options),
    'add_credit_items' => static fn (Invoice $invoice, string $actionKey, string $createActionKey, array $items): Operation => $invoice->addCreditNoteItems($actionKey, $createActionKey, $items),
    'finalize_credit' => static fn (Invoice $invoice, string $actionKey, string $createActionKey, mixed $expectedTotal, array $allocation = []): Operation => $invoice->finalizeCreditNote($actionKey, $createActionKey, $expectedTotal, $allocation),
    // An exact FLAT/ONCE first-charge reduction; an intervening Invoice can consume it.
    'apply_first_charge' => static fn (Subscription $subscription, string $actionKey, string $couponId, mixed $amountOff, string $currency, string $boundary): Subscription => $subscription->applyFirstChargeDiscount($actionKey, $couponId, $amountOff, $currency, $boundary),
    'remove_first_charge' => static fn (Subscription $subscription, string $actionKey, string $originalActionKey): Subscription => $subscription->removeFirstChargeDiscount($actionKey, $originalActionKey),
    'first_charge_invoice' => static fn (Subscription $subscription, string $originalActionKey, string $invoiceId, mixed $expectedTotal): Invoice => $subscription->firstChargeDiscountInvoice($originalActionKey, $invoiceId, $expectedTotal),
];
