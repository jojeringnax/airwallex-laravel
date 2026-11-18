# Payments receipts and recovery

Payments has a separate opt-in receiver, account context, endpoint secret, receipt table and queue routing. Billing's organization receiver keeps its existing contract. Configure the trusted account/credentials from [Payments](payments.md), then publish configuration and explicitly enable `airwallex.payments.webhook.enabled`. The default route is `POST /airwallex/payments/webhook`, named `airwallex.payments.webhook`; its path must differ from Billing's route. Register a matching `2026-08-21` webhook subscription with Airwallex. Default middleware is empty; if the application applies global CSRF checks, exempt only this provider receiver. Browser return endpoints still require application authorization.

```dotenv
AIRWALLEX_PAYMENTS_WEBHOOK_SECRET=externally-supplied-endpoint-secret
AIRWALLEX_PAYMENTS_WEBHOOK_QUEUE=payment-receipts
AIRWALLEX_PAYMENTS_WEBHOOK_QUEUE_CONNECTION=billing-database
AIRWALLEX_PAYMENTS_EVENTS_QUEUE=payment-events
AIRWALLEX_PAYMENTS_EVENTS_QUEUE_CONNECTION=billing-database
```

Connection names refer to existing Laravel queue connections. Null preserves the application default; queue names are independent. Provision jobs/failed-jobs/cache/lock tables in the application and run `php artisan queue:work billing-database --queue=payment-receipts,payment-events`. Invalid routing or enqueue failure leaves committed work recoverable. The selected deterministic profile qualifies database queues/cache/locks on the supported database; it establishes no Redis/SQS or deployed supervisor/topology claim.

