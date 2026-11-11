# Installation and upgrades

Laravel v0.1.0 is the released baseline. The adoption additions below are unreleased. The local qualification descriptor still uses `0.1.0-RC1` solely to identify an exact review archive; it does not select a future release number. Follow [candidate installation](docs/release.md) and [configuration](docs/usage.md#install-and-configure).

Back up the application database and encryption keys before applying package migrations. Automatic discovery loads package migrations, including the observed-routing additions and the adoption tables. Migrations are reversible for a clean uninstall, including removal of package history; rollback is not an offboarding workflow and must not discard unresolved financial evidence. Rehearse changes against a disposable copy of the application's data.

## Upgrading from 0.1.0

Apply the pending migrations for persisted sweeps, subscription transitions and Payments customers, operations, instruments, intents, refunds, receipts and observations. Existing Billing mappings and financial history remain authoritative. If the application publishes migrations, compare the package's migration files with its recorded migration names before copying only missing files; preserve customizations and avoid duplicate table creation.

Review the new configuration keys against the application's published configuration, then rebuild its configuration cache and restart workers. Billing's `webhook.queue_connection` and `events.queue_connection` default to null and retain the application default. Payments uses separate environment/account/credentials and an opt-in `payments.webhook.enabled` receiver; a Billing organization, Source or signing secret is not a Payments identity. Provision the application's database queue/cache/lock tables when choosing that profile, and register the recurring commands in [operations](docs/operations.md) and [Payments recovery](docs/payments-webhooks.md).

Existing standalone `Billable` calls retain their contracts. Models already using Cashier should use `AirwallexBillable` for explicitly authorized Airwallex work and retain original-provider routes for historical renewals/refunds; see [coexistence](docs/adoption-handoff.md). Do not replace or infer Payments mappings from existing Billing or Stripe identifiers.

Invoice transaction reads require released SDK v0.1.2 or later. Explicitly require `jojeringnax/airwallex-php-sdk:^0.1.2` in applications using them, update the lock and qualify the installed recipes on the application's supported profile. The distributed constraint remains `^0.1.1`; v0.1.1 alone raises a configuration prerequisite for those reads. SDK v0.1.2 also supplies the opt-in object-preserving decoder; this adapter retains array defaults and conservative numeric-key metadata recovery pending [adapter qualification #37](https://github.com/jojeringnax/airwallex-laravel/issues/37).

Use the restricted [renewal-restoration and transition contracts](docs/contracts.md#adoption-amounts-and-provider-routing) only for supported combinations. Imported/old scheduled subscriptions without the original saved bound cannot use renewal restoration. Keep existing command keys, encrypted intents, reservations, receipts and event delivery IDs through upgrades and rollback planning; a restored prepared row does not prove a write was never sent.

## Identity, recovery and uninstall

Keep environment/organization, billable morph identity and remote parent/resource IDs stable. Credential rotation does not change mapping identity. Changing an established morph map or custom table layout needs a coordinated application data/schema migration. Do not repoint a local mapping to another remote Customer or organization. Published migrations/custom subclasses must preserve the package's unique and foreign-key constraints, immutable fields and casts.

The schedule-cap migration only backfills `scheduled_ends_at` from matching positively owned raw snapshot evidence with a valid scheduled flag/bound. It does not fabricate effective dates from stale unrelated columns. Inspect a scheduled subscription with missing proof and reconcile it; default access remains conservative. Existing receipts/operations/outbox rows are operational evidence and must survive ordinary upgrades.

During 0.x, documented breaking public changes require a new minor with release notes and migration guidance; patches preserve public behavior. Only the latest minor is maintained under the provisional policy. Before adopting another candidate/release, inspect its notes, run dependency audit and migrations/recipes on your qualified runtime/backends, and compare exact SDK/API/webhook versions. Reconfigure a webhook subscription's version explicitly when required; changing API headers alone does not migrate deliveries.

Before uninstalling, follow [offboarding and retention](docs/operations.md#retention-replay-and-offboarding): explicitly confirm intended cancellations, resolve held operations, retain lawful invoice/history access and recover pending receipts/events. Removing code or a billable never cancels remote subscriptions.

The observed-routing migration adds separate Subscription and Operation evidence columns and backfills only positively owned Subscription response values. `observed_routing` retains those values when later optional fields are omitted; raw snapshots still reflect the latest response. Foreign-route imports remain inspectable, but a retained contradiction prevents high-level financial writes until a canonical read positively establishes the configured routing.
