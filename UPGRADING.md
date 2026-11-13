# Installation and upgrades

This guide covers upgrading from v0.1.0 to v0.2.0. Verify version availability and exact source on [Packagist](https://packagist.org/packages/jojeringnax/airwallex-laravel). Before publication, install the exact reviewed archive using the local `0.2.0-RC1` descriptor in [candidate installation](docs/release.md); that descriptor is not a published tag.

## Upgrading from 0.1.0

Back up the application database and encryption keys, rehearse migrations against a disposable data copy, and preserve existing financial history. Once v0.2.0 is published, select `jojeringnax/airwallex-laravel:^0.2` and update the lock with dependencies. The required SDK is now `jojeringnax/airwallex-php-sdk:^0.1.3`; remove incompatible application pins. Coupons, Credit Notes, transactions and object-preserving views require no development archive or capability override. Default SDK array views remain unchanged; enable `airwallex.preserve_response_objects` explicitly when object/list metadata proof is needed.

Apply all pending package migrations before deploying the new code. They include routing evidence, the encrypted operation observation, persisted sweeps/transitions, Payments tables and the additive creation-retry history migration. Automatic discovery loads them. If the application publishes migrations, compare recorded names and copy only missing files without overwriting customizations or duplicating table creation.

### Creation retries and retained obligations

A new creation action key may now supersede an unknown-ID `in_flight` or `ambiguous` creation and release its local reservation automatically. This deliberately accepts possible provider duplicates; it does not prove the original failed. Reusing an attempted original key never resends it. Original intents/request identities remain history. Known remote resources and other mutation coordination keep their existing rules. Correcting local SDK configuration can resume an unattempted prepared original under its same key.

Implement listeners for [retry and late-locator observations](docs/operations.md#retrying-an-unknown-creation) when the application needs duplicate servicing. Billing uses `CreationRetryObserved`; Payments uses `PaymentObserved` classifications. Late locators stay quarantined until explicitly inspected and serviced by the consumer. An optional context-scoped `airwallex:allow-creation-retry` command records an actor/reason privately; ordinary new-key customer retry needs no operator action.

The migration backfills current `order_reservation` values from immutable `merchant_order_id`. Superseded Intent generations release their own current reservation while retaining order history. Rollback to the old unique-order schema refuses once duplicate-order history exists. Do not delete or rename financial records to force rollback. Canonically observed remote refunds still consume refundable capacity after an unseen local reservation is released.

### Saved instruments and optional subscription servicing

Embedded Payments setup can create a Billing Source after canonical Consent/Method verification. Payments and Billing Customers must represent the same billable/environment, and the Billing collector must equal the Payments account. [The source workflow](docs/instruments.md) retains independent command identities; setup/source creation does not establish successful collection or remove possible browser action.

Merge the new `payments.events.instrument_revoked_handler` configuration key into a published config if needed. Its default is `null`, leaving subscriptions unchanged. A configured `Contracts\InstrumentRevocationHandler` selects an explicitly authorized replacement/cancellation policy using the package's ordinary durable commands and chosen financial controls. Local revocation and a later remote disable may produce distinct observations. Deduplicate event redelivery by event ID, and reconcile the consumer's persisted desired subscription state across observations; event-ID deduplication alone does not combine those separate facts.

### Timing, discounts and existing commands

`trialUntil()` sets an exact timezone-bearing trial end for direct and hosted creation and cannot be combined with `trialDays()`. `cancelAt()` requires explicit update proration/billing controls and supported original renewal bounds. It schedules the same owned generation; restoration uses its saved cancellation action and original bounds. It computes no refund or shortened-period charge. See [timing contracts](docs/contracts.md#dated-subscription-ending).

Managed Coupons use `withCoupon()`, `applyCoupon()` and `removeCoupon()`. Replace direct raw creation `discounts` with `withCoupon($couponId)`. A valid single top-level raw hosted Coupon remains accepted through the managed proof path. Existing raw hosted operations without saved Coupon terms keep their earlier unverified contract and hashes; they cannot be retrofitted or authorize `removeCoupon()` as managed originals.

Use the dedicated verified Coupon API for managed subscription discounts; migrate raw discount inputs as described in [public contracts](docs/contracts.md). Coupon administration remains application-owned through the SDK. Do not reinterpret provider CUSTOM calendar duration as a guaranteed count of discounted invoices or assume ONCE affects a later invoice on an already-ACTIVE subscription. Existing bounded free-period and experimental first-charge commands retain their original intent hashes and evidence requirements. Run the operation-observation migration before using discount evidence or [owned Invoice credits](docs/contracts.md#owned-invoice-credits). Credit creation, exact original-line additions and approved finalization remain separate durable actions; financial effects and settlement are separate observations.

New deferred transitions use financial baseline shape v3, including billing anchor/cadence/tax controls. Saved v1/v2 plans and existing prepared operations retain their identities. Explicitly cancel/recreate an unattempted plan to adopt stronger guards. Generic item helpers refuse discount mutation inputs because item confirmation does not prove discount effects. Already-attempted originals retain read-only recovery.

Use [renewal restoration](docs/contracts.md#adoption-amounts-and-provider-routing) only for its supported combinations. Imported/old schedules without saved original bounds cannot reconstruct them. An indefinite AUTO_CHARGE original requires the explicit null bounds triplet when scheduling. Custom subclasses overriding `cancelAtPeriodEnd`, `applyTransition` or `SubscriptionSynchronizer::reconcile` must match the current optional parameters before loading.

### Configuration and deployment

Review published configuration, rebuild `config:cache`, restart workers and verify the application's queue/cache/lock tables and scheduler. Billing queue-connection defaults preserve the application default. Payments uses separate account credentials, webhook signing and an opt-in receiver; a Billing organization/Source/secret is not a Payments identity. See [operations](docs/operations.md), [Payments recovery](docs/payments-webhooks.md) and [supported profiles](docs/compatibility.md).

Existing standalone `Billable` calls remain available. Models already using Cashier should use `AirwallexBillable` for authorized Airwallex work and retain original-provider routes for historical renewals/refunds. Never derive Payments mappings from Billing, email or Stripe identifiers. Each application qualifies its own deployment and client integration. Account-specific Sandbox journeys are supplemental to library release qualification; fixtures establish no account activation, payment success or settlement.

## Identity, rollback and uninstall

Keep environment/organization/account, billable morph identity and remote parent/resource IDs stable. Credential rotation does not change mapping identity. Retain database backups and old encryption keys needed to decrypt history. Morph-map or custom-table changes require an application data/schema migration preserving unique/FK constraints, immutable fields and casts.

Routing and schedule-cap backfills use positively owned provider evidence; they do not fabricate missing bounds or erase previously confirmed routing contradictions. Reconcile incomplete observations. Preserve receipts, operations, request IDs, delivery IDs and history through upgrades; restoring a prepared row does not prove its old write was never sent.

During 0.x, breaking public changes require a new minor, release notes and migration guidance; only the latest minor is maintained. Compare exact package/SDK/API/webhook versions and qualify dependency audit, migrations and recipes on the application's chosen profile. API headers alone do not migrate webhook-subscription versions.

For uninstall, follow [offboarding and retention](docs/operations.md#retention-replay-and-offboarding). Explicitly service subscriptions, pending commands and lawful history access first. Removing code, rolling back tables or deleting a billable never cancels provider resources. Schema rollback is a data-removing uninstall operation, not a financial recovery strategy.
