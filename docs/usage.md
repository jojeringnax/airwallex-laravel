# Laravel billing recipes

For separately configured standalone merchant Payments, see [account-owned Payments](payments.md).

Consent setup, explicit browser disclosure and saved-card defaults use the separate [instruments workflow](instruments.md); they do not automatically change Billing Sources.

Start here when installing the package or writing application billing code. [Scope](scope.md) owns eligibility and deferrals; [contracts](contracts.md) own signatures and stored identities; [operations](operations.md) owns webhooks, queues, recovery and offboarding. Accepted policies are recorded in [#10](https://github.com/jojeringnax/airwallex-laravel/issues/10), [#34](https://github.com/jojeringnax/airwallex-laravel/issues/34), [#39](https://github.com/jojeringnax/airwallex-laravel/issues/39) and [#40](https://github.com/jojeringnax/airwallex-laravel/issues/40) and remain subject to the documented compatibility policy.

## Install and configure

Use the exact review candidate archive and Composer descriptor from the [release-candidate tooling and installation instructions](release.md); the Laravel package is not yet claimed as published to Packagist. Its dependency is the released Packagist SDK `jojeringnax/airwallex-php-sdk:^0.1.1`, with no sibling/path repository. Once a Laravel package release is actually published, the normal registry installation is `composer require jojeringnax/airwallex-laravel:^0.1`. See [compatibility](compatibility.md) for the PHP, Laravel 12/13, SQLite/MySQL, cache and queue support boundary.

Laravel discovers the provider automatically. Publish configuration, configure external credentials, and migrate:

```sh
php artisan vendor:publish --tag=airwallex-config
php artisan migrate
php artisan config:cache
```

```dotenv
AIRWALLEX_ENVIRONMENT=sandbox
AIRWALLEX_ORGANIZATION_ID=your-billing-organization
AIRWALLEX_CLIENT_ID=your-organization-client
AIRWALLEX_API_KEY=your-private-key
AIRWALLEX_LEGAL_ENTITY_ID=your-eligible-entity
AIRWALLEX_LINKED_PAYMENT_ACCOUNT_ID=your-linked-collector
AIRWALLEX_CURRENCY=USD
AIRWALLEX_COLLECTION_METHOD=AUTO_CHARGE
```

Use organization-authorized credentials and explicit eligible entity/collector settings. Collector identity is not Billing organization ownership. High-level writes use the configured pair and reject different overrides or contradictory returned values. Subscription routing fields and Checkout legal entity may be omitted; Checkout collector remains required. Optional omissions alone do not block confirmed workflows, and raw snapshots retain those omissions. See the [per-resource routing contract](contracts.md#consumer-api). Account activation, permission, price-frequency, currency/method and Source eligibility still require provider/account verification. The raw SDK remains available for its wider supported operations.

The default connection must use SQLite >=3.43.2 or MySQL >=8.4.11 <8.5. Install `pdo_sqlite` or `pdo_mysql` for the selected engine; MySQL consumers do not require SQLite. Enable foreign keys. MySQL also requires strict SQL mode, 16KiB InnoDB pages and DYNAMIC default row format. Package tables set InnoDB and exact `utf8mb4_0900_bin` collation independently of the application’s default collation. See [database requirements](compatibility.md). Package migrations create separate `airwallex_*` tables and do not alter Users/Teams. Migrations load automatically; publish `--tag=airwallex-migrations` only when deliberately owning coordinated schema changes. Keep application and package migration names/paths consistent; use a database backup and review FK/identity constraints before changing tables. A stable Laravel `APP_KEY` protects operation intents, checkout snapshots/URLs and receipt bodies; key rotation must retain decryption capability for retained records. Default model output hides these fields, but direct database access remains sensitive.

Configure and supervise the receiver/queues/scheduler using [operations](operations.md) before relying on notifications. API and webhook versions are separately pinned to `2026-08-21`; the webhook subscription must explicitly select it. A package install, config cache or successful API schema check does not establish account eligibility or financial settlement. The [optional Sandbox journey](sandbox.md) records guarded manual steps and exact unexecuted provider evidence.

## Billables and local authorization

Add the trait to a persisted Eloquent User, Team, Company or another application model:

```php
use Jojeringnax\AirwallexLaravel\Billable;

class Team extends \Illuminate\Database\Eloquent\Model
{
    use Billable;

    public function airwallexProfile(): array
    {
        return ['name' => $this->name, 'email' => $this->billing_email];
    }
}
```

The same trait works on a User extending Laravel's Authenticatable or a Company extending Model. The package uses the model's actual persisted integer/UUID/ULID key and morph class. Keep existing `HasUuids`/`HasUlids` key behavior; local package IDs are separate ULIDs. An application morph map remains effective. Changing an established morph alias or billable key requires an explicit mapping-data migration, not remote relinking.

Authenticate the actor and authorize the local billable in your application's policy before every read/write or link exposure. A route's authenticated user need not be the billable Team/Company. Subscription eligibility is a separate check and must not substitute for ownership authorization. Saving the billable never synchronizes a profile remotely.

```php
$customer = $team->createOrGetAirwallexCustomer($persistedCommandKey, ['name' => 'Team']);
$customer = $team->linkAirwallexCustomer($explicitRemoteCustomerId);
$customer = $team->syncAirwallexCustomer($anotherCommandKey, ['email' => null]);
```

Creating first reserves the local mapping and records the write identity. Getting an already confirmed mapping is local-only and does not update its supplied profile. Use explicit sync to update it. Linking verifies the exact remote ID and cannot replace a confirmed mapping or adopt by email. One remote Customer/context maps to one local billable/context. Omission, null and empty values stay distinct where the SDK contract distinguishes them. [Create](../examples/create-customer.php), [link](../examples/customer.php) and [import](../examples/import-subscription.php) recipes execute against the real SDK with fake transport in deterministic tests.

## Named subscriptions and checkout

Persist an application command key before invoking a financial action. Reuse that key and exact intent for retries; each distinct authorized action gets a different key. A changed intent under the same key conflicts. Never replace an uncertain command with a new key merely to retry it; use [read recovery](operations.md#uncertain-actions).

```php
$subscription = $team->newSubscription('default', $priceId)
    ->quantity(2)->addPrice($supportPriceId, 1)
    ->trialDays(7)->startsAt('2026-12-01T00:00:00Z')
    ->create($commandKey, ['payment_source_id' => $ownedSourceId]);

$checkout = $team->newSubscription('support', $supportPriceId)
    ->quantity(3)->checkout($checkoutCommandKey, [
        'success_url' => 'https://app.example.test/billing/return',
        'back_url' => 'https://app.example.test/billing',
    ]);
return $checkout->redirect();
```

Direct AUTO_CHARGE requires an owned Source under the configured collector. Other collection methods must satisfy SDK/account conditions, including applicable due-date settings. Builder trials/future starts use provider fields; no generic local trial is created. Trial dates resolve once at intent preparation, anchored to explicit start or preparation time. Delayed hosted completion does not guarantee the same trial duration after completion. Creation can return PENDING, IN_TRIAL, ACTIVE, UNPAID or an unfamiliar state; access follows the actual confirmed projection.

Hosted checkout uses HOSTED mode only. Its returned HTTPS redirect is explicitly exposed; the protected URL/client secret is absent from normal model diagnostics. No guaranteed TTL is calculated. On an authorized server-side callback, load your own local Checkout and call `reconcile()`. Browser query parameters alone never create a subscription or confirm success. Verification reads the owned checkout and resulting Subscription/Source. The [direct](../examples/subscription.php) and [hosted](../examples/checkout.php) recipes demonstrate the actual signatures.

Each explicit name reserves one current generation, including unknown, UNPAID, scheduled ending and uncertain creation. Expired local access does not free a potentially billable name. Confirmed termination permits replacement and preserves history. `subscription(name)` returns current or latest history for inspection; `subscribed(name)` checks current only and never falls back to history. Import an explicitly owned remote subscription under a caller-chosen name with `$team->importSubscription('default', $remoteSubscriptionId)`; import performs reads only.

## Sources, items and cancellation

```php
$setup = $team->setupPaymentMethod($setupCommandKey, [
    'success_url' => 'https://app.example.test/billing/return',
    'back_url' => 'https://app.example.test/billing',
]);
$setup->reconcile();
$sources = $team->paymentSources(['page_size' => 100]);
$subscription->updatePaymentSource($sourceCommandKey, $chosenSourceId);

$subscription->updateQuantity($quantityCommandKey, 5, ['default_proration_mode' => 'NONE'], $itemId);
$subscription->swapPrice($swapCommandKey, $newPriceId, ['default_proration_mode' => 'PRORATED'], $itemId, 5);
$subscription->addItem($addCommandKey, $anotherPriceId, 1, ['default_proration_mode' => 'NONE']);
$subscription->removeItem($removeCommandKey, $itemId, ['default_proration_mode' => 'NONE']);
$subscription->cancelAtPeriodEnd($endingCommandKey);
$subscription->cancelNow($cancelCommandKey, 'NONE');
```

Setup verifies a Billing Source and grants no subscription access. Source replacement is a separate explicit financial action and may immediately retry the latest failed invoice. Successful setup/replacement does not itself restore UNPAID access; reconcile the Subscription. Billing Sources differ from Payments Customers, Methods and Consents. No default/delete/detach source behavior is invented. Use [setup](../examples/setup.php) and [source replacement](../examples/replace-source.php) recipes.

Item quantities supplied to writes must be native positive int32 integers. Observed quantities/money remain exact strings and can be fractional; never cast them through float. Single-item conveniences require exactly one item unless an explicit ID is supplied. Unsupported observed item models block unsafe item changes without automatically denying lifecycle access. A swap deletes the old ID and adds the new price; provide an explicit replacement quantity when the observed value cannot be a native integer. Omitted existing items stay unchanged.

Every item change needs explicit provider proration controls. `updateItems()` supports per-item `CUSTOM` values such as `['id' => $itemId, 'quantity' => 3, 'proration_mode' => 'CUSTOM', 'proration_value' => ['charge_amount' => '100.00', 'credit_amount' => '20.00']]`, with explicit `billing_action`/`proration_date` options where needed. The package does not calculate proration, fees or effective item history. A returned Subscription is the actual canonical projection; inspect operation status when requested effects are not observed. `acknowledged` is not proof of fulfillment or settlement and is never replayed. See the [item change](../examples/change-items.php) recipe and [acknowledgement runbook](operations.md#uncertain-actions).

[Scheduled ending](../examples/schedule-ending.php) retains access only within positively confirmed effective bounds. Call `$subscription->restoreRenewal($onCommandKey, $originalOffCommandKey)` before the confirmed scheduled end. It supports only saved-original finite ACTIVE OUT_OF_BAND schedules, with no payment source; old/imported schedules lacking the original bound and other collection/state combinations are unavailable. A false flag alone cannot clear the retained access cap: canonical exact-original-end proof is required. This request restores a finite lifetime and changes no items/source or proration controls; it promises no refund, charge, invoice void or settlement. [Contract evidence and limitations](contracts.md#adoption-amounts-and-provider-routing) remain explicit. [Immediate cancellation](../examples/cancel-subscription.php) requires an explicit proration behavior and denies default access after confirmed CANCELLED. Already-CANCELLED subscriptions cannot be reactivated.

## Deferred item transitions

`$subscription->scheduleTransition($actionKey, $effectiveAt, $items, $options, $expiresAt)` stores an exact future item update and an explicit latest execution time. Choose the target price/items and proration/billing policy in the application; for a next-cycle billing-only change, the existing immediate `swapPrice`/`updateItems` API with NONE may be appropriate instead. A due plan is a delayed local command, with no atomic provider-boundary guarantee. It preserves source/collection/auto-renew controls and blocks if the current generation or financial shape changed, ACTIVE is unconfirmed or its first execution misses the allowed window. `cancel()` or `replace(...)` works before execution; once attempted, ambiguity keeps the plan reserved and only canonical recovery can complete it.

Schedule the [due command](operations.md#deferred-transitions) and supervise its outcomes. A trial can be followed by a downgrade when its canonical ACTIVE state and expected financial shape are available; an ACTIVE earned/free billing holiday still requires the [unresolved provider strategy](https://github.com/jojeringnax/airwallex-php-sdk/issues/161). Local free access alone never suspends collection. Application entitlements and invoice/cash accounting remain separate.

## Local eligibility and customization

`$team->subscribed('default')`, `$subscription->valid()`, `active()`, `onTrial()` and `onGracePeriod()` perform no HTTP. Default eligibility denies PENDING/UNPAID/CANCELLED/unknown and follows confirmed start, trial, end and scheduled-end bounds. ACTIVE recurring period rollover is not an access-expiry clock. `onGracePeriod()` means scheduled ending, not payment-failure grace. Full rules are in [scope](scope.md#eligibility).

Bind your implementation of `Contracts\SubscriptionAccessPolicy` in the application container to change `valid()` and Billable `subscribed()`. Its method is `allows(Subscription $subscription): bool`. Lifecycle helpers `active()`, `onTrial()` and `onGracePeriod()` retain default lifecycle semantics. A custom policy makes an application access decision; it does not rewrite the provider status or authorize a financial action.

For custom local models, extend the corresponding package model and set its class in `airwallex.models` before resolving services. All eight model keys in the published configuration must use compatible subclasses. Queries/relations use those configured classes; table changes require coordinated migrations/FKs. Keep the immutable identity fields and casts intact. Profile customization belongs on the billable; token, transport and API validation customization belongs in the SDK's supported interfaces. Resolve the raw `Jojeringnax\Airwallex\Client` from Laravel or use the `Airwallex` facade; no package HTTP/auth implementation is added.

## Invoices and document links

```php
$invoice = $team->findInvoice($invoiceId);
$invoices = $team->invoices(['page_size' => 100]);
$subscriptionInvoices = $subscription->invoices();
$pdf = $invoice->pdfUrl();
$hosted = $invoice->hostedUrl();
$lines = $invoice->lineItems(['page_size' => 100]);
$line = $invoice->lineItem($lineItemId);
```

Authorize the local billable before retrieving or delivering document links, including after subscription termination. Customer ownership remains the boundary; subscription eligibility is not required for historical invoices. Direct-ID reads verify the mapped customer, supplied subscription scope and known related mappings. Lines reverify their parent invoice before reading. Lists return all pages or throw; no partial result is exposed. Exact decimal amounts remain strings. Invoice reads never update subscription access and create no local invoice ledger.

Absent/null document links return null, as is common for drafts; present links are returned as observed independently of invoice status. SDK provider errors retain their original classification, including 404; handle them without treating absence as permission to recreate an invoice. The SDK's observed `pdf_url`/`hosted_url` values carry no package renewal or expiry guarantee. There is no PDF rendering/downloading, invoice email or regenerated-link promise. Default JSON/debug output excludes URLs/customer profiles; explicit `toArray()` exposes a sanitized provider snapshot for authorized server-side use. Treat that explicit data and links as sensitive. The [invoice recipe](../examples/invoices.php) returns nullable links explicitly.

For timeout recovery, use [the operation recipe](../examples/recover-operation.php) in an authorized operator flow and the [commands/runbooks](operations.md). Applications should observe package events after committed changes and deduplicate their stable outbox IDs; events describe provider observations rather than automatic application entitlements.

## Cashier coexistence

Keep Cashier's `Billable` on the original model. Authorize it in the application and create a provider-qualified object; never import both Billable traits or alias only the public method names, because helper dispatch can still cross providers.

```php
$billing = new \Jojeringnax\AirwallexLaravel\AirwallexBillable($authorizedCustomer);
$customer = $billing->createOrGetAirwallexCustomer('customer-command', ['name' => 'Example']);
$subscription = $billing->newSubscription('membership', $airwallexPriceId)
    ->create('subscription-command', ['collection_method' => 'OUT_OF_BAND']);
$billing->subscription('membership'); // Airwallex current, otherwise history.
$billing->invoices();                // Owned Airwallex invoices.
$authorizedCustomer->subscription('membership'); // Existing Stripe contract.
```

The wrapper preserves the original morph identity, so use the same stable morph map and configured Airwallex model subclasses as the standalone trait. Identical subscription names can coexist across providers; Airwallex's own context/name reservation still prevents duplicate generations. Provider IDs, setup methods and remote source/consent identities never transfer merely because the local billable is shared. The application owns its explicit `billingProvider` routing, historical reads/refunds and access combination; do not choose a provider from an untrusted request parameter.

The real Cashier fixture is an optional development dependency, isolated from package runtime. Install `tests/Consumers/coexistence/composer.json` into a disposable directory and run:

```sh
AIRWALLEX_CASHIER_AUTOLOAD=/absolute/fixture/vendor/autoload.php \
  vendor/bin/phpunit --bootstrap tests/Consumers/coexistence/bootstrap.php tests/CashierCoexistenceTest.php
```

For installed-candidate qualification, also set `AIRWALLEX_PACKAGE_AUTOLOAD=/absolute/consumer/vendor/autoload.php` after installing the exact package archive and selected SDK revision in the isolated consumer. That path selects the installed package code; it does not install a sibling path repository. Preserve the consumer lock and exact archive/SDK references with the evidence. The three checks exercise real Cashier relations/builders/legacy invoice reads and cancellation, Airwallex current/history/import/create/setup/invoice/cancel/recovery, morph maps, configured subclasses and unsaved-model refusal. Both transports deny unexpected requests and use synthetic responses only. This fixture is shared by the subscription and Payments adoption qualification tracks; each must verify its own provider context and financial event boundary.

## Application amount and accounting adapters

The executable [application adapter factory](../examples/adoption-adapters.php) illustrates an injected, independently verified currency-exponent table and catalogue aliases, plus a database-unique financial listener. It adds no package ledger or commercial policy:

```php
$factory = require base_path('vendor/jojeringnax/airwallex-laravel/examples/adoption-adapters.php');
$adapters = $factory($verifiedCurrencyExponents, $verifiedCatalogueAliases, 'application_cash_entries');
$decimal = $adapters['major']('12345', 'USD'); // '123.45' for a verified exponent of 2.
$minor = $adapters['minor']('123.4500', 'USD'); // '12345', with no rounding.
$priceId = $adapters['price']('sandbox', 'membership', 'USD');
$result = $adapters['cash']($ownedTransaction->toArray(), $independentApplicationAllocation);
```

Both amount callbacks reject floats even from weak PHP callers, unknown exponents and excess nonzero precision. The illustrative currency table in qualification does not establish account/payment-method eligibility. Keep coupon eligibility/redemption and initial trial/free-period date calculation in application policy. An ACTIVE billing holiday or exact provider renewal boundary remains unavailable without the evidence in the scope/provider references.

The optional cash callback requires an application-owned table with unique/primary `financial_identity`, string `provider`, `environment`, `account_id`, `resource_id`, `invoice_id`, `currency`, `proof_reference`, `clearing_account`, and exact string/text `amount_minor`. Supply allocation evidence from trusted server-side reconciliation: owning environment/account, invoice and PaymentIntent IDs, currency/amount, independent proof reference, clearing account and an independently established exclusive-to-invoice allocation. A caller-supplied boolean is not that proof. Missing allocation, manual/refund/pending/failed observations and zero cash return `unallocated`; repeated canonical intent identity returns `duplicate`; contradictory existing allocation fails. Distinct webhook delivery IDs and Billing/Payments references must resolve to that same verified account/intent key. The application must adapt this example to its real atomic ledger, partial/cross-document allocations, credit notes, accounting configuration and retention policy.

The [owned invoice transaction recipe](../examples/invoice-transactions.php), [finite restoration recipe](../examples/restore-renewal.php) and [due transition recipe](../examples/schedule-transition.php) are qualified through the selected subscription track. The latter two do not establish indefinite/AUTO_CHARGE restoration, active recurring free periods or atomic scheduled provider execution. Actual downstream catalogue/client/accounting implementation and separately authorized Sandbox evidence remain required.
