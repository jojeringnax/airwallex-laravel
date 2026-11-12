# Subscription Sandbox journeys

Use this guide for an explicitly authorized observation against dedicated Sandbox resources. The first-release optional journey and the required full-provider adoption gate below have separate completion criteria. The [release evidence policy](compatibility.md#release-evidence) permits completion of [#32](https://github.com/jojeringnax/airwallex-laravel/issues/32) through deterministic guard checks and an exact unexecuted record. Sandbox observations supplement the [provider contracts](provider-contracts.md); they do not replace package qualification or establish production readiness.

## Current evidence

As of 2026-11-10, this Laravel journey is **unexecuted**. The development session has no explicit Sandbox financial-validation opt-in, and no external credentials or ownership inputs were supplied for this run. No credential file was read, provider API request sent, browser payment completed or resource created. There is consequently no new cleanup obligation from this run. Deterministic tests use synthetic inputs and a poisoned SDK autoloader with network functions disabled.

| Account-dependent scenario | Recorded result / remaining evidence |
| --- | --- |
| Organization credentials, Billing activation, entity/collector/currency and price eligibility | Unexecuted; independently authorized account configuration remains unverified |
| Customer create, existing-customer link and owned subscription import | Unexecuted; dedicated billable and exact owned remote identities required |
| HOSTED setup and owned Source confirmation | Unexecuted; browser completion, resulting Source/customer/collector proof required |
| Direct and HOSTED subscription creation, trial/future start and multi-item behavior | Unexecuted; canonical status/dates/items and account-compatible prices required |
| Exact quantity, add/remove/swap and explicit financial controls | Unexecuted; resulting complete item observations and actual invoice effects required |
| UNPAID, separately authorized source replacement and provider recovery | Unexecuted; supported failure simulation and observed recovery/dunning behavior required |
| Invoice/line reads and PDF/hosted-link availability | Unexecuted; actual owned invoices and returned link/null values required |
| Selected-version signed deliveries and queue/reconciliation convergence | Unexecuted; configured endpoint, signature, delivered version/owner and receipt evidence required |
| Scheduled ending, immediate cancellation, natural timing and cleanup | Unexecuted; positive canonical termination and an owned-resource cleanup journal required |

The [SDK's tagged Sandbox record](https://github.com/jojeringnax/airwallex-php-sdk/blob/v0.1.1/docs/sandbox-validation.md) contains separate scoped observations; those are not a pass for this Laravel journey. API and webhook contract baseline is **2026-08-21**, selected SDK **v0.1.1**, source **bd11abb67e2e5761b86a9ca9d4a1b7993900de6e**. Record the exact installed candidate and SDK resolution before any later observation.

## Standalone preflight

From the reviewed source checkout, run `php tools/sandbox-preflight.php`. The qualification tool is excluded from runtime archives. It reads only specifically named process environment variables, loads no `.env` file, application or SDK, and makes no request even when every check passes. Exit 2 means a prerequisite is missing or malformed; exit 0 means the supplied configuration shape is ready for manual steps. Output contains fixed classifications, booleans and a plan, with no credential or resource values. Passing does not prove authorization, provider ownership, eligibility, installed versions or cached Laravel configuration.

An authorized operator supplies these values through a private process environment or secret manager, outside version control and shell history. This table names inputs; it does not authorize supplying real values in the current development session.

| Input | Required shape or explicit attestation |
| --- | --- |
| `AIRWALLEX_SANDBOX_OPT_IN` | Exact `I_AUTHORIZE_SANDBOX_VALIDATION`, after obtaining scoped Sandbox approval |
| `AIRWALLEX_ENVIRONMENT` | Exact `sandbox` |
| `AIRWALLEX_CLIENT_ID`, `AIRWALLEX_API_KEY` | Nonempty external organization-authorized Sandbox credentials |
| `AIRWALLEX_ORGANIZATION_ID` | Explicit owning Billing organization identifier |
| `AIRWALLEX_LEGAL_ENTITY_ID`, `AIRWALLEX_LINKED_PAYMENT_ACCOUNT_ID` | Explicit authorized legal entity and eligible linked collector identifiers |
| `AIRWALLEX_SANDBOX_PRICE_ID`, `AIRWALLEX_CURRENCY` | Authorized primary price identifier and uppercase three-letter currency; actual compatibility requires provider verification |
| `AIRWALLEX_SANDBOX_BILLABLE_TYPE`, `AIRWALLEX_SANDBOX_BILLABLE_ID` | Dedicated persisted local billable's exact morph class/alias and key |
| `AIRWALLEX_SANDBOX_OWNERSHIP` | Exact `DEDICATED_AUTHORIZED_RESOURCES`, attesting organization/entity/collector/price and local billable authorization |
| `AIRWALLEX_SANDBOX_CLEANUP` | Exact `CONFIRMED_OWN_SUBSCRIPTIONS_ONLY`, acknowledging the cleanup boundary below |
| `AIRWALLEX_SANDBOX_RUN_ID` | Unique private run identifier for the command-key and resource journal |

Identifier checks reject empty values and ASCII whitespace/control characters; they establish input shape only. The tool accepts no credential-file path, URL, production override or automatic-execution option. Run `vendor/bin/phpunit tests/SandboxPreflightTest.php` for the credential-free guards, including absent inputs, production selection, redaction and absence of SDK/bootstrap/environment-file loading.

## Authorized application preparation

Use an isolated consumer database with the qualified SQLite settings and stable encryption key from [usage](usage.md#install-and-configure). Load the approved candidate normally through Composer and use the package's scoped SDK; authentication, request validation and HTTP remain SDK-owned. The official [Sandbox overview](https://www.airwallex.com/docs/developer-tools/sandbox-environment), [Billing settings](https://www.airwallex.com/docs/billing/configure-your-billing-settings) and [integration checklist](https://www.airwallex.com/docs/billing/test-and-go-live/integration-checklist) identify account setup work. A successful login or collector ID does not verify all Billing permissions or collection eligibility.

Authorize and explicitly load the dedicated application model; never instantiate a model class or adopt a customer from untrusted incoming input. Before invoking any recipe, verify the application's **actual cached configuration** still has `airwallex.environment=sandbox`, the exact authorized organization/entity/collector/currency, and the intended collection method. Use AUTO_CHARGE for the automatic-collection/recovery path. Compare the persisted model's `getMorphClass()` and string key with the authorized dedicated billable. Stop before resolving the SDK or sending a request if any comparison fails. Shell preflight values do not override a stale application config cache.

Keep the actor's authorization, the run opt-in and the private expected mappings available for each manual step. Establish additional compatible owned prices before swap/multi-item cases, and record those cases as blocked if such prices are unavailable. Configure the selected-version receiver, endpoint secret, asynchronous workers and scheduler using [operations](operations.md#receiver-deployment); lack of signed deliveries remains a separate observation gap. The preflight does not inspect those services or claim they are operational.

Create a private run directory outside every Git checkout, with directory permissions 0700 and journal permissions 0600. Record only the following explicit fields, rather than complete models, responses or receipt bodies:

- Exact package commit/tree and archive SHA256; Composer lock hash; installed SDK version/source reference; API version; configured and actually delivered webhook versions.
- Trusted Sandbox organization/entity/collector/currency and dedicated local billable identity; private aliases for each case.
- Recipe, stable application action key, local operation/request IDs, exact known remote Customer/Checkout/Source/Subscription/item/Invoice IDs and their verified owner relationships.
- UTC observation time, actual canonical status and relevant exact dates/quantities, receipt IDs, safe outcome (`pass`, `fail`, `pending`, `blocked` or `unexecuted`) and remaining proof.
- Cleanup intent, known attempted outcome, confirmed termination, and retained-resource or unresolved-operation obligations.

Keep real IDs inside that private ownership journal; use aliases/counts and classifications in shareable evidence. Exclude credentials, tokens, profiles, signing secrets, raw bodies, checkout URLs/client secrets, invoice URLs and payment instrument data. Preserve an earlier failure or pending result when a later observation succeeds. A 403 alone does not identify the missing entitlement.

## Manual recipe sequence

Load each shipped closure from the installed package's `examples/` directory in an authorized server-side application action. For example, `$recipes = base_path('vendor/jojeringnax/airwallex-laravel/examples');` and `$create = require $recipes.'/create-customer.php';`. Prepare and journal a distinct action key before each distinct financial action; retry/recovery retains the original key and intent. These are manual calls, not a batch runner.

| Recipe | Manual use / evidence to retain |
| --- | --- |
| [create-customer.php](../examples/create-customer.php) | Invoke with the dedicated billable, persisted action key and explicit synthetic profile; retain the verified Customer mapping |
| [customer.php](../examples/customer.php) | Alternative read-only link to an exact authorized existing Customer; email/profile similarity is not ownership |
| [import-subscription.php](../examples/import-subscription.php) | Import an exact same-customer owned Subscription with an explicit local name; an already-mapped identity keeps its original name |
| [setup.php](../examples/setup.php) | Create HOSTED SETUP with controlled return URLs; complete it manually, then reconcile its exact local Checkout and verify the resulting Source |
| [subscription.php](../examples/subscription.php) | Create direct `default` subscription, quantity 2, with the verified owned Source; retain actual canonical status/dates/items |
| [checkout.php](../examples/checkout.php) | Create HOSTED SUBSCRIPTION named `default`, quantity 3; manually complete and canonically reconcile the owned result |
| [change-items.php](../examples/change-items.php) | Pass exact owned item IDs/native integer quantities and explicit SDK financial controls; inspect the complete resulting item set and invoices |
| [replace-source.php](../examples/replace-source.php) | Separately authorize the owned replacement Source; this may immediately retry the last failed invoice |
| [invoices.php](../examples/invoices.php) | Read an exact owned Invoice's observed PDF/hosted links or null values; keep URLs private |
| [schedule-ending.php](../examples/schedule-ending.php) | Record the canonical scheduled flag and positively confirmed effective end; local time alone proves no termination |
| [cancel-subscription.php](../examples/cancel-subscription.php) | Explicit immediate cancellation of the exact owned generation with chosen proration; confirm canonical CANCELLED |
| [recover-operation.php](../examples/recover-operation.php) | Read-only recovery of the original local operation; a candidate ID is only a locator subject to canonical ownership/correlation proof |

1. **Customer and Source.** Choose create or link for the main billable. To observe both paths, use another separately authorized dedicated billable and a different owned Customer, then repeat the preflight and journal its scope. A confirmed mapping cannot be reassigned. Import an existing Subscription only when that alternative case has a verified same-customer remote identity. Run hosted setup and complete the owned session using the provider's [documented Sandbox test inputs](https://www.airwallex.com/docs/payments/test-and-go-live/test-card-numbers). A browser return is navigation evidence; `Checkout::reconcile()` must confirm the expected mode/customer/collector and Source. Setup grants no subscription access.
2. **Direct subscription and changes.** Run the direct recipe only after Source verification. Reconcile the Subscription, including all items. Exercise an explicit item quantity through `change-items.php`; use the documented `updateQuantity`, `addItem`, `removeItem` and delete-plus-add `swapPrice` conveniences for their separate authorized cases. Supply explicit proration/billing controls from [usage](usage.md#sources-items-and-cancellation); amounts remain decimal strings. `acknowledged` is a recorded response with unproven item effect, not an active fee, settlement or eventual-application promise. A timeout remains ambiguous and is never permission to repeat a write with a new key.
3. **Invoices, failure and recovery.** Select Invoice IDs only from the owned customer/subscription's canonical reads. Record status, exact quantities/money and link/null availability without inferring link renewal or settlement. Use only a separately authorized, method-specific Sandbox failure simulation to observe UNPAID. If unavailable, leave that case blocked. Observe configured [provider recovery](https://www.airwallex.com/docs/billing/subscriptions/dunning), collect a new owned Source through setup, and explicitly invoke the replacement recipe. Neither Source success nor elapsed time restores access; record a later canonical Subscription status and corresponding eligibility separately. Verify signed receipt context/version and record absent notifications as missing evidence.
4. **Ending and the hosted generation.** Observe the scheduled-ending recipe and confirmed effective bound, then immediately cancel the direct generation with an explicit proration choice when that cleanup is authorized. The shipped direct and hosted recipes both use `default`: complete canonical termination before invoking the hosted recipe on the same billable, or use a separately authorized dedicated billable for a concurrent case. Keep history and exact generation IDs. Manually complete/reconcile the hosted session, then inspect its resulting Subscription/Source/Invoice relationships and signed deliveries. Trial/future-start cases use the supported builder fields in [usage](usage.md#named-subscriptions-and-checkout); do not alter provider timestamps or claim natural timing from a frozen local clock. Leave natural timing pending until actually observed.
5. **Uncertainty and cleanup.** Stop dependent steps when required proof is missing. Use the recovery closure or bounded [operational commands](operations.md#uncertain-actions), retaining original intent and IDs. Unknown financial outcomes remain held even if a target read looks matching. Finish each case with the cleanup record below; do not convert a partial run into a passing journey.

## Confirmed owned cleanup

Before each cleanup mutation, recheck the opt-in, actual Sandbox context and actor authorization. Resolve a Subscription by its journalled immutable local **and remote** IDs under the journalled Customer/context, rather than selecting whichever generation currently owns a name. Use the cancellation recipe with its persisted cleanup action key and explicit proration. Reconcile that same generation and record cleanup complete only after canonical `status === 'CANCELLED'`. Scheduled ending, a local access cutoff, an expired date, a timeout or a 404 does not prove cancellation.

For an unfinished owned Checkout, use its explicit `cancel(actionKey)` only after re-verifying its journalled ID/customer/operation, then reconcile the canonical outcome and any resulting Subscription. Never assume elapsed checkout time proves expiry or absence of a resulting resource. A foreign or unjournalled resource is outside cleanup scope. No production mutation, account sweep, automatic deletion, source detach or unsupported customer/catalogue delete is part of this journey.

If an ambiguous hold or unavailable provider prevents confirmed cancellation, preserve the private resource/operation IDs and mark cleanup **pending**, with the next read-only/provider investigation needed. Do not clear the hold or replay the mutation. Customers, Sources, Invoices, catalogue data and local history without a supported cleanup action remain explicitly retained; deleting a local billable never cancels remote billing. Share only the sanitized outcome and pending obligations, retaining the ownership journal privately for subsequent authorized resolution.

## Adoption gate #63

The full-provider programme has a separate required [subscription Sandbox gate #63](https://github.com/jojeringnax/airwallex-laravel/issues/63). The baseline optional policy above does not turn this adoption gate into a pass. It remains **unexecuted**: no scoped financial-validation approval, external credentials, owned account inputs or cleanup controls were supplied to this development programme. Deterministic installed-consumer fixtures are separate evidence. [Payments qualification](https://github.com/jojeringnax/airwallex-laravel/issues/64) has an independent owning account and journey.

Run `php tools/subscription-sandbox-plan.php --package-commit=FULL_PACKAGE_COMMIT --sdk-commit=FULL_SDK_COMMIT --lock-sha256=FULL_LOCK_SHA256` from the exact reviewed checkout to print a secret-free manual journey plan. The tool does not load Laravel, the SDK, environment files or credentials, and makes no provider request. Missing/malformed provenance leaves the plan blocked. A valid plan still reports provider observations unexecuted and cached configuration/ownership unverified. Then use the preflight and manual application preparation above under separate scoped authorization.

| Adoption step | Executable public interface and required observation |
| --- | --- |
| Owner and migration boundary | Authorize the original persisted billable; use `AirwallexBillable` beside its existing Cashier trait. Verify exact local/remote customer mappings. Retain old-provider renewals/refunds/history on their original provider. |
| Initial free period / future start | Create using `startsAt` and `trialDays` or exact trial end according to the application policy; observe PENDING/IN_TRIAL/natural ACTIVE timing and first invoice effects. A local clock or commercial access override does not suppress collection. |
| Direct/hosted/setup and source | Execute the installed baseline recipes above with controlled return URLs; canonically verify each resulting customer/source/subscription. Record actually delivered selected-version signatures and worker convergence. |
| Immediate / deferred tier changes | Use explicit item/proration/billing controls. Run the installed `schedule-transition.php` with a persisted future date and lateness window; record the original generation/items, real worker time, exact outcomes/invoices and any blocked/ambiguous plan. Local due execution does not promise an atomic provider renewal boundary. |
| Auto-renew off/on | Use `cancelAtPeriodEnd` then installed `restore-renewal.php` for the [supported combinations](contracts.md#adoption-amounts-and-provider-routing). Supply explicit original indefinite intent before scheduling; verify source ownership, state and canonical false plus the intended bounds before clearing local caps. Retain actual Invoice and renewal observations separately; SDK observations do not qualify this Laravel journey. |
| Invoice financial evidence | Use the installed owned invoice-transaction recipe and independently verified application allocation/clearing rules. Keep pending/failed/refund/manual/credit/zero-cash distinctions. Invoice PAID or an external reference alone never creates cash. |
| Failure/source recovery and dunning | Separately authorize the failure simulation/source change and its possible immediate invoice retry. Observe canonical subscription/invoice/transaction state and actual account retry/final-action settings; unsupported timing stays pending. |
| Ending / retained obligations | Reconcile natural ending if required, then perform only journalled owned cleanup using original action identities. Preserve ambiguous commands, failed/pending results, retained customer/source/invoice/history and any cleanup still outstanding. |

Before the first call, bind the exact package archive, selected SDK archive/tag, dependency lock, API version, actually configured/delivered webhook version and selected backend to the private journal. Include worker/scheduler queue connections, encryption-key continuity and known-original-bound availability. A synthetic test profile does not qualify an integrating application's deployment. A later source/dependency change requires the affected cases to be repeated and the final cumulative subscription/Payments candidate to be bound to one graph. The open gate stays incomplete until the required observations exist.
