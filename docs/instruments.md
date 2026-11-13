# Saved instruments and browser setup

The application authorizes the billable, obtains its distinct Payments Customer through `Payments::for($billable)`, and owns web/mobile/admin endpoints and UI. Billing Customer/Source and existing Stripe tokens are separate identities. Use the account-owned `Payments\Instruments` service:

```php
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Instruments;

$customer = Payments::for($authorizedBillable)->customer();
$instruments = app(Instruments::class);
$setup = $instruments->createSetup($customer, $persistedSetupCommandKey, [
    'next_triggered_by' => 'merchant',
    'merchant_trigger_reason' => 'unscheduled',
]);
return response()->json($setup->expose()); // Explicit authorized browser disclosure.
```

The selected client contract uses Airwallex Elements/native mobile Consent setup. Clients collect card/device data with Airwallex, handle required actions, and call an authorized server completion endpoint. Normal JSON/debug exposes safe identity/status/bootstrap availability. Only `expose()` releases the create response's secret/next action; keep that private response out of response-body logs, caches and queues. The package persists neither secrets nor raw card/CVC/device data.

The [Consent API](https://www.airwallex.com/docs/api/payments/payment_consents) describes the secret as create-only and valid for 60 minutes. Stable-key recovery reads the original Consent and returns a setup without a secret; `expose()` fails when no secret/next action is available. Lost create responses require a separately authorized new setup/key, never automatic recreation. [Accepted decision #74](https://github.com/jojeringnax/airwallex-laravel/issues/74) retains this transient-secret recovery policy. Hosted/Elements/mobile/wallet readiness requires separate client/account validation.

```php
$instrument = $instruments->complete($customer, $submittedConsentId); // Known setup, canonical reads.
$instruments->setDefault($customer, $instrument);
$default = $instruments->default($customer); // Fresh canonical verification.
$usable = $instruments->verified($customer, $submittedConsentId); // Merchant/unscheduled saved card.
```

Callbacks supply locators, never trusted success/status/customer/method claims. Completion verifies Consent/Method identity, customer, selected account client and any returned account/environment proof. Both require canonical timezone-bearing update versions; stale versions and equal-version eligibility contradictions fail closed. Method proof is retained independently across all Consents referencing it, so a rejected stale Consent cannot erase a freshly observed Method disable. Saved-card use requires Consent `VERIFIED`, Method `CREATED`, card linkage and no local revocation. `verified()` also requires `merchant`/`unscheduled`. Missing, failed, disabled, expired/unknown states cannot grant eligibility. Verification does not establish payment completion/settlement; no inferred local expiry clock replaces provider status.

`all($customer)` explicitly adopts existing same-customer consents after collecting all filtered pages and canonically reading each Consent/Method. Duplicate IDs, foreign owners and incomplete pages fail the call. Browser completion alone cannot adopt an unknown setup. Non-card observations remain inspectable without invented saved-card eligibility.

`verify($customer, $key, $consentId, $methodId, $options)` accepts an owned tokenized card ID and optional `return_url`/`descriptor`, returning `BrowserSetup` with canonical status and an explicit response next action when present. Browser SDKs own card/device collection. `continueVerification($customer, $key, $consentId, $options)` accepts selected SDK `type`/`micro_deposit` continuation fields. `update($customer, $key, $consentId, $metadata)` retains reserved setup correlation metadata. `disable($customer, $key, $consentId)` revokes locally before attempting provider disable.

All mutations use durable original action/request IDs. Creation recovery proves original options/customer/correlation metadata; other uncertain actions require target and matching canonical request identity/effect. Insufficient evidence retains ambiguity and the mutex. `recover($operation, $operatorKnownConsentId)` reads only, rejecting never-attempted/failed actions. No request-ID lookup, safe replay interval or absence proof is assumed.

Local defaults are preferences: `setDefault($customer, null)` clears one. No provider default/detach/delete endpoint is fabricated. Disable clears the default and preserves local revocation through timeout and late reads; history remains. All Consent mutations share the Customer's durable mutex with Payments financial commands. Unresolved actions block competing same-customer mutations, a conservative throughput tradeoff for attribution. Charge reservations recheck local usability under the Customer lock; already claimed charges finish/recover their original workflow. Changing defaults or disabling consent does not cancel/refund pending charges or change active Billing subscription Sources.

```php
$source = $instruments->createBillingSource($customer, $consentId, $authorizedBillingCustomer, $persistedSourceCommandKey);
$subscription->updatePaymentSource($replacementCommandKey, $source->id);
```

`createBillingSource()` uses the Billing source-create API with the verified merchant/unscheduled card Method, explicit linked account and one durable Billing operation. The two Customers must represent the same billable/environment and the configured Billing collector must equal the Payments account. Source identity, Method linkage and original correlation are confirmed through canonical reads. Repeating the action key reads the original source; an unknown-ID timeout requires a canonically correlated source locator through `airwallex:operations --reconcile --id=LOCAL_OPERATION --remote-id=SOURCE_ID`. Neither creation nor recovery replays a write. Browser verification, including any required 3DS action, must finish before creation. A Source does not establish that a later collection succeeds or avoids customer action; invoice/subscription payment recovery remains separate.

The installed [instrument recipe](../examples/payments-instruments.php) accepts optional authorized Billing Customer and source-command arguments to exercise this bridge. It retains the standalone Payments path when they are omitted.

`billingSource($paymentsCustomer, $consentId, $authorizedBillingCustomer, $sourceId)` is an explicit read-only proof. Both mappings must represent the same billable/environment; canonical Source customer/collector and `external_id` must match the verified Payments account/card Method. Equal opaque Method IDs alone never bridge different collector accounts. It performs no source swap. The [Source API](https://www.airwallex.com/docs/api/billing/payment_sources) uses Method linkage and requires merchant/unscheduled authorization. Separate Billing source replacement remains an explicit action that may retry an invoice.

Downstream work owns agreement text, callback authorization, wallet/domain setup, deployed client builds and instrument migration/recollection. Existing Stripe tokens cannot be copied into Airwallex; use an approved provider migration route or recollect. No consumer repository migration or account observation is claimed.

## Optional subscription servicing after revocation

Local revocation and canonical Consent/Method disable observations produce durable `PaymentObserved` events for the Instrument. The payload includes `revoked_at` when locally revoked. Default selection is cleared; existing Billing Sources and subscriptions remain unchanged. The selected provider API establishes no source-delete/revoke endpoint or automatic cascade from Consent disable to Billing subscriptions. A replacement card creates a new Source; moving a subscription uses its explicit `updatePaymentSource()` command and may retry a failed invoice.

A consumer can register an ordinary event listener or configure `airwallex.payments.events.instrument_revoked_handler` with a container-resolvable class implementing `Contracts\InstrumentRevocationHandler`. Its `handle(PaymentObserved $event): void` selects the consumer's authorized subscription policy: for example notify the owner, use an explicitly approved replacement, or deliberately cancel with chosen proration controls. The default is `null`, so the package performs no automatic subscription change. Configuration uses a class name and remains config-cache compatible. Delivery can repeat; deduplicate redelivery by event ID and preserve original command keys on recovery. Local revocation and later remote disable can produce distinct observations for the same instrument. Reconcile the consumer’s persisted desired subscription state across them and reuse the stable key for that logical decision; event-ID deduplication alone does not collapse separate observations. A handler failure leaves the observation recoverable through the existing event outbox.
