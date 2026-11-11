# Account-owned Payments

The separate opt-in [Payments receiver and recovery](payments-webhooks.md) reconciles canonical resources and delivers durable safe observations for application accounting.

Standalone Payments uses one explicitly configured merchant login account, independently of the Billing organization. Set `airwallex.payments.environment`, `account_id`, `client_id`, `api_key` and optional `cache_store`. Configuration is lazy and cacheable. The SDK receives `loginAccountId` and partitions token reuse by environment, credentials and account. Connected-account delegation is outside this launch profile. A replacement `Payments\Client` binding must retain the same immutable context.

Authorize the persisted application model before creating this explicit entry point. It does not install an authentication guard or change Cashier methods:

```php
use Jojeringnax\AirwallexLaravel\Payments;

$payments = Payments::for($authorizedTeam);
$customer = $payments->createCustomer($persistedCommandKey, ['business_name' => 'Example']);
// Alternative: explicitly associate an existing customer after application authorization.
$customer = $payments->linkCustomer($operatorVerifiedPaymentsCustomerId);
$customer = $payments->syncCustomer($anotherPersistedCommandKey, ['business_name' => 'Updated']);
$payments->offboard(); // Local write restriction; retains mapping/history/recovery obligations.
```

Payments Customer, Billing Customer, Consent, Method and Billing Source IDs are different identities. Association never uses email. Separate `airwallex_payment_customers` and `airwallex_payment_operations` tables preserve immutable environment/account/owner, exact IDs, stable application action key, generated request ID and encrypted original intent. Unique local/remote identities and composite foreign keys prevent reassignment or cross-context operations. New additive migrations preserve existing Billing records. Back up the encryption key and database together; loss of the key prevents recovery of encrypted command intent.

Persist the application command key before calling the package. Reusing it with changed kind/target/intent conflicts. An uncertain mutation retains its original request ID and reservation; changing keys, credential rotation, local offboarding or a clock cannot authorize a replacement. A successful operation means its canonical resource/intent was confirmed; it is not a cash or settlement assertion. Customer profiles/snapshots and command payloads are hidden in ordinary model serialization and encrypted at rest; canonical snapshots omit client secrets. Retrieve client bootstrap only through an explicit authorized workflow, never by serializing a customer.

```php
// Investigation after a timeout may supply the original resource ID.
// Recovery only reads; original request/intent and merchant ownership must match.
$customer = $payments->recoverCustomer($safeLocalOperationId, $operatorKnownRemoteId);
```

Without a known ID, recovery stays ambiguous. No absence proof, request replay window, automatic retry of writes, remote customer deletion or token transfer is assumed. Existing confirmed mappings return locally for a fresh create key; use `syncCustomer` for explicit profile changes. Offboarding blocks new writes while retaining reads and recovery. Application retention must keep mappings referenced by financial history.

## Payment intents and exact amounts

```php
$intent = $payments->createIntentMinor($createCommand, $merchantOrderId, '12345', 'EUR');
// Or createIntent($createCommand, $merchantOrderId, '123.45', 'EUR').
$intent = $payments->confirmIntent($intent, $chargeCommand, $verifiedMerchantConsentId);
// Customer-present tokenized method, after owned browser collection:
$intent = $payments->confirmIntentWithMethod($intent, $confirmCommand, $ownedMethodId);
$clientPayload = $payments->intentBootstrap($intent)->expose(); // Deliberate authorized handoff.
$intent = $payments->continueIntent($intent); // Browser callback triggers GET only.
$intent = $payments->captureIntent($intent, $captureCommand, '40.00');
$intent = $payments->cancelIntent($intent, $cancelCommand);
$intent = $payments->recoverIntent($localOperationId, $optionalOperatorKnownIntentId);
```

