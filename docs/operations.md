# Webhooks and recovery

Use this reference when deploying the receiver, queue workers and scheduler, or investigating an uncertain action. [Public contracts](contracts.md) own persisted identities and lifecycle rules; [provider evidence](provider-contracts.md) owns event availability and account prerequisites. Application authorization of a billable is required independently of subscription eligibility.

Use the [qualified database prerequisites](compatibility.md) for web, scheduler and workers. MySQL package tables use InnoDB and exact binary NO PAD identities even when the application connection defaults to an insensitive collation. Preserve foreign keys, strict mode and transactional ownership constraints in backups/restores. Package row locks cover short local commits, never provider calls; a database lock/deadlock conflict means inspect/retry local coordination, not replay an uncertain financial write.

## Application runtime checklist

Use this checklist when selecting an integration profile or rehearsing a deployment. Record results against the application's actual web, worker and scheduler processes; a passing library matrix establishes only its tested profiles. Application upgrades and deployment execution belong to the integrating application.

| Check | Evidence to retain |
| --- | --- |
| Supported dependency graph | Actual 64-bit PHP, Laravel, SDK and database versions against [compatibility](compatibility.md), including the engine settings checked by migrations. Select the SDK feature version required by [the public interface](contracts.md#invoice-transaction-observations), then resolve and audit the complete application graph. |
| Package provenance and advisories | Composer lock, package/SDK source or dist references, archive hashes and installed-file comparison from [candidate qualification](release.md#adoption-tracks-and-sdk-selection). Run the application's `composer validate --strict` and `composer audit --locked`; resolve relevant findings before claiming that graph is qualified. A development archive remains unreleased. |
| Database, queues and cache | Applied package migrations and application-owned jobs/failed-jobs/cache/lock tables, exact queue connection and queue names, token-cache store and tested lock behavior. Follow [receipt and event delivery](#receipt-and-event-delivery) and the separate [Payments worker configuration](payments-webhooks.md). Verify a real worker consumes the selected connection while preserving failed work. |
| Scheduler and recovery | Loaded schedules from `php artisan schedule:list`, an independently running scheduler and workers, and observed progress of [recurring recovery](#bounded-commands). Schedule [financial transitions](#deferred-transitions) explicitly only for already authorized plans. Verify the actual shared cache/lock topology before using multiple scheduler hosts. |
| Keys, backups and restore | Private key inventory and encrypted-data recovery using matching Laravel encryption-key material. Rehearse a consistent database restore with exact identities, constraints, original commands and receipts intact. Follow [restore and resumption](adoption-handoff.md#isolated-rehearsal-and-resumption): reconcile the post-backup command interval before resuming financial workers. |
| Signing and cached configuration | Inspect effective cached configuration privately in each process: immutable environment/owner, external credentials, endpoint secrets, webhook version, queue routing and cache stores. Apply [Billing receiver](#receiver-deployment) and [Payments receiver](payments-webhooks.md) settings independently. Rebuild the application's configuration cache and restart long-lived workers after configuration changes; retain redacted classifications rather than configuration dumps. |
| Clocks, timeouts and leases | Synchronized UTC clocks across web/worker/scheduler/database hosts, plus measured maximum request/job duration. Review SDK connect/request/retry budgets, worker/job timeout, queue `retry_after`, receipt/outbox/sweep leases and signature tolerance in [package configuration](../config/airwallex.php). Set timeout and lease budgets consistently so an ordinary in-flight job does not become eligible for competing execution. Never widen signature tolerance merely to conceal clock drift. |
| Operational ownership | Named application owners, backlog/lease/ambiguity alerts and a private original-resource journal using [the operator handoff](adoption-handoff.md#operators-and-alerts). Stop new financial work on unresolved restore gaps while retaining owned canonical reads and existing obligations. |

For example, an application may select the qualified database queue/cache/lock profile below while retaining a different default queue. Adapt its connection names, supervisors and scheduler entry point to the application. Redis/SQS, other engines and multi-host behavior require their own evidence under the compatibility policy. Provider/account/client journeys use the separately guarded [Billing](sandbox.md#adoption-gate-63) and [Payments](payments-sandbox.md) plans; synthetic runtime checks do not establish account eligibility, financial outcomes or deployment readiness.

## Receiver deployment

Configure one environment and Billing organization, its nondelegated API credentials, an endpoint secret, and a webhook subscription explicitly using version `2026-08-21`. API request version headers do not configure webhook subscriptions. Register the HTTPS URL in Airwallex and select the supported subscription, Billing Checkout and invoice events listed in the provider reference. Configure the endpoint secret outside version control:

```dotenv
AIRWALLEX_ENVIRONMENT=sandbox
AIRWALLEX_ORGANIZATION_ID=your-billing-organization
AIRWALLEX_WEBHOOK_SECRET=your-endpoint-secret
AIRWALLEX_WEBHOOK_QUEUE=billing
AIRWALLEX_EVENTS_QUEUE=billing
AIRWALLEX_WEBHOOK_QUEUE_CONNECTION=billing-database
AIRWALLEX_EVENTS_QUEUE_CONNECTION=billing-database
```

`airwallex.webhook.queue_connection` and `airwallex.events.queue_connection` select existing Laravel queue connection names independently. Their default null preserves the application's default connection. Queue names remain independently configurable. For example, define `queue.connections.billing-database` using Laravel's database queue driver and the same qualified database as the package, then run `php artisan queue:work billing-database --queue=billing`. The application may keep a different default such as `sync`. A missing/invalid package connection prevents enqueueing: receipts retain `dispatch_configuration`, and committed outbox events remain undispatched for recovery. Fix configuration and restart workers before retrying; invalid routing never discards committed receipts/events.

The default POST route is `/airwallex/webhook`, named `airwallex.webhook`. Publish `airwallex.php` to change its path, middleware or enablement. It is registered outside Laravel's `web` and authenticated browser middleware groups. If your application adds CSRF middleware globally or to this route, exempt only this receiver path in the application's CSRF configuration. Signature verification remains mandatory. For example, Laravel's application `bootstrap/app.php` can call `$middleware->validateCsrfTokens(except: ['airwallex/webhook']);` with your actual configured path. Keep browser return routes authorized separately: query parameters or a success page never confirm a financial result. Load an already-authorized local Checkout and call `reconcile()` server-side.

The receiver passes the exact body and `x-timestamp`/`x-signature` to the SDK before decoding. Timestamp tolerance is in milliseconds; the default is 300,000. It never selects credentials or a secret from incoming data. Signature/JSON failure returns 400, and invalid configuration or failed durable storage returns 503. A 200 follows committed acceptance, including an explicitly classified ignored/quarantined delivery. A subsequent queue dispatch failure leaves the receipt available to recovery commands.

Supported envelopes require an explicit matching `org_id` and selected version. `account_id` is not an organization alias. Generic wrapped `data.object` and direct Billing `data` are supported; a malformed present wrapper never falls back. Unknown names are ignored; missing/foreign/conflicting ownership, unsupported versions and malformed recognized envelopes are quarantined. Neither a valid signature nor a known email authorizes automatic resource adoption. Identity guards operate on the SDK-decoded representation: the selected decoder normalizes numeric tokens to strings at these positions and retains last-wins duplicate-key behavior. It does not expose native JSON token provenance. This package does not claim rejection of every wire-type distinction; independently owned canonical reads and stored operation proof still govern all association and state changes.

The optional [object-preserving mode](usage.md#install-and-configure) must use the same selected SDK and boolean setting in HTTP, scheduler and workers. Rebuild cached configuration and restart every worker after changing it. It preserves decoded metadata object/list proof; original-operation identity and owned canonical reads still control recovery.

## Receipt and event delivery

Receipts retain the original verified body encrypted at rest, with context/event ID, digest, attempts, outcome and worker lease. Body access is sensitive operational access: restrict database/backups and retain the Laravel encryption key according to application policy. Default model output hides bodies. A same-ID/body duplicate reuses its receipt. A same-ID/different-body conflict quarantines it and invalidates any active lease. Receipt workers read canonical resources outside database transactions and check their current lease again inside projection/finalization transactions.

Unknown resources are ignored unless an attempted, recorded creation operation provides correlation that canonical reads verify. Known subscription IDs always select their immutable local generation, including history. A delayed cancellation cannot release a replacement subscription's name. Setup verifies a source but grants no access. Invoice notifications verify the invoice and may refresh an already-known same-customer subscription; invoice payment status does not directly change access.

Projection events are recorded in the same transaction as local state. The outbox dispatches `SubscriptionReconciled`, `CheckoutReconciled`, `InvoiceObserved` and `WebhookProcessed` after commit. Events carry a stable delivery ID, context, local resource identity and safe observation fields. They contain no customer profile, body, secret or document/checkout URL. Listeners must deduplicate the event delivery ID: a crash after listener delivery and before acknowledgement can redeliver the same row. A failed synchronous listener or queue dispatch leaves recoverable outbox work. Event delivery does not authorize a new financial command. Retrying one outbox row retains its delivery ID; explicitly replaying a receipt is a new canonical observation and can create new InvoiceObserved/WebhookProcessed delivery IDs. Use receipt_id/provider_event_id as an additional application deduplication boundary when that is appropriate for your listener.

Use a configured persistent queue and run its worker for the selected queue names. A synchronous queue is supported for deterministic tests and small deployments, but executes canonical reads/listeners within the HTTP request after receipt acceptance. Use asynchronous workers to keep production acknowledgements prompt. Worker jobs bind their stored environment/organization to current configuration before processing; changing deployments never reroutes old jobs to another organization.

The selected deterministic adoption profile uses Laravel database jobs, database token cache and database scheduler locks, backed by one qualified SQLite/MySQL database shared across HTTP/worker scopes. Provision the application's jobs, failed-jobs, cache and cache-lock tables separately from package migrations, select the explicit billing queue connections above, and set `airwallex.cache_store` to the configured database cache store. Queue reservation `retry_after`, job/worker timeout and package lease durations must cover the longest in-flight work; the consumer's configured values require deployment review. Local fixtures and installed-archive worker checks establish this isolated topology, not the deployed consumer's runtime, supervisors or shared lock setup. Redis/SQS and multi-host topology remain unqualified. Application runtime upgrades and deployments are outside library qualification.

The recovery `airwallex:events` command (and sweep event family) deliberately invokes listeners synchronously for pending outbox rows. Queue selection governs automatically dispatched receipt/outbox jobs; it does not silently turn operator commands into asynchronous jobs. Laravel `ShouldQueue` listeners keep their own connection/queue policy and failed-job handling.

The event classes live in `Jojeringnax\AirwallexLaravel\Events`. Each extends readonly `BillingEvent` with `id`, `environment`, `organizationId`, `resourceType` (local model table), `resourceId` (local mapping ID) and `payload` properties. Payload fields are nullable strings:

| Event | Observation payload |
| --- | --- |
| `SubscriptionReconciled` | Remote `remote_id`, `previous_status`, current `status` |
| `CheckoutReconciled` | Remote `remote_id`, `mode`, `previous_status`, current `status` |
| `InvoiceObserved` | Remote invoice `remote_id`, `subscription_id`, `status`, `payment_status`; webhook observations additionally include `receipt_id` and `provider_event_id`. The local resource is the Customer, or the Subscription for a subscription-scoped application read. |
| `WebhookProcessed` | `receipt_id`, `provider_event_id`, `event_name`, remote `resource_id`, `outcome`, `reason` |

Register Laravel listeners for these classes, persist delivery-ID deduplication in application storage and make side effects idempotent. A synchronous listener may receive an already-delivered ID after a crash; throwing before the dispatcher returns leaves its outbox row eligible for retry. Laravel ShouldQueue listeners have a separate handoff: successful enqueueing can mark the outbox row dispatched before that listener executes. Recover their retries/failed_jobs through Laravel queue operations; the outbox does not track eventual queued-listener completion. No event represents an exactly-once payment instruction.

## Bounded commands

Commands operate only in the configured environment/organization. Output is safe JSON lines containing local IDs, classifications and resume cursors. Limits are 1–1,000, default 100. They never send financial mutations.

| Command | Operation |
| --- | --- |
| `airwallex:reconcile` | Refresh up to `--limit` known customers, subscriptions and checkouts per type. Select `--type=customer`, `subscription` or `checkout`; resume that type using its last `--after=LOCAL_ID`. The default `--type=all` starts each bounded type batch and cannot use a shared cursor. |
| `airwallex:operations` | Inspect unresolved intents without constructing the SDK. `--reconcile` performs reads only for attempted operations. `--id=LOCAL_ID` selects one, including terminal inspection. `--after=LOCAL_ID` resumes a batch. |
| `airwallex:receipts` | Dispatch pending/failed receipts and expired processing leases. `--after=LOCAL_ID` resumes the batch. `--id=LOCAL_ID --replay` explicitly reprocesses an eligible retained receipt body. |
| `airwallex:events` | Deliver pending outbox rows whose leases are available; `--after=LOCAL_ID` resumes the batch. |
| `airwallex:prune-receipts` | Purge up to `--limit` expired terminal bodies, preserving identity/digest/outcome for duplicate detection. |
| `airwallex:sweep` | Advance durable recurring progress for `customer`, `subscription`, `checkout`, attempted `operation`, eligible `receipt` and pending `event` families. `--family=NAME` selects one; default `all` gives each family its own bounded turn. `--limit=100` (1–1,000) and `--seconds=30` (1–3,600) apply **per family**. |

Use `airwallex:sweep` for recurring recovery. Unlike the manual single-batch commands, it persists a separate cursor and high-water ID for each family/environment/owner. Every attempted row advances progress, including failures; a permanently failing first row cannot occupy every recurring batch. Reaching the high-water boundary completes the sweep and the next invocation starts another. An exact-limit batch can require one subsequent empty probe to record completion. Rows inserted above the boundary, or behind the current cursor, wait for the next sweep; deletion removes work without invalidating progress. Eligibility is evaluated when each row is selected, so a busy receipt/event can be revisited in a later sweep.

The row budget limits attempts, including retained failures. The monotonic time budget stops starting more rows; an in-flight provider read/listener can finish later. Configure SDK timeouts/retries and worker timeouts accordingly. Total invocation work is bounded by selected family count × row budget; each family receives its own time budget. No sweep submits a prepared financial intent or replays an ambiguous write. Receipt dispatch and listener delivery retain their existing at-least-once behavior.

Progress is committed after each attempted row. A crash before that checkpoint can repeat the last read or dispatch after its lease expires; canonical projections, receipt/event leases and listener deduplication remain required. Database compare-and-set leases coordinate overlapping sweep workers independently of scheduler cache locks. `airwallex.sweep.lease_seconds` defaults to 300 and must cover an individual read/listener; a superseded/expired worker cannot advance the cursor or clear another worker's lease. All workers must use the same qualified database and synchronized UTC clocks.

Each JSON summary exposes a safe progress ID, cursor/boundary, attempts/failures, last failure classification, `last_completed_at` and completed sweep count. `sweep_completed` means the bounded scan finished, including retained failures; inspect failures separately. `busy` means another worker holds that family. Alert on stale completion times and repeated failure classifications. The single-batch commands remain available for inspection, targeted recovery and explicit terminal receipt replay.

Register the recurring sweep and body pruning in the application's actual scheduler entry point. For applications using `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('airwallex:sweep --limit=100 --seconds=30')->everyMinute()->withoutOverlapping();
Schedule::command('airwallex:prune-receipts')->daily()->withoutOverlapping();
```

Applications configuring schedules through `bootstrap/app.php` can register the same commands in `->withSchedule(function (Schedule $schedule) { ... })`. Use the entry point enabled by the application; verify registration with `php artisan schedule:list`.

Adapt budgets/frequency to backlog and provider rate limits. Scheduler overlap locks require an application cache with lock support; multi-host scheduling requires infrastructure that actually shares those locks and the qualified database. Sweep database leases protect each family even when a scheduler lock is unavailable, but do not configure scheduler infrastructure. Run Laravel's scheduler and queue supervision independently; registering schedules does not start workers.

Additional read-only resource families register a `Support\SweepFamily` with the scoped `Services\SweepRegistry` through the application container. Supply a unique bounded family name, environment, owner type and exact owner ID, a closure returning a context-filtered Eloquent query ordered by its stable string `id`, and a processor returning true for completion or false for retained work. Both closures must enforce that same immutable owner; the registry does not authorize arbitrary rows. A Payments account uses a distinct owner type/ID, never the Billing organization as an alias. The engine installs a composable `Services\CommitGuard`: processors must call `assertMayCommit()` inside each short projection transaction, as the existing canonical synchronizers do, to fence stale projection commits as well as progress. Nested receipt guards retain both checks. Listener side effects still require delivery-ID deduplication. Financial deferred commands require their separate operation workflow.

## Deferred transitions

`php artisan airwallex:transitions --limit=100` executes only already authorized due subscription plans and resumes their original commands. Unlike reconciliation commands, it may submit a plan's first financial mutation. Schedule it explicitly, for example every minute, on the configured Billing context. It filters future candidates by a UTC index and compares exact stored timestamp text before execution. Batches are bounded and rotate by attempt count so unresolved work cannot permanently starve later plans. Output contains local IDs and safe outcome classifications; inspect encrypted intents through an authorized operator path.

Persisted `scheduled` plans reserve one local generation but leave ordinary financial mutation locking available; a later source/item/renewal change causes expected-shape rejection rather than silently applying stale terms. `executing` survives a process interruption; the same derived operation key recovers or sends its first unattempted command. `ambiguous` retains the plan and original financial mutex/evidence as applicable. `completed` requires confirmed operation fulfillment. `blocked` requires an explicit cancel/replacement decision; missed windows do not authorize catch-up, compensation or recreation. Cancelling/replacing an attempted plan is rejected. A worker outage can miss the intended boundary; neither the scheduler nor local time stops provider renewal. [SDK question #161](https://github.com/jojeringnax/airwallex-php-sdk/issues/161) retains exact timing and unsupported free-period/renewal obligations.

## Uncertain actions

Persist the original application command key before a financial call. On `AmbiguousOperationException`, retain its `operationId` and inspect it. Keep the original request ID, payload and name hold. A timeout, provider outage, 404 or expired worker lease never proves that the write failed and never permits automatic replacement/replay.

For a known resource ID, use `airwallex:operations --id=LOCAL_ID --reconcile`. For an unknown direct subscription/checkout creation, an operator can supply a locator with `--remote-id=PROVIDER_ID`; canonical ID/customer/routing and reserved `_laravel_operation_id` metadata must still match the original intent. A signed notification uses the same checks. A different recorded ID, foreign customer, changed mode or missing correlation cannot be forced through this command. Routing follows the [per-resource response contract](provider-contracts.md#workflow-map): optional omissions alone do not block recovery; required Checkout collector, returned routing contradictions and malformed values still fail. The original durable request remains separate from raw response observations. The SDK has no Checkout list or request-ID lookup; irreducibly unknown operations remain held for provider/operator investigation. Unknown customer creation has no automatic association shortcut.

`prepared` means no attempt is recorded: recovery reports a conflict instead of sending it. The application's original explicit command path may submit that same prepared intent. `failed` means a verified unsent rejection, such as SDK request validation or a preflight ownership, evidence or provider-read failure before the mutation. Inspect its safe error classification, resolve the cause, and prepare a new explicit command key. `ambiguous` means proof remains incomplete and its active mutation hold stays in place.

`acknowledged` means a known item-write response was recorded, but the requested observable item effect remains unproven. The active mutex is released; the operation is never replayed, including by the same key. Exact observed quantity/item conditions may later establish fulfillment. Overlapping additions without command-specific provider attribution can remain unconfirmed indefinitely. Inspect actual canonical items before a distinct, explicitly authorized action; do not treat acknowledgement as a pending guarantee, active fee or settlement. [Question #40](https://github.com/jojeringnax/airwallex-laravel/issues/40) records this provisional policy.

Default access always follows confirmed lifecycle/dates. UNPAID denies immediately; source replacement is a separate explicit action that may immediately retry the latest failed invoice. Neither successful setup nor local time restores access. A later canonical ACTIVE observation can restore eligibility. Provider dunning configuration determines retry behavior; there is no package cancellation deadline.

An acknowledged call returns the actual canonical Subscription. To find its operation without an exception, use the original command key in the already-authorized subscription's customer/context scope:

```php
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\ModelRegistry;

$operation = ModelRegistry::query('operation')
    ->where(app(BillingContext::class)->attributes())
    ->where('customer_id', $subscription->customer_id)
    ->where('action_key', $persistedCommandKey)
    ->firstOrFail();
// Inspect $operation->id, status and error_code; keep raw intent private.
```

## Retention, replay and offboarding

The default body retention is seven days after terminal processing/ignoring/quarantine. Pending, processing and failed bodies remain recoverable. Pruning removes bodies rather than receipt identities; a purged body cannot be replayed. Replay uses previously verified encrypted bytes and trusted context, without requiring the old signature timestamp to be fresh today. Conflicting-digest quarantines cannot be replayed as though the conflict disappeared. Canonical reconciliation remains available independently.

Monitor failed/expired leases, quarantine reasons, undispatched outbox rows and ambiguous/acknowledged operations. Investigate ownership/version conflicts against the selected provider subscription before changing configuration. A provider outage retains the last confirmed projection; the local access policy still applies its confirmed time bounds. Do not paste bodies, credentials, customer profiles, checkout URLs or client secrets into logs/issues.

Offboarding is an application workflow: authorize the billable, inspect current reservations and operations, explicitly cancel intended owned subscriptions with recorded command keys and proration, and reconcile confirmation. Preserve invoice access under your application's ownership policy after termination. Deleting a local user never cancels remotely; package history/children restrict deletion. Resolve in-flight work and your retention/legal obligations before deleting local mappings. Unmapped remote resources require explicit authorized customer link/subscription import; no email search or automatic adoption occurs.
