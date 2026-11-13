# Installed Payments qualification

Issue [#62](https://github.com/jojeringnax/airwallex-laravel/issues/62) owns this deterministic consumer qualification. The runner installs an exact Git ZIP in a new native Laravel application, checks installed bytes, boots cached configuration, migrates, rolls back and reapplies every package migration, and executes every shipped recipe selected by its tracks. It makes no Airwallex request: a deny-by-default synthetic PSR transport is the only payment transport. Public Composer dependency downloads remain permitted.

```sh
python3 tools/qualify-candidate.py --candidate <reviewed-commit> \
  --composer /absolute/path/to/verified-composer-2.11.2.phar \
  --laravel 13 --database sqlite --track all --sdk-version 0.1.3 \
  --workspace /private/tmp/airwallex-payments-consumer \
  --output /private/tmp/airwallex-payments-evidence
```

For MySQL, precreate an empty disposable `airwallex_test_<lowercase suffix>` schema and set `AIRWALLEX_QUALIFY_MYSQL_DATABASE`, `AIRWALLEX_TEST_MYSQL_HOST`, `AIRWALLEX_TEST_MYSQL_PORT`, `AIRWALLEX_TEST_MYSQL_USER` and `AIRWALLEX_TEST_MYSQL_PASSWORD`; select `--database mysql`. The runner never reads credentials from the consumer application being adopted. These test database credentials must identify the disposable local service. Lowest/latest dependencies and Laravel 12/13 are explicit runner choices; CI exercises the declared matrix.

The current cumulative candidate ships both adoption tracks, so its complete recipe set requires `--track all` and an SDK version supporting Billing transactions, selected here with `--sdk-version 0.1.3` from Packagist. A Payments-only track is valid only for an exact historical candidate whose shipped example set matches it; it uses the declared released SDK constraint. Every recipe in that single archive must appear in the union of executed reports; a missing helper or skipped recipe fails qualification. Tests and helpers are extracted from that candidate's Git blobs into the disposable consumer. Installed tests use its real Composer autoloader and archive source, including subprocess workers, with no repository source override or path repository. Testbench, PHPUnit and real Cashier are optional dependencies of this disposable fixture, not runtime package requirements.

## Executed application boundary

The [`payments-*.php` recipes](../examples/payments-customer.php) use public installed services on the original persisted Cashier model. They cover account-owned customer setup, embedded Consent setup and explicit local default, exact minor-unit payable conversion, merchant-initiated saved-card confirmation, synthetic customer-present Apple Pay/Google Pay method seams, authorized ephemeral client bootstrap, canonical browser return, partial capture recovery, explicit partial/full-remaining refund commands, signed receipt acceptance, ambiguous-create read recovery and a cumulative application ledger. The fixture also calls real Cashier's refund with a synthetic Stripe transport, proving historical payments retain their original provider. The installed consequential resource suites retain ownership, races, stale method/consent proof, pending/ambiguous outcomes, capacity, receipt worker races and secret boundary coverage.

Currency conversion uses the [documented Airwallex precision reference](payments.md#payment-intents-and-exact-amounts), including SLE precision 0. Deterministic tests cover exact SLE creation, equivalent major/minor command reuse and excess fractional precision rejection before HTTP.

A missing local credential is a configuration failure before the mutation claim. The original prepared row, request ID and customer mutex remain available; repairing configuration and repeating the same key resumes one provider write. Provider authentication, transport or response failures after the claim remain uncertain. This distinction does not authorize replay.

The fixture's 3DS state changes model a canonical callback. These deterministic fixtures do not execute actual browsers, SDK client configuration or Apple Pay/Google Pay merchant eligibility/device actions. The [general Sandbox gate](sandbox.md) remains applicable; [issue #64](https://github.com/jojeringnax/airwallex-laravel/issues/64) owns the separate [Payments account/client journey](payments-sandbox.md), including its bounded real card/refund observations and remaining gaps. No installed result proves wallet readiness, account capabilities or settlement.

## Application ledger schema and trust

[`payments-ledger.php`](../examples/payments-ledger.php) is application code. Its trusted arguments are the expected local customer, a server-selected table name, and a verified currency clearing account. It checks the configured environment/account and original resource ownership. The caller must authorize the payable and establish its mapping to that customer/resource; webhook input cannot supply those mappings. The fixture creates the following disposable application tables:

- A state table keyed by `financial_identity` with `provider`, `environment`, `account_id`, `resource_id`, nullable `invoice_id`, `currency`, `proof_reference`, `clearing_account`, and decimal-digit text `amount_minor` (the cumulative posted total).
- An `<state table>_entries` table with unique `id`, `financial_identity`, `delivery_id`, `kind`, `currency`, and decimal-digit text `delta_minor`.

The state row is inserted and locked in the same transaction as each new delta. Positive captured totals of 40.00 then 60.00 post 4000 then 2000 minor units once. They share the canonical payment identity, while observations and listener deliveries have separate stable identities. Duplicate delivery and a listener crash after the application commit produce no second cash row. A canonical `SUCCEEDED` payment with positive capture represents that captured cash; the application still compares cumulative capture with its payable total before fulfillment. A 60.00 capture against a 100.00 payable remains partial.

Refund `RECEIVED`/`PENDING` reserves capacity without posting completion. `ACCEPTED` is payment-method-provider completion, while `SETTLED` records reconciliation into Airwallex settlement reporting; neither establishes shopper bank-credit timing. Both use the same original refund identity and amount, so the example posts one refund credit across that transition. The application must separately decide credit-note issuance, order adjustment, tax and general-ledger entries. The example records a positive refund delta under `refund_completed` and inserts one transactional `<state table>_credit_notes` handoff keyed by financial identity, with `refund_id`, local `intent_id`, `currency`, `amount_minor` and `status=pending_review`. Duplicate settlement does not create another handoff. This durable review request is not an issued credit note; accounting owns tax/order authorization and issuance. It does not silently subtract that amount from gross received cash. Reconcile cash and refunds as separate accounting movements. [Refund evidence](refunds.md#contract-evidence-and-qualification) owns these provider definitions.

Billing transaction `external_id` is only a locator. After an independently verified owning invoice/account mapping, resolve the original Payments Intent canonically and send the same canonical resource through this writer. The synthetic fixture checks this path deduplicates with direct Payments observation. Actual Billing transaction reads are exercised by the separate subscriptions track; this check does not prove exclusive allocation of a payment to an invoice. An out-of-band/manual invoice mark or a Billing invoice's paid status alone cannot insert cash. Multiple application cash writers must share the identity/state schema and transaction rule rather than create an independent ledger per provider notification.

## Evidence and remaining gates

Each run stores the full candidate revision, archive SHA-256/manifests, exact installed SDK/dependency versions and lock, tooling provenance, runtime/database versions, command outcomes and recipe reports. A failed run remains failed evidence. Successful installed qualification establishes those synthetic code paths in that selected disposable profile only.

Account-owned Sandbox journeys, natural financial timing and browser wallets/3DS remain separate validation work. Integrating applications own authorization, catalogue/ledger policy, production configuration and migration/cutover; those activities are outside this library qualification. Awaiting-merge implementation is not released delivery.
