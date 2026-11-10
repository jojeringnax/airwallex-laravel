# Public interfaces and persistence contract

This is the **proposed implementation contract**, not a claim that examples already execute. Accepted invariants are in [scope](scope.md); material choices are provisional under [question #34](https://github.com/jojeringnax/airwallex-laravel/issues/34), support under [#10](https://github.com/jojeringnax/airwallex-laravel/issues/10), and provider facts under [provider contracts](provider-contracts.md). Implementation must update examples to verified status with executable recipe evidence.

## Consumer API

Applications authorize the local billable first. Any persisted Eloquent model uses `Jojeringnax\AirwallexLaravel\Billable`; keys may be integer/UUID/ULID and morph maps remain effective. Methods do not assume an authentication guard. `airwallexProfile(): array` is overridable and returns provider customer profile keys; saving a model never syncs remotely.

```php
$customer = $team->createOrGetAirwallexCustomer('customer-command-123', ['name' => 'Team']);
$customer = $team->linkAirwallexCustomer($remoteCustomerId);
$customer = $team->syncAirwallexCustomer('profile-command-124', ['email' => null]);

$subscription = $team->newSubscription('default', $priceId)
    ->quantity(5)->trialDays(7)->startsAt('2026-12-01T00:00:00Z')
    ->create('subscribe-command-125', ['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => $sourceId]);
$checkout = $team->newSubscription('support', $supportPriceId)
    ->quantity(2)->checkout('checkout-command-126', ['success_url' => $successUrl, 'back_url' => $backUrl]);
return $checkout->redirect(); // Explicitly expose the provider HOSTED URL.

$setup = $team->setupPaymentMethod('setup-command-127', ['currency' => 'USD', 'success_url' => $successUrl, 'back_url' => $backUrl]);
$setup->reconcile(); // Verifies checkout and resulting source; does not grant access.
$sources = $team->paymentSources(); // All pages, owned customer/collector.
$checkout->reconcile(); // Verifies resulting subscription under its reserved name.
$checkout->updateMetadata('metadata-command-128', ['reference' => 'support']);
$checkout->cancel('cancel-checkout-command-129'); // SDK action is bodyless.

$subscription = $team->importSubscription('default', $remoteSubscriptionId);
$subscription->updatePaymentSource('source-command-130', $sourceId);
// Explicit action: may immediately retry the provider's last failed invoice.
$subscription->updateQuantity('quantity-command-131', 6, ['default_proration_mode' => 'NONE']);
$subscription->updateQuantity('item-command-132', 3, ['default_proration_mode' => 'PRORATED'], $itemId);
$subscription->swapPrice('swap-command-133', $newPriceId, ['default_proration_mode' => 'PRORATED'], $itemId);
$subscription->addItem('add-command-134', $anotherPriceId, 1, ['default_proration_mode' => 'NONE']);
$subscription->removeItem('remove-command-135', $itemId, ['default_proration_mode' => 'NONE']);
$subscription->updateItems('custom-command-136', [['id' => $itemId, 'quantity' => 3, 'proration_mode' => 'CUSTOM', 'proration_value' => ['charge_amount' => '100.00', 'credit_amount' => '20.00']]], ['billing_action' => 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE']);
$subscription->cancelAtPeriodEnd('ending-command-137');
$subscription->cancelNow('cancel-command-138', 'NONE');
$subscription->reconcile();

$team->subscription('default'); // Current, otherwise latest history for inspection.
$team->subscribed('default'); // Current only, no HTTP.
$subscription->active(); $subscription->onTrial(); $subscription->valid(); $subscription->onGracePeriod();
$invoice = $team->findInvoice($invoiceId);
$invoices = $team->invoices();
$invoice->pdfUrl(); $invoice->hostedUrl(); $invoice->lineItems(); $invoice->lineItem($lineItemId);
$subscription->invoices();
```

`newSubscription(name, priceId)` requires a nonempty explicit name. `addPrice(priceId, quantity)` supports multi-item creation. Request quantities are native positive integers under the SDK schema; observed response quantities remain exact decimal strings, including fractional values. Unsupported observed item shapes block unsafe item changes, not otherwise valid lifecycle access. Single-item conveniences fail when the target is ambiguous; multi-item changes require the item ID. Price swaps use deletion plus addition, never an immutable item price update. Caller options cannot override mapped customer/context, durable request identity or managed items. `trialDays(days)` uses the final explicit `startsAt` as its anchor, or the intent-preparation time when absent, independently of builder call order. Persist the resulting absolute dates in the prepared intent; repeated action keys reuse them rather than shifting a trial with the clock.

`actionKey` is a stable application command identifier, scoped to immutable billing context. When `createOrGetAirwallexCustomer` finds an already confirmed mapping, it performs a local read: supplied profile values do not sync, a fresh key remains unused, and unrelated mutation holds remain intact. An already-recorded key still validates its original mutation intent and resolves any original ambiguity; use explicit customer sync to update profiles. Persist it in application command handling. Same key/intent returns or recovers the same operation; changed kind/target/payload conflicts. A distinct user-authorized financial action needs a new key. Ambiguous operations throw an actionable exception containing the safe local operation ID; they never silently reissue the write. Customer and Subscription methods return the configured corresponding Eloquent model after canonical verification. `setupPaymentMethod` returns the configured Checkout; `paymentSources` returns a collection of immutable lightweight `PaymentSource` objects (remote `id`, `billingCustomerId`, `linkedPaymentAccountId`, sanitized provider data), with no source table. `updatePaymentSource` returns the reconciled Subscription. Checkout methods return the configured Checkout, invoice reads return immutable lightweight Invoice objects, and list reads return collections after all pages succeed. Unmapped billables return no local subscription; remote operations require a confirmed customer.

Public package exceptions extend `BillingException` (RuntimeException): `ConfigurationException`, `OwnershipException`, `ConflictException`, `EvidenceException` and `AmbiguousOperationException`. Messages contain safe classifications only; `AmbiguousOperationException` exposes readonly string `operationId` and retains only already-redacted SDK exceptions as causes, preserving SDK classification. Untrusted transport/storage/confirmation causes are discarded; default string/debug output excludes traces/arguments and exception serialization is rejected. `ConflictException` may expose a nullable readonly `operationId`; other exceptions need no payload/context dump. SDK exceptions retain their original classification as causes; no secret payloads are added. Raw SDK `Client` is replaceable in the container; `Airwallex` facade resolves it. `BillingContext` is immutable environment/organization; credentials never form database identity. Provider access policy interface is `SubscriptionAccessPolicy::allows(Subscription $subscription): bool`, replaceable through the container. Default lifecycle helpers follow [scope](scope.md), independently of custom policy.

## Schema ownership

All tables use `airwallex_`; local primary keys are ULID strings. Configured subclasses extend the corresponding package model; the service provider resolves all relationships and service queries through configured classes. Local Laravel timestamps are UTC microseconds. Provider dates/timestamps are exact text with timezone/offset/fraction retained; a chronological comparator handles arbitrary fractional precision. JSON snapshots preserve SDK-decoded unknown fields and decimal strings; populated numeric-key object wire types may already be normalized by the SDK ([#37](https://github.com/jojeringnax/airwallex-laravel/issues/37)), so those ambiguous updates require further proof rather than inferred confirmation; raw owner/profile data and secrets are hidden from normal model serialization.

| Table | Fields and constraints |
| --- | --- |
| `airwallex_customers` | `id`, `environment`, `organization_id`, `billable_type`, `billable_id` (opaque string), nullable `remote_id`, `synced_at`, timestamps. Unique local morph/context and remote/context. Reservation precedes creation. |
| `airwallex_subscriptions` | `id`, `customer_id`, context, `remote_id`, `name`, raw `status`, internal monotonic `revision` for concurrent observations, `starts_at`, `ends_at`, `trial_starts_at`, `trial_ends_at`, `current_period_starts_at`, `current_period_ends_at`, `cancel_at_period_end`, `cancel_requested_at`, `provider_created_at`, `provider_updated_at`, sanitized `snapshot`, `synced_at`, `items_synced_at`, timestamps. Remote/context unique; owner immutable. Optional date columns retain the last positively confirmed timestamp when later responses omit it or return null; raw snapshots preserve the precise absence/null. A first absent/null observation remains null. This conservative local bound policy is provisional under [#34](https://github.com/jojeringnax/airwallex-laravel/issues/34#issuecomment-6769270411), pending verified clearing semantics. Provider timestamp field names remain unchanged; no cancellation timestamp is fabricated from observation time. |
| `airwallex_subscription_items` | `id`, `subscription_id`, `remote_id`, nullable `price_id`/`quantity`, `snapshot`, provider timestamps, local timestamps. Unique subscription/remote ID; item response parent must match. Never fabricate absent price/quantity. |
| `airwallex_subscription_slots` | `id`, `customer_id`, `name`, nullable `subscription_id`, nullable `operation_id`, timestamps. Unique customer/name. Current pointer or in-flight reservation; retained through unknown/UNPAID/access expiry. |
| `airwallex_operations` | `id`, context, `customer_id`, optional `subscription_id`, `kind`, `action_key`, `intent_hash`, encrypted non-secret `intent` (stable specification, resolved dates, resource/correlation inputs), provider `request_id`, optional `remote_id`, `status`, sanitized `error_code`, nullable unique context/`lock_key` for the active mutation, timestamps. Unique context/action key; opaque payload digest compares intent, no credentials/client secrets. |
| `airwallex_checkouts` | `id`, `customer_id`, `operation_id`, context, optional name, `mode`, `remote_id`, `status`, returned `expires_at`, optional verified `subscription_id` (local Subscription FK), `remote_subscription_id` and `payment_source_id` (remote Source ID), protected URL, timestamps. No client-secret persistence. Remote checkout ID is unique within environment/organization. |
| `airwallex_domain_events` | `id` (stable delivery ID), context, resource type/local ID, event name, sanitized payload, nullable `dispatched_at`, `attempts`, lease fields, timestamps. Persist in the same transaction as the projection; scheduler redispatches unacknowledged rows. |
| `airwallex_webhook_receipts` | `id`, trusted context, `event_id`, body digest, `event_name`, version, resource identity, encrypted body, `status`, `attempts`, `last_error`, timestamps. Unique context/event ID; conflicting same-ID bodies quarantined. |

Provider IDs and owner/context cannot be reassigned by a later observation or mass-assigned as a side effect of reconciliation. DB constraints enforce uniqueness; services validate parent/context on every attach. Default database is the application's configured connection; only the qualified engine is supported. Migrations are publishable and reversible; no billable-table changes. Offboarding never automatically deletes history or performs remote cancellation; application retention/authorization decides local deletion after operational and legal review. Customer/subscription deletion is restricted while children/reservations exist.

## Operation, checkout and receipt lifecycles

Operations: `prepared` persisted before I/O, then `in_flight`, then `succeeded`, `failed` (verified no-write rejection), or `ambiguous`. Preserve original request/correlation and record returned remote identity before follow-up reads. Never hold a DB transaction over network calls. Unique rows and compare-and-set transitions coordinate competing writers. An expired worker lease is recovery eligibility, not permission to replay a financial write. Known-ID operations recover through canonical reads; unknown-ID create requires verified metadata correlation or an explicit operator-supplied resource and ownership proof. A 404 or timeout alone cannot release the name. The SDK exposes no checkout list; record the specific upstream automation limit rather than adding Laravel HTTP.

A Checkout reserves its subscription name from intent through confirmed resulting Subscription or verified terminal cancellation/expiry without a resulting resource. Ambiguous terminal evidence retains the reservation. Store actual provider status/expiration; do not invent TTLs. Setup has no name slot and its completed verified source does not change subscription eligibility. Delayed responses and webhooks converge through operation correlation and immutable IDs. Caller metadata is merged with a reserved operation marker; overriding it is rejected.

Receipts: `pending`, `processing`, `processed`, `ignored`, `quarantined`, `failed`. Verify signature with the SDK before persistence; bind trusted receiver context/version; durably store before 200. Foreign owner/version or conflicting identity is quarantined; unknown events/resources are ignored with reason, never adopted. Dispatch failure leaves pending work; scheduled replay finds it. Workers claim receipts atomically and recover expired processing leases. Signature verification occurs on receipt, not replay; replay trusts the already verified encrypted receipt. Purge bodies only for terminal processed/ignored/quarantined receipts after configured retention (default seven days); pending/processing/failed bodies remain recoverable until processed or explicitly quarantined by an operator. Retain identity/digest/outcome for deduplication. A purged receipt cannot be replayed from its body; canonical reconciliation remains available.

Domain events use a stable correlation ID, local resource ID, context and prior/current provider status, never credentials, profile data or URLs. `SubscriptionReconciled`, `CheckoutReconciled`, `InvoiceObserved` and `WebhookProcessed` are persisted in `airwallex_domain_events` in the state-change transaction, then dispatched after commit. A failed dispatch or crash between commit and dispatch leaves the outbox row undispatched; the scheduled event command retries it. A crash after listener delivery but before marking dispatch can redeliver the same stable ID. Delivery/listener handling is at least once; application listeners deduplicate stable IDs and reconcile if delivery was interrupted. No exactly-once financial/listener promise is made.

## Operational API

Proposed commands: `airwallex:reconcile --limit=100` (known customers/subscriptions/checkouts in the configured context), `airwallex:receipts --limit=100` (dispatch pending/retryable/expired-processing receipts), `airwallex:operations --limit=100` (inspect/reconcile known-ID ambiguity), `airwallex:prune-receipts` (bounded body retention), and `airwallex:events --limit=100` (dispatch durable pending domain events). Each uses bounded batches, context filtering and progress identifiers; repeating recovery never performs remote mutations. Run them through Laravel's scheduler with overlap prevention. Operator association of an unknown-ID operation must verify stored intent/customer/mode/metadata and cannot silently attach a foreign resource. Commands report safe IDs/outcomes, never body/profile/credential dumps.
