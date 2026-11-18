# Owned Payments refunds

Use `Payments::for($authorizedOriginalBillable)` after application authorization. An Airwallex Payments refund retains its original merchant account, Payments Customer and captured Intent. Legacy-provider payments keep their original provider/refund path; a Billing Invoice or Credit Note is not a Payments Intent.

```php
$available = $payments->refundableAmount($ownedIntent); // Conservative observed ceiling, never a funds/eligibility promise.
$refund = $payments->refundIntent($ownedIntent, $permanentBusinessActionKey, '12.34', ['reason' => 'Return']);
$refund = $payments->reconcileRefund($refund);
// After an interrupted attempt, reuse the original operation; an optional known ID is a locator requiring canonical proof.
$refund = $payments->recoverRefund($originalOperationId, $operatorKnownRefundId);
```

Amount is an explicit positive decimal string in the owned Intent's immutable currency; no float, FX or automatic remaining-amount mutation is used. For a full remaining refund, the application persists the exact chosen amount from its reviewed observation and supplies it as a new authorized action. A later preflight may reject that amount if capacity changed. Zero is not a refund.

Before creating a refund the package canonically verifies the same customer/order/Intent and positively observed SUCCEEDED/captured state, reads every original-Intent refund page, verifies any returned attempt against that same Intent, and reserves the requested amount under the shared customer mutation mutex. Every non-FAILED remote refund and unseen local pending/ambiguous reservation counts against captured funds. Incomplete/foreign/duplicate pages, a list exceeding 10,000 pages or unknown proof cannot yield partial capacity or authorize a write. Other provider clients can race these observations; the provider still decides disputes, method/time limits and available settlement-currency funds. This is no merchant-balance guarantee.

The original application action, request ID and any returned refund ID are durable before confirmation. Timeout, missing list entry, 404, local expiry and failed readback authorize no create replay or replacement identity. Known-ID or operator-located recovery must prove canonical request correlation, original Intent/attempt, amount/currency and context. Unknown outcomes keep their financial hold. A known RECEIVED refund reserves capacity but its confirmed creation command releases the mutation mutex, permitting another independently authorized partial refund within the remaining observed ceiling. FAILED returns no successful-refund claim and may release that observed capacity; a later attempt requires a separate explicit business action.

`Refund::completed()` is true only for canonical ACCEPTED/SETTLED. RECEIVED means pending; FAILED is failure; unknown statuses remain inspectable and reserve capacity. SETTLED is separately visible and is not proof that the shopper's bank credited its account. Safe stable observation payloads retain `financial_identity=refund/{canonicalId}` across different delivery IDs and ACCEPTED/SETTLED observations; application credit-note/ledger listeners deduplicate their refund-completion effect using that identity plus provider/environment/account. The package creates no application credit note, Billing Credit Note or accounting row.

## Refunds created outside the package

The [Payments receiver](payments-webhooks.md) can discover a previously unknown Refund from a signed event. The event supplies its ID. The package retrieves Refund, original Intent and any linked Attempt under the original merchant account. It verifies a mapped owned capture, matching currency and positive exact refund amount within captured funds. A linked Attempt must belong to that Intent and show sufficient captured amount. Unknown local payments produce no refund projection. An event cannot supply an owner, status, reason or amount for the projection.

If the canonical ID or request ID matches an existing package command, the package recovers that command and its original Refund row. An unknown Refund without a request ID stays retryable while a matching package command for the same Intent and amount has an unresolved resource identity. A genuinely external refund has a nullable `operation_id` and `Refund::origin()` returns `provider_created`. Package-initiated rows return `package_initiated`. Provider-created denotes a refund first seen externally, including a staff dashboard action or another client. The API does not attest which client initiated it. Refund IDs remain unique in the configured account/environment. `Refund::reason()` and `Refund::paymentAttemptId()` return the optional canonical fields. `intent_id` links to the owned local Intent, whose `remote_id` is the provider payment reference.

Schedule a bounded application job over authorized captured Intent IDs in stable local ID order. After a missed webhook or any processing outage, resume that cursor and call:

```php
foreach ($payments->reconcileRefunds($ownedIntent) as $refund) {
    // Inspect the current projection. Handle accounting through the stable observation identity.
}
```

`reconcileRefunds(Intent $intent): array` reads all pages of the original Intent's Refund List (100 per page, zero-based, until `has_more` is false) and validates all list evidence before projecting canonical Get results. It returns a list of owned `Refund` models, including failed and pending records. It can commit earlier canonical reads if a later Get fails. Retry the same Intent after the outage or conflict. Advance the application cursor after success. Known Refunds also converge using `reconcileRefund` and the package's existing Refund recovery family. A known provider-created Refund missing from one List result retains its last confirmed reservation until new canonical evidence.

Refund observations use the same `PaymentObserved` resource type `airwallex_payment_refunds`. For discovered provider-created refunds the safe payload includes `origin=provider_created` in addition to refund ID, original Intent ID, amount, currency, provider status and financial identity. Use the owned projection when you need the optional reason. The status and delivery ID converge on replay and out-of-order notifications by reading the current provider resource. Deduplicate credit-note effects by the refund financial identity, including when ACCEPTED later becomes SETTLED. FAILED remains an observation with no credit.

Deploy the nullable operation-link migration before enabling discovery in an existing installation. Rolling back that migration requires preserving externally observed history outside the older schema.

## Contract evidence and qualification

The required SDK is released v0.1.3, source `33881b46d696435dfbd370ec371e1fef5452ba44`, API `2026-08-21`. Official [Refund API](https://www.airwallex.com/docs/api/payments/refunds) and [refund guide](https://www.airwallex.com/docs/payments/payment-operations/manage-payments/refunds) were rechecked on 2026-11-11. Fresh page metadata selected that API date, `airwallex-openapi-2026-08-21.yaml`, advertised MD5 `6caa23f3759634e64859ff6aa8c37336`; the category matches the retained selected research. Refunds support create/retrieve/list at account level with API-key-supported `payment_acceptance:write` / `payment_acceptance:read`. Provider OBO support does not enable delegation in this package's explicit merchant-login context. Request creation takes an Intent or Attempt ID, optional amount/reason/metadata and required request ID. This package always sends an explicit amount against the owned Intent. SDK owns the endpoint/request validation, precision, authentication and HTTP.

The selected schema describes ACCEPTED as payment-method-provider completion and SETTLED as reconciliation into Airwallex settlement reporting. The guide retains method/dispute/refundable-funds prerequisites and states shopper bank posting is outside Airwallex visibility. Deterministic SDK transport tests establish package ownership/reservations/recovery only. Actual refund movement, eligibility, account funds, settlement and bank-credit timing need the separately authorized Payments journey. [Programme #59](https://github.com/jojeringnax/airwallex-laravel/issues/59) owns execution and review status.

An attempted Refund creation with unknown remote identity retains its local hold when another creation key is supplied. An explicit consumer/operator release is required under the [shared retry policy](operations.md#retrying-an-unknown-creation). This deliberately permits a duplicate request, preserves the original Refund/Operation history and does not assert the earlier request failed. All canonically listed nonfailed remote refunds still reduce available capacity. A late original locator is quarantined for consumer servicing; no financial completion is emitted from that locator alone.
