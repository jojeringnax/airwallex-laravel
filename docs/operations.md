# Webhooks and recovery

Use this reference when deploying the receiver, queue workers and scheduler, or investigating an uncertain action. [Public contracts](contracts.md) own persisted identities and lifecycle rules; [provider evidence](provider-contracts.md) owns event availability and account prerequisites. Application authorization of a billable is required independently of subscription eligibility.

## Receiver deployment

Configure one environment and Billing organization, its nondelegated API credentials, an endpoint secret, and a webhook subscription explicitly using version `2026-08-21`. API request version headers do not configure webhook subscriptions. Register the HTTPS URL in Airwallex and select the supported subscription, Billing Checkout and invoice events listed in the provider reference. Configure the endpoint secret outside version control:

```dotenv
AIRWALLEX_ENVIRONMENT=sandbox
AIRWALLEX_ORGANIZATION_ID=your-billing-organization
AIRWALLEX_WEBHOOK_SECRET=your-endpoint-secret
AIRWALLEX_WEBHOOK_QUEUE=billing
AIRWALLEX_EVENTS_QUEUE=billing
```

The default POST route is `/airwallex/webhook`, named `airwallex.webhook`. Publish `airwallex.php` to change its path, middleware or enablement. It is registered outside Laravel's `web` and authenticated browser middleware groups. If your application adds CSRF middleware globally or to this route, exempt only this receiver path in the application's CSRF configuration. Signature verification remains mandatory. For example, Laravel's application `bootstrap/app.php` can call `$middleware->validateCsrfTokens(except: ['airwallex/webhook']);` with your actual configured path. Keep browser return routes authorized separately: query parameters or a success page never confirm a financial result. Load an already-authorized local Checkout and call `reconcile()` server-side.

The receiver passes the exact body and `x-timestamp`/`x-signature` to the SDK before decoding. Timestamp tolerance is in milliseconds; the default is 300,000. It never selects credentials or a secret from incoming data. Signature/JSON failure returns 400, and invalid configuration or failed durable storage returns 503. A 200 follows committed acceptance, including an explicitly classified ignored/quarantined delivery. A subsequent queue dispatch failure leaves the receipt available to recovery commands.

Supported envelopes require an explicit matching `org_id` and selected version. `account_id` is not an organization alias. Generic wrapped `data.object` and direct Billing `data` are supported; a malformed present wrapper never falls back. Unknown names are ignored; missing/foreign/conflicting ownership, unsupported versions and malformed recognized envelopes are quarantined. Neither a valid signature nor a known email authorizes automatic resource adoption. Identity guards operate on the SDK-decoded representation: the selected decoder normalizes numeric tokens to strings at these positions and retains last-wins duplicate-key behavior. It does not expose native JSON token provenance. This package does not claim rejection of every wire-type distinction; independently owned canonical reads and stored operation proof still govern all association and state changes.

## Receipt and event delivery

Receipts retain the original verified body encrypted at rest, with context/event ID, digest, attempts, outcome and worker lease. Body access is sensitive operational access: restrict database/backups and retain the Laravel encryption key according to application policy. Default model output hides bodies. A same-ID/body duplicate reuses its receipt. A same-ID/different-body conflict quarantines it and invalidates any active lease. Receipt workers read canonical resources outside database transactions and check their current lease again inside projection/finalization transactions.

Unknown resources are ignored unless an attempted, recorded creation operation provides correlation that canonical reads verify. Known subscription IDs always select their immutable local generation, including history. A delayed cancellation cannot release a replacement subscription's name. Setup verifies a source but grants no access. Invoice notifications verify the invoice and may refresh an already-known same-customer subscription; invoice payment status does not directly change access.

Projection events are recorded in the same transaction as local state. The outbox dispatches `SubscriptionReconciled`, `CheckoutReconciled`, `InvoiceObserved` and `WebhookProcessed` after commit. Events carry a stable delivery ID, context, local resource identity and safe observation fields. They contain no customer profile, body, secret or document/checkout URL. Listeners must deduplicate the event delivery ID: a crash after listener delivery and before acknowledgement can redeliver the same row. A failed synchronous listener or queue dispatch leaves recoverable outbox work. Event delivery does not authorize a new financial command. Retrying one outbox row retains its delivery ID; explicitly replaying a receipt is a new canonical observation and can create new InvoiceObserved/WebhookProcessed delivery IDs. Use receipt_id/provider_event_id as an additional application deduplication boundary when that is appropriate for your listener.

Use a configured persistent queue and run its worker for the selected queue names. A synchronous queue is supported for deterministic tests and small deployments, but executes canonical reads/listeners within the HTTP request after receipt acceptance. Use asynchronous workers to keep production acknowledgements prompt. Worker jobs bind their stored environment/organization to current configuration before processing; changing deployments never reroutes old jobs to another organization.

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

Run these in the application's Laravel scheduler with overlap prevention. For example, in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('airwallex:receipts')->everyMinute()->withoutOverlapping();
Schedule::command('airwallex:events')->everyMinute()->withoutOverlapping();
Schedule::command('airwallex:operations --reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('airwallex:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('airwallex:prune-receipts')->daily()->withoutOverlapping();
```

Adapt batch cursors/frequency to your backlog; a permanently failing oldest row must not hide later work. Run the next batch with the emitted cursor and revisit failed IDs separately. Scheduler overlap locks require an application cache with lock support; multi-host scheduling requires infrastructure that actually shares those locks and the qualified database. Run Laravel's scheduler and queue supervision independently; registering schedules does not start workers.

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
