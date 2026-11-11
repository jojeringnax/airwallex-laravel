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

`billingSource($paymentsCustomer, $consentId, $authorizedBillingCustomer, $sourceId)` is an explicit read-only proof. Both mappings must represent the same billable/environment; canonical Source customer/collector and `external_id` must match the verified Payments account/card Method. Equal opaque Method IDs alone never bridge different collector accounts. It performs no source swap. The [Source API](https://www.airwallex.com/docs/api/billing/payment_sources) uses Method linkage although the product guide describes a verified Consent. Separate Billing source replacement remains an explicit action that may retry an invoice.

Downstream work owns agreement text, callback authorization, wallet/domain setup, deployed client builds and instrument migration/recollection. Existing Stripe tokens cannot be copied into Airwallex; use an approved provider migration route or recollect. No consumer repository migration or account observation is claimed.