SDK HMAC/timestamp verification uses exact raw bytes before decoding. Missing/invalid signatures or JSON return 400; invalid configuration/storage returns 503; 200 follows durable acceptance. Secrets/credentials never come from the event. Supported envelopes explicitly supply the configured `account_id`, `version`, timestamp and `data.object.id`. Missing/foreign/competing owners, `accountId` aliases, unsupported versions and malformed/direct-data wrappers are quarantined. Unknown event names are ignored. [Provider evidence](provider-contracts.md#payments-webhook-evidence) owns selected names and documentation conflicts; no actual account delivery is claimed.

Known Intent, Refund, Consent and Method notifications trigger canonical reads under the original account/customer. Event names and snapshots act as locators. A `succeeded` event can still canonically read PENDING, and a `settled` event can still read RECEIVED. [Provider-created Refunds](refunds.md#refunds-created-outside-the-package) can be discovered after canonical proof of an owned capture. Other unknown IDs require an exact recorded attempted creation and canonical original request, owner and intent proof, or separate explicit association. Recovery submits no financial writes and never dispatches `prepared` operations. Unproved outcomes keep their original identity and reservations.

Local offboarding blocks new commands, defaults and charge eligibility, while known-resource canonical reads and recovery of attempted original actions remain available. Consent/Method projection locks therefore permit historical reconciliation for an offboarded owner; command preparation/claim and financial reservation guards still require an enabled customer. Offboarding never clears local revocation or produces another browser secret.

`airwallex_payment_receipts` retains immutable context/event ID/digest/classification and the verified body encrypted at rest. Identical duplicates reuse one row; conflicting bodies quarantine that event ID and invalidate its active lease. Processing leases fence projection commits and compose with sweep guards. A worker interruption leaves pending/failed/expired work recoverable. Body retention defaults to seven days for terminal processed/ignored/quarantined rows; pending/failed work remains retained. Pruning preserves duplicate identity/digest; purged or conflicting bodies cannot be replayed. Restrict body/database/backups and preserve encryption keys according to application retention policy.

Canonical projections and `airwallex_payment_observations` commit together. After commit, `DispatchObservation` queues a delivery carrying only local ID/environment/account. `Events\PaymentObserved` exposes readonly `id`, `environment`, `accountId`, `resourceType`, `resourceId` and safe `payload`. Payloads contain strings/null for remote/status/amount/currency/captured amount, parent payment, method status/ID and stable `financial_identity` where applicable. Receipt outcomes also use this event; filter the resource type before accounting. No profile, raw body, secret or private URL is delivered.

Listeners deduplicate delivery IDs and separately reconcile financial identity/cumulative captured values. Identical canonical observations retain their delivery row; a crash after listener execution but before acknowledgement can redeliver that same ID. Accepted and settled observations of one Refund share `refund/REMOTE_ID`; they are not two refund credits. Partial captures can increase one Intent's cumulative total, so account for new confirmed amounts without duplicating earlier amounts. PENDING/review/action-required/failed/cancelled or unknown statuses never become payment completion by event naming. Laravel queued listeners have separate retries/failed-jobs: an acknowledged outbox means successful enqueueing, not that a queued listener finished. No event is a financial command.

Manual bounded reads/recovery use `airwallex:payments-recover`:

| Options | Behavior |
| --- | --- |
| `--family=intent\|refund\|instrument\|method\|operation\|receipt\|event\|dispute\|deposit` | One account-scoped family; `all` is default. `--limit=100` (1–1,000) applies per family. |
| `--after=LOCAL_ID` / `--id=LOCAL_ID` | Manual cursor or one row; requires one family. Output contains safe local IDs/classifications. |
| `--family=operation --id=ID --remote-id=PROVIDER_ID` | Operator-known locator for original attempted creation; canonical proof remains mandatory. |
| `--family=receipt --id=ID --replay` | Explicitly replay one retained eligible body. |
| `--family=prune --limit=100` | Bounded terminal body retention. |
| `--sweep --seconds=30 --limit=100` | Durable fair progress, requiring the independent [fair recovery foundation](https://github.com/jojeringnax/airwallex-laravel/pull/67). Same per-family budgets/high-water/failure/fencing contract as [Billing sweeps](operations.md#bounded-commands). |

The `payments_*` families join the shared sweep registry when the foundation and a Payments account are configured. `airwallex:payments-recover --sweep` also works in an account-only deployment without a Billing organization. [Native prerequisite #50](https://github.com/jojeringnax/airwallex-laravel/issues/50) owns fair progress; [#52](https://github.com/jojeringnax/airwallex-laravel/issues/52) owns the qualified queue profile. Manual recovery remains available without those optional sweep classes, and reports their absence if `--sweep` is requested.

Register the recurring commands through the application's configured scheduler entry point. For example, inside a `withSchedule` callback in `bootstrap/app.php`, where `$schedule` is an `Illuminate\Console\Scheduling\Schedule` instance:

```php
$schedule->command('airwallex:payments-recover --sweep --limit=100 --seconds=30')
    ->everyMinute()->withoutOverlapping();
$schedule->command('airwallex:payments-recover --family=prune --limit=100')
    ->daily()->withoutOverlapping();
```

Shared scheduler locks, database access, synchronized clocks, queue reservation/worker timeouts and supervision remain deployment prerequisites. Direct event recovery invokes listeners synchronously; receipt recovery enqueues stored jobs. Scheduling does not start workers. Monitor failed/expired leases, quarantines, unresolved operations, stale completed sweeps and undispatched observations. Provider sandbox delivery, client/wallet readiness and deployed-consumer qualification remain separate work.

## Superseded unknown creations

A consumer's new creation key cannot release an older unknown-ID creation. The consumer/operator must explicitly release that original operation if another attempt is authorized despite possible duplicates; no provider-absence claim is inferred. The [shared retry runbook](operations.md#retrying-an-unknown-creation) defines the encrypted audit, explicit `airwallex:allow-creation-retry --payments`, order/refund reservations and retained original request identity. Same-key mutation replay remains prohibited. A late signed original request locator produces a quarantined receipt and `PaymentObserved` classification `SUPERSEDED_CREATION_LOCATOR`, never a silently adopted current resource or financial completion. Monitor and service possible duplicates through explicitly authorized consumer workflows.

## Owned payment disputes

Subscribe to the selected-version `payment_dispute.*` events to track disputes on package-owned PaymentIntents. A canonical dispute links to an Intent directly or through a canonical Attempt read. The package verifies any supplied Attempt against the original Intent, requires an owned Intent in the configured account, and checks any supplied dispute customer against its local mapping. Unknown payments produce an ignored receipt. Contradictory account or payment ownership quarantines the receipt. The application handles dispute evidence, responses and business restrictions.

Read or recover through an application-authorized billable and its owned Intent.

```php
$payments = \Jojeringnax\AirwallexLaravel\Payments::for($authorizedBillable);
$dispute = $payments->reconcileDispute($ownedIntent, $providerDisputeId);

$page = $payments->disputes($ownedIntent, $fromUpdatedAt, $toUpdatedAt, cursor: $savedCursor);
foreach ($page['items'] as $dispute) {
    // Read $dispute->status, $dispute->stage, $dispute->due_at and $dispute->reason.
}
```

Use explicit timezone-bearing bounds and repeat a fixed time window until `nextCursor` is null. Start a new window with null and overlap the preceding window's boundary. Store each returned opaque cursor after processing that page and keep the bounds and page size fixed during pagination. This discovers missed disputes for each tracked Intent. Use `airwallex:payments-recover --family=dispute` to refresh known disputes. Older canonical snapshots cannot replace newer provider timestamps, and an equal timestamp with conflicting evidence stays retryable.

Filter `PaymentObserved` for `airwallex_payment_disputes`. Payloads expose the provider dispute ID, parent Intent and optional Attempt IDs, status, stage, exact amount and currency, response deadline, and provider update time. They share `payment_dispute/REMOTE_ID` across the lifecycle. The versioned canonical projection also exposes provider reason description, original code and type when supplied. The model encrypts the reason and omits it from serialization. Applications can use `stage` with the provider's preserved status to interpret `REQUIRES_RESPONSE`, `WON`, `LOST`, and other outcomes. [Provider evidence](provider-contracts.md#payment-disputes-and-global-account-deposits)

## Incoming Global Account deposits

Set `AIRWALLEX_PAYMENTS_DEPOSIT_GLOBAL_ACCOUNT_ID` to the receiving Global Account ID owned by the configured Payments account. Published configurations use `airwallex.payments.deposit_global_account_id`. Subscribe to `deposit.pending`, `deposit.settled`, `deposit.rejected` and `deposit.reversed` on the same account-level receiver. Configure deposit read permissions on the API key.

```php
$deposits = app(\Jojeringnax\AirwallexLaravel\Payments\Deposits::class);
$deposit = $deposits->reconcile($providerDepositId);
$page = $deposits->page($fromCreatedAt, $toCreatedAt, page: $savedPage ?? 0);

foreach ($page['items'] as $deposit) {
    $reference = $deposit->snapshot['reference'];
    $senderName = $deposit->snapshot['sender_name'];
    $senderCountry = $deposit->snapshot['sender_country'];
}
```

Authorize access at the operator or application service boundary. The service accepts only canonical bank transfers with the exact configured Global Account ID. Transfers from other sources and transfers without a receiving identifier are outside the feed. Those include DBS Hong Kong transfers for which Airwallex may omit the Global Account ID. The encrypted model snapshot retains the last supplied reference, sender name and country, with null for fields never supplied. Sender bank details stay out of the projection. `provider_created_at` and nullable `settled_at` retain the first confirmed creation and settlement times.

Use inclusive, timezone-bearing creation windows and continue with `nextPage` even when the filtered page is empty. Keep the window and page size fixed and persist progress after each successfully processed page. Begin each window at page 0 and repeat overlapping windows to recover missed new deposits. The service defaults to 100 results per page and accepts page sizes from 10 to 500. Repeating a page converges on the same deposit and observations. Schedule `airwallex:payments-recover --family=deposit --sweep` to refresh older known deposits because the provider's List filter is based on creation time.

Filter `PaymentObserved` for `airwallex_global_account_deposits`. Its payload carries `deposit/REMOTE_ID`, the receiving account, exact amount, currency, status, provider creation time and optional settlement time. Treat `SETTLED` as provider-confirmed funds and `REVERSED` as a separate change to that same receipt. `PENDING`, `REJECTED`, and unfamiliar statuses are insufficient to credit funds. A reversal signal stays retryable until a canonical read confirms it. Ledger posting, payment matching and application approval remain consumer responsibilities. [Provider evidence](provider-contracts.md#payment-disputes-and-global-account-deposits)
