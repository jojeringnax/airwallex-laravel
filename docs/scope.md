# Scope and access rules

The released v0.1 baseline is [release tracker #1](https://github.com/jojeringnax/airwallex-laravel/issues/1). This reference owns durable capability and access requirements; GitHub owns execution status. [Public contracts](contracts.md) own signatures/schema, [provider contracts](provider-contracts.md) own evidence, and [compatibility](compatibility.md) owns support policy. Public implementation choices are accepted under [#34](https://github.com/jojeringnax/airwallex-laravel/issues/34).

## Capabilities

| Capability | Boundary |
| --- | --- |
| Billable Eloquent User, Team, Company or custom model | Separate polymorphic Customer; application authorizes its local model without a package authentication guard |
| Direct subscription and hosted subscription checkout | Explicit local name, multi-item builder, provider trials/future starts, one configured Billing organization |
| Hosted setup and source selection/replacement | Billing Source identity/owner/collector verified; setup grants no subscription access |
| Existing customer link and subscription import | Explicit authorized association and caller-supplied name, read-only import, no email-based adoption |
| Subscription changes/cancellation | Exact values and explicit provider financial controls; no package proration calculation |
| Invoice access | Owned invoice/line-item reads and provider PDF/hosted links; transaction observations require the explicitly selected SDK extension under [public contracts](contracts.md#invoice-transaction-observations); no local cash ledger, allocation or settlement inference |
| Webhooks and recovery | SDK signatures/decoding, durable receipts, canonical reconciliation, events, dispatch/replay/reconcile tools |
| Extensibility | Configured model subclasses, billable profiles and replaceable access policy; raw SDK remains accessible |

The SDK owns authentication, HTTP, request validation, lossless representation and signature verification. Laravel owns local persistence, orchestration, receipts/jobs, reconciliation and events. Sandbox/production and owning organization form immutable identity; credentials may rotate without changing mappings. Each local billable/context has one Customer; each remote Customer/context has one local billable.

## Eligibility

Helpers are local-only. `active()` means eligible ACTIVE, excluding trials; `onTrial()` means eligible IN_TRIAL; `valid()` and Billable `subscribed(name)` apply the replaceable access policy. The default policy is:

| Confirmed status | Access |
| --- | --- |
| PENDING | Denied; local time cannot promote state |
| IN_TRIAL | Allowed only within consistent confirmed trial and subscription bounds |
| ACTIVE | Allowed within confirmed subscription bounds |
| UNPAID | Immediately denied |
| CANCELLED | Immediately denied |
| Unknown future value | Denied, value preserved, reconcile |

Starts are inclusive, ends exclusive. Missing/inconsistent required timing denies default access. Expired trial/subscription end dates cap stale snapshots. A normal `current_period_ends_at` rollover does not end recurring ACTIVE access. Scheduled cancellation caps access at the earliest positively confirmed effective end, retained across partial reads and period rollovers; a fresh scheduled flag without a confirmed bound grants no default access; `onGracePeriod()` describes that scheduled ending, never payment-failure grace. Scheduling UNPAID ending cannot restore access. Confirmed immediate cancellation denies access immediately; a timeout retains the last confirmed projection and ambiguous operation.

ACTIVE denotes lifecycle eligibility, not settlement of every invoice. Provider recovery runs independently: UNPAID does not cause package cancellation, a universal retry deadline or an inferred eventual CANCELLED state. Applications may override access policy without mutating provider status. Scheduled reconciliation repairs missed changes; read failures retain the last confirmed projection.

## Identity and history

One current reservation exists per explicit customer/name. PENDING, IN_TRIAL, ACTIVE, UNPAID, unknown and scheduled-ending observations reserve it, as do in-flight creation/checkout/import. Local access expiry cannot release a financial reservation. Confirmed termination permits a new generation, preserving history.

`subscription(name)` returns current, otherwise latest history for inspection. `subscribed(name)` checks current only; history cannot restore access. Stable local creation identity orders history, never mutable provider timestamps. All synchronization resolves immutable context/remote ID: late old-generation events update only that historical row and cannot displace or release its replacement.

Checkout/setup/browser success grants no independent access. Verify owned resulting resources and canonical Subscription state. Replace item projections only after every page succeeds; item-level historical audit ledgers are deferred. Unknown resources require explicit association.

## Full-provider adoption contract

[Programme #46](https://github.com/jojeringnax/airwallex-laravel/issues/46) expands reusable orchestration while preserving v0.1 behavior. The library is application-agnostic. This matrix specifies reusable capabilities and package/SDK/application boundaries; its examples do not prescribe a particular application, business model or deployment. Under the [accepted ownership decision #48](https://github.com/jojeringnax/airwallex-laravel/issues/48), this package owns durable Payments identities, commands, reservations and canonical recovery; the SDK owns transport/validation/precision/signatures; the application owns commercial policy, clients and ledgers.

| Consumer scenario | Required outcome, money effect and owner |
| --- | --- |
| Direct / hosted subscription create | Package uses an explicit named reservation and durable action. SDK validates exact provider input. Only canonical resulting Subscription establishes eligibility; browser return establishes none. Application selects catalogue price, collection policy and authorized source. |
| Trial, invitation, coupon free period | Application computes explicit timezone-bearing start/trial end from its commercial rules; package persists those resolved dates. No charge or local trial entitlement is inferred. A free catalogue price is distinct from an empty/zero Payments charge. |
| Immediate upgrade | Application chooses target price, explicit provider proration/billing controls and entitlement policy. Package performs durable item change, preserving original subscription ownership and recovery identity. Canonical ACTIVE is lifecycle evidence, not cash-settlement evidence. |
| Next-boundary downgrade / future transition | Application selects a confirmed boundary or explicit future date and approved target. Package must store a due transition, generation/expected-state guards and one stable financial command. Canonical preflight precedes execution; ambiguity holds it without replay. Scheduling research and implementation are [#158](https://github.com/jojeringnax/airwallex-php-sdk/issues/158) / [#54](https://github.com/jojeringnax/airwallex-laravel/issues/54). Local execution can be delayed and cannot promise an atomic provider boundary. The native [next-cycle helper](contracts.md#adoption-amounts-and-provider-routing) submits NONE before the confirmed boundary; item projection and application entitlement dates remain separate. A locally due NONE request submitted after renewal can price a later cycle instead. |
| Auto-renew off / on / immediate cancel | Off uses confirmed scheduled cancellation. On requires verified reversal of a pending cancellation on the same owned generation, never recreation of a terminated subscription. Immediate cancel uses explicit proration. Restoration proof is [#158](https://github.com/jojeringnax/airwallex-php-sdk/issues/158) / [#53](https://github.com/jojeringnax/airwallex-laravel/issues/53); the supported combinations and saved-intent requirements are defined in [public contracts](contracts.md#adoption-amounts-and-provider-routing). |
| Saved-card setup/list/default/revoke | Independent account-owned Payments Customer/Consent/Method; Billing Source is not a Payments consent. Embedded browser setup collects explicit authority; package verifies canonical status/customer/context before adoption. Application chooses a local default among eligible instruments; revocation uses the SDK and retains history/in-flight obligations. |
| Merchant-initiated saved-card charge | Application authorizes purpose, exact amount/currency and stable command. Package retains intent identity and canonical recovery; only confirmed successful payment establishes payment completion. Consent purpose/merchant indicators are explicit, never inferred from card presence. |
| Shopper-present cards, wallets and 3DS | Application owns Airwallex.js/Apple Pay/Google Pay UI, registered domains and callback/API authorization. Package returns an explicitly requested transient bootstrap and reconciles the exact intent. Pending/action-required/failed are distinct from success. No browser callback independently posts money. |
| Zero amount | Application records a no-money commercial outcome or uses the dedicated consent/setup flow. Package does not fabricate a successful zero-value PaymentIntent or convert it to a positive charge. |
| Partial/full refund | Application selects owned successful payment, amount and business allocation. Package reserves cumulative refundable amount and keeps one original refund identity through pending/failed/ambiguous outcomes. Only canonical successful refund produces financial completion; application posts its credit note/ledger once. |
| Offboarding | Application stops new authorization, resolves pending commands and financial holds, cancels subscriptions/revokes instruments explicitly where supported, and preserves old-provider/refund/accounting history. Deleting a billable does not erase obligations or trigger charges. |

Representative provider-qualified application boundary (the `AirwallexBillable` seam is delivered by [#51](https://github.com/jojeringnax/airwallex-laravel/issues/51)):

```php
// Authorize this original persisted model first; keep its existing Cashier trait.
$billing = new \Jojeringnax\AirwallexLaravel\AirwallexBillable($authorizedCustomer);
$billing->createOrGetAirwallexCustomer('customer-command-123', ['name' => 'Example']);
$subscription = $billing->newSubscription('membership', $verifiedPriceId)
    ->trialDays(7)->create('membership-command-124', ['payment_source_id' => $ownedBillingSourceId]);
$subscription->cancelAtPeriodEnd('renewal-off-command-125');
$authorizedCustomer->subscription('legacy'); // Existing Cashier API still belongs to Stripe.
```

The [Billing recipes](usage.md) remain executable against the standalone trait. [Payments recipes #62](https://github.com/jojeringnax/airwallex-laravel/issues/62) must qualify its separate account context, setup/bootstrap, intents and refund APIs before those scenarios are ready; the matrix does not promise an unreleased signature.

### Catalogue, coupons and access

Application aliases map business tier/product/price/currency/environment to verified immutable provider price IDs. Never derive Airwallex IDs from Stripe IDs, display names or email. A catalogue audit owns cadence, zero prices, active versions and replacements; this package does not administer the catalogue.

Applications own coupon eligibility, redemption and free-period calculation; the package performs no automatic native-provider discount mapping. An integration migrating existing discounts must inventory its own provider IDs and verify the amount, percentage and repetition behavior it needs. If native recurring invoice discounts are required, select and qualify [SDK coupon work #38](https://github.com/jojeringnax/airwallex-php-sdk/issues/38) explicitly; a free period is not an equivalent discount implementation.

The default access matrix above remains unchanged, including immediate UNPAID denial. The application must explicitly replace `SubscriptionAccessPolicy` if it requires legacy grace, and qualify stale observations, trial/end bounds and restoration separately. Local commercial membership history never promotes an unconfirmed provider payment or subscription.

### Browser and saved-instrument migration

Billing keeps its hosted checkout contract. Standalone Payments uses embedded Airwallex.js consent/intent bootstrap with transient client secrets returned only through an authorized endpoint; client secrets never enter durable intents, logs, events or serialization. [The accepted setup-secret policy](instruments.md) requires original recovery and a separately authorized new setup when the create response is lost. A Billing setup checkout cannot supply a Payments Customer or consent. Mobile clients need their own native SDK/deep-link implementation and qualification; a web recipe does not establish mobile compatibility.

Recollection through fresh explicit setup is the viable default. Provider-assisted transfer is optional and externally gated: the merchant completes KYB and arranges an encrypted processor export and Airwallex migration report. The application migration owner maps processor customer/card identifiers to already authorized local owners, verifies resulting account-owned Customer/Consent/Method canonically, and quarantines unmatched/failed records. Package fixtures establish no completed migration. The commercial owner must plan recollection and retain old-provider servicing until the transfer/recollection coverage is proven. [Provider evidence](provider-contracts.md#saved-instrument-transfer) records the official prerequisites.

### Downstream work and unsupported scenarios

Applications own authorization, provider routing, catalogue and commercial policy, client interfaces, ledgers/accounting and deployment. The [integration guide](adoption-handoff.md) provides adaptable migration and operational patterns. Application implementation, runtime upgrades and production cutover are outside library delivery; package qualification establishes only the tested library profiles.

Multiple Billing organizations, connected-merchant products, OAuth, portal UI, tax/metering administration, package-owned commercial ledgers and arbitrary provider schedule phases remain outside this programme. Exact boundary execution, cancellation restoration and native discounts remain unavailable where their required provider evidence is missing. Provider invoice-link renewal/expiry, PDF generation/mailing and settlement guarantees are not inferred.
