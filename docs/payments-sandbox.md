# Owned Payments sandbox journey

This is the preparation for [#64](https://github.com/jojeringnax/airwallex-laravel/issues/64). **Provider execution is unexecuted.** Package fixtures, SDK account observations from another release, Billing activation and the [subscription journey](sandbox.md) do not qualify these account-owned flows. Actual browser/client builds, merchant permissions, owned instruments and separately scoped authorization are required. The downstream runtime upgrade/profile is user-deferred under #49.

Start from passing exact installed [Payments](payments.md) and [instrument](instruments.md) recipes. Retain the package commit/archive hash, SDK commit/archive hash, dependency lock, API/webhook date, actual PHP/framework/database, cache/queue/lock profile and selected client versions. Verify runtime bytes and actual cached configuration before starting workers; a local checkout or a supplied version string is insufficient.

## Scope and preflight

Obtain separate approval naming the sandbox merchant account, dedicated local billable, Payments Customer and instrument ownership, each allowed action/client/currency, per-payment and aggregate amount limits, original payment/refund scope, operator and cleanup/retention plan. Keep credentials, client secrets, raw card/wallet artifacts and exact resource journals outside the repository and public diagnostics. Do not reuse Billing organization/entity/collector permission as Payments account authorization.

`tools/payments-sandbox-preflight.php` prints only booleans and a manual plan. Supply exact `--package-commit`, `--sdk-commit`, `--lock-sha256`, explicit `AIRWALLEX_PAYMENTS_ENVIRONMENT=sandbox`, external `AIRWALLEX_PAYMENTS_CLIENT_ID`/`API_KEY`, `AIRWALLEX_PAYMENTS_ACCOUNT_ID`, and these private controls:

| Variable under `AIRWALLEX_PAYMENTS_SANDBOX_` | Required value |
| --- | --- |
| `OPT_IN` | `I_AUTHORIZE_OWNED_PAYMENTS_SANDBOX_VALIDATION` after separate approval |
| `BILLABLE_ID`, `RUN_ID` | Dedicated authorized billable and private run identities |
| `CURRENCY`, `PAYMENT_CAP_MINOR`, `TOTAL_CAP_MINOR` | Explicit currency and positive integer-string limits in verified minor units |
| `OWNERSHIP` | `DEDICATED_AUTHORIZED_ACCOUNT_CUSTOMER_INSTRUMENTS` |
| `CLEANUP` | `JOURNALLED_ORIGINAL_PAYMENTS_AND_REFUNDS_ONLY` |
| `CLIENTS` | `METHOD_SPECIFIC_CLIENT_PLAN_REVIEWED` |

The tool never bootstraps the app, validates an account, enforces transaction caps or runs a financial action. `manual_journey_prepared` means only input-shape/attestation checks passed; environment flags do not grant authorization. A missing prerequisite exits 2. The application/operator must enforce the approved private amount limits and reconcile the original command before considering another action.

## Execute only the approved cases

Use the installed public APIs in authorized server endpoints; persist command keys and resolved exact amounts before invoking them. The browser owns provider tokenization and next actions; a callback supplies a locator, never financial truth.

| Case | Operations and evidence required |
| --- | --- |
| Customer/setup | Create or explicitly link the owned Payments Customer; verify login account independently from Billing. Create Consent and deliberately expose its ephemeral bootstrap only to the authorized client. A lost create-only secret uses original recovery first, then an explicitly distinct setup decision. |
| Saved instrument | Complete via canonical Consent/Method reads; list/default/revoke; reject a different customer's locator and confirm late reads cannot restore local revocation. Changing a default does not rewrite active Billing Sources or pending charges. |
| Card/wallet client | For every selected web/mobile/admin combination, record enabled method, SDK/client version, return-route authorization and actual tokenization/required-action behavior. Use `intentBootstrap()->expose()` only in that authorized endpoint. Missing real client/method evidence remains unexecuted. |
| Saved-card charge | Use stable intent and confirm command keys with a verified merchant-unscheduled Consent. Exercise success, decline, customer action and delayed outcomes. `continueIntent` only reads canonical state after the browser return. |
| Capture/cancel | Exercise account-supported authorization/partial capture/cancel paths. Authorization or request acceptance is insufficient: retain acknowledged obligations until positive capture advance or canonical cancellation. |
| Signed delivery/workers | Use the separately configured Payments endpoint/secret, actual queue workers, restart and recurring recovery. Compare canonical customer/account/amount/order with signed selected-version events. Verify duplicate/reordered delivery does not duplicate financial effects. |
| Refunds | Use the [owned refund API](refunds.md) against only the approved original captured payments; exercise partial/full and competing partial commands. RECEIVED remains pending; ACCEPTED/SETTLED are distinct observations. Verify unknown-outcome holds, eventual failure, recovery and exact cumulative reservations. |
| Application ledger | Record one cash/credit effect per verified provider/environment/account/financial identity across distinct delivery IDs and Billing external references. Exercise zero business completion, partial capture/refund, failed/pending results and unallocated invoice proof. A Payments refund does not create an Airwallex Billing Credit Note or application credit note automatically. |
| Cleanup | Reconcile original intents/refunds/operations before any further action. Cancel only eligible owned intents, revoke only approved consents and retain financial/mapping history. Journal every retained or ambiguous obligation; never broaden cleanup by email/list-all or create a replacement refund. |

The official [Payments testing facilities](https://www.airwallex.com/docs/payments/test-and-go-live/test-card-numbers) distinguish card/3DS, Apple Pay and Google Pay scenarios. Apple Pay testing uses Airwallex demo facilities and requires the supported device/browser/region; Airwallex's guide does not support Apple's sandbox environment. Follow [Apple Pay activation/domain/iOS setup](https://www.airwallex.com/docs/payments/payment-methods/global/apple-pay/enable-apple-pay) for the chosen integration. Google Pay has its own [activation](https://www.airwallex.com/docs/payments/payment-methods/global/google-paytm/enable-google-paytm) and [web test setup](https://www.airwallex.com/docs/payments/payment-methods/global/google-paytm/web-checkout#test-your-integration). These sources were checked on 2026-11-11; do not substitute PHP fixtures for actual wallet/client facilities or use personal wallet/card data without explicit owner authorization.

## Evidence and final qualification

The private journal binds each command, original provider resource, exact amount/currency, response classification, independent canonical observation, receipt/outbox IDs, worker/client builds and cleanup obligation. Publish only sanitized outcomes and source/dependency hashes. Preserve failed/pending results and unresolved ambiguity; operator-known IDs still require canonical request/intent/owner proof. Provider refund acceptance does not prove settlement or the shopper's bank posting, as the [refund guide](https://www.airwallex.com/docs/payments/payment-operations/manage-payments/refunds) explains.

Record each case as passed, failed, blocked or unexecuted with the missing input/evidence. Re-run affected cases after fixes. Final adoption qualification must bind subscription and Payments evidence to the same cumulative package/SDK/lock and backend; later changes that invalidate a journey require affected requalification. Release publication, downstream implementation, deployment rehearsal and production rollout remain separately authorized work.
