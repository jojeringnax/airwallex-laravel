# Account-owned Payments

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

The released SDK **v0.1.1** (`bd11abb67e2e5761b86a9ca9d4a1b7993900de6e`) owns HTTP, API request validation, signatures and exact number encoding. Official [Customers](https://www.airwallex.com/docs/api/payments/customers), [Consents](https://www.airwallex.com/docs/api/payments/payment_consents), [Methods](https://www.airwallex.com/docs/api/payments/payment_methods), [Intents](https://www.airwallex.com/docs/api/payments/payment_intents), [Attempts](https://www.airwallex.com/docs/api/payments/payment_attempts) and [Refunds](https://www.airwallex.com/docs/api/payments/refunds) page metadata was rechecked on 2026-11-11 with `currentVersion=2026-08-21`. Customer create/update require `request_id`; responses expose merchant/customer and request identities. The [API-key guide](https://www.airwallex.com/docs/developer-tools/api/manage-api-keys) distinguishes account Payments from organization Billing. Synthetic tests establish package behavior, never activation, instrument availability, actual charges or provider delivery. Separately scoped [Payments sandbox qualification](https://github.com/jojeringnax/airwallex-laravel/issues/64) remains required.
