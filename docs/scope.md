# Scope and access rules

The accepted product baseline is [release tracker #1](https://github.com/jojeringnax/airwallex-laravel/issues/1). This reference owns durable capability and access requirements; GitHub owns execution status. [Public contracts](contracts.md) own signatures/schema, [provider contracts](provider-contracts.md) own evidence, and [compatibility](compatibility.md) owns support policy. Public implementation choices are accepted under [#34](https://github.com/jojeringnax/airwallex-laravel/issues/34).

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

## Deferrals

High-level standalone Payments/refunds, multiple Billing organizations/connected-merchant routing, OAuth, catalogue administration, embedded/Elements components, customer portal UI, generic local trials, tax/coupons/usage billing, pause/reactivation and unverified schedule reversal/resume conveniences are deferred. This does not remove raw SDK capabilities. Provider invoice-link renewal, expiry, PDF generation and mailing guarantees are not inferred.