These are alternative paths selected from canonical state, not a sequence to execute unconditionally. Merchant consent must be canonically verified for this customer/account and allow merchant-unscheduled charges. Customer-present card/Apple Pay/Google Pay methods use the same retained owner/version/status proof as saved instruments; an older CREATED response cannot overwrite a known Method disable. The common customer lock rechecks Method eligibility before reservation and the mutation claim. Local Consent revocation alone does not detach its Method or prohibit a new explicit customer-present action. Customer-present methods must be owned and usable; raw PAN/CVC/PIN data is outside these APIs. Airwallex.js/eligible mobile clients own tokenization, wallet availability and 3DS next actions. `continueIntent` consumes no callback result or server-side PIN/microdeposit payload; it only reads the known intent. The SDK's `confirmContinue` endpoint is not a generic 3DS return handler.

Persist every command key. Merchant order identity is unique across customers within environment/account, so changing customer or key cannot create another intent for the same order. Choose an application namespace for order IDs when several order domains share an account. Immutable intent amount/currency/customer/order and canonical request correlation protect each action. An unresolved operation retains its original customer mutex; instrument mutations and new financial commands serialize on that same customer. The actual mutation claim rechecks local offboarding/revocation under the customer lock. Already claimed work retains its original obligation when a later revoke arrives. Repeating the same original confirm command validates its stored customer/target/body and recovers read-only even after later instrument revocation; a fresh or never-attempted command still requires current eligibility.

All amount/minor-unit inputs are strings. `Payments\Amount` converts integer minor units and performs reservation arithmetic with decimal digits, preserving values above PHP's integer range. The bundled 137-currency exponent reference follows the official [currency precision table](https://www.airwallex.com/docs/payments/reference/supported-currencies), checked 2026-11-11; this is unit conversion, not FX or Payments-method eligibility. Unknown currencies/excess precision/floats fail without rounding. Zero totals are an application business completion and cause no artificial charge.

`Intent::paid()` requires owned canonical `SUCCEEDED` and a positive explicit `captured_amount`. It identifies captured funds, including a partial capture, and does not mark an application payable fully paid or prove settlement. Missing capture proof, authorization, action-required, review, pending, unknown states and frontend success grant no paid state. Compare captured value with the application payable and deduplicate the account/provider/financial identity. Canonical timestamps and local revision fences reject older or conflicting equal-version observations. Safe financial observations commit with the projection and use a stable delivery identity; the application also deduplicates the financial identity across deliveries and Billing external references.

Capture/cancellation request acceptance alone leaves an acknowledged operation and its mutex until a canonical capture advance or `CANCELLED` is positively observed. Recovery is read-only. A failed local precondition releases only a never-attempted prepared hold; no uncertain write is reissued. SDK errors remain classified without payload diagnostics. `intentBootstrap` deliberately exposes a fresh retrieved secret and next action, while ordinary JSON/debug output and persisted snapshots omit it. The documented [Intent client secret](https://www.airwallex.com/docs/api/payments/payment_intents) is returned by create/retrieve and valid for 60 minutes; no local expiry/renewal guarantee is inferred. Actual browser/wallet behavior and account-specific capture availability remain separate qualification obligations.

The released SDK **v0.1.1** (`bd11abb67e2e5761b86a9ca9d4a1b7993900de6e`) owns HTTP, API request validation, signatures and exact number encoding. Official [Customers](https://www.airwallex.com/docs/api/payments/customers), [Consents](https://www.airwallex.com/docs/api/payments/payment_consents), [Methods](https://www.airwallex.com/docs/api/payments/payment_methods), [Intents](https://www.airwallex.com/docs/api/payments/payment_intents), [Attempts](https://www.airwallex.com/docs/api/payments/payment_attempts) and [Refunds](https://www.airwallex.com/docs/api/payments/refunds) page metadata was rechecked on 2026-11-11 with `currentVersion=2026-08-21`. Customer create/update require `request_id`; responses expose merchant/customer and request identities. The [API-key guide](https://www.airwallex.com/docs/developer-tools/api/manage-api-keys) distinguishes account Payments from organization Billing. Synthetic tests establish package behavior, never activation, instrument availability, actual charges or provider delivery. Separately scoped [Payments sandbox qualification](https://github.com/jojeringnax/airwallex-laravel/issues/64) remains required.
