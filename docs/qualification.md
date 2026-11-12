# Claim-to-evidence map

Use this map to review the first-release capabilities against the exact candidate's evidence. It names deterministic test classes in the matching repository checkout; runtime archives intentionally omit tests/tools. [Release instructions](release.md) produce the candidate/SDK revisions, artifact hashes, logs, native-consumer lock and per-file manifest. GitHub CI/checkpoint evidence owns actual run results; workflow configuration alone is not a passing qualification.

| Capability or failure boundary | Deterministic evidence |
| --- | --- |
| Lazy SDK/provider discovery, config cache, API version, credential rotation, array/file token cache | `FoundationTest`; native consumer boot/discovery/config-cache checks |
| SQLite/MySQL migrations/rollback, context/FK/identity constraints, configured subclasses, morph maps and integer/UUID/ULID keys | `PersistenceTest`, `CustomerTest`, `InvoiceTest`; native consumer migrate/rollback/reapply and configured model recipes |
| Exact customer create/get/link/sync, durable command/request identity and unknown outcomes | `CustomerTest`, `OperationTest`, `ConcurrencyTest` |
| Customer/name reservation, competing processes and import/create exclusion | File-backed two-process `ConcurrencyTest`; unique/FK constraints and interleaving workflow tests |
| Canonical full-page Subscription/items, exact quantities, stale/equal versions and atomic projection/outbox | `SubscriptionSyncTest`, `SubscriptionWorkflowTest`, `QuantityProofTest` |
| Frozen-clock trial/future/ACTIVE/UNPAID/CANCELLED/unknown access, scheduled bounds and current/history separation | `AccessPolicyTest`, `SubscriptionSyncTest`, `SubscriptionWorkflowTest` |
| Direct/multi-item builder, stable dates, owned source/routing and safe mutation preflight | `SubscriptionWorkflowTest`; installed direct/source/change recipes |
| Hosted checkout/setup, redirect privacy, completion/response ordering and verified result ownership | `CheckoutWorkflowTest`, `WebhookProcessingTest`; installed checkout/setup recipes |
| Exact item financial controls, swap/delete/add, source recovery and cancellation | `SubscriptionWorkflowTest`, `QuantityProofTest`; installed item/source/ending/cancel recipes |
| Known acknowledgement versus observable fulfillment; no unknown-write replay or borrowed addition proof | `SubscriptionWorkflowTest`, `OperationTest`, `RecoveryCommandTest` |
| Exact signed bytes/millisecond boundaries, rejected configuration, encrypted receipt acceptance, duplicate/conflicting event identity | `WebhookReceiptTest`, including actual two-process SQLite/MySQL receiver races |
| Direct/wrapped events, immutable remote-generation targeting, unknown resource boundaries and canonical invoice/setup handling | `WebhookProcessingTest`, `CheckoutWorkflowTest` |
| Lease loss, restart/finalization failure, offboarding deletion and transactional deferred events | `WebhookReceiptTest`, `WebhookProcessingTest`, `RecoveryCommandTest` |
| After-commit outbox delivery/rollback, immutable observation IDs, queue/listener failure and stable retry ID | `OutboxTest`, `QueueRecoveryTest` |
| Actual database queue jobs/worker invocations, per-job scope reset, receipt/event restart phases and queue outage commands | File-backed `QueueRecoveryTest`, across sync/database queues × array/file caches |
| Explicit billing database connection with a different application default; database token expiry/credential rotation, database locks and failed queued-listener ownership | `QueueRecoveryTest`; installed-archive `consumer-check.php` database job/cache/lock journey and real Laravel worker invocations |
| Bounded context/cursor commands, strict candidate selectors, canonical recovery and terminal-only pruning | `RecoveryCommandTest`, `QueueRecoveryTest`, `WebhookReceiptTest` |
| Owned Invoice/line paging, exact money, nullable observed links, configured/current owner checks and access after termination | `InvoiceTest`; installed invoice recipe and exact-money assertion |
| Every shipped consumer recipe, deny-network real SDK, installed runtime byte equality and Packagist SDK provenance | `tools/qualify-candidate.py`, candidate-pinned consumer helpers and `consumer-recipes.json` |
| Artifact exclusions, path/symlink/duplicate boundaries, required runtime files and pinned validation tooling | `tools/test_qualify_candidate.py`; exact Git archive manifest and installed-file comparison |
| Explicit Sandbox opt-in, production/missing-input rejection, redaction and no implicit SDK/bootstrap/credential-file loading | `SandboxPreflightTest`; standalone preflight and [unexecuted journey record](sandbox.md) |

## Matrix and artifacts

The candidate support boundary is in [compatibility](compatibility.md): 64-bit PHP 8.3/8.4/8.5, lowest/latest security-aware dependency resolutions, Laravel 12.69+/13, SQLite >=3.43.2 and MySQL >=8.4.11 <8.5 with the documented InnoDB prerequisites, array/file caches and sync/database queues. Each of the 24 PHP/framework/database/dependency cells runs the package suite, style/static checks, dependency audit, artifact-boundary tests and exact-commit native-consumer installation/recipes. The queue/cache cross-product executes inside each package suite. Record the actual engine, framework, dependency and SDK versions from each cell rather than inventing unavailable combinations.

Actual two-process receiver/reservation, duplicate-command, execute-status and stale-generation tests and worker lease/restart/interleaving tests establish the named boundaries. The database-queue worker restarts are real Laravel worker invocations and scope resets in the test process; they are not an OS-process-kill or Redis/SQS qualification. Unqualified database engines, older SQLite, other cache/queue drivers and unsupported PHP/framework majors remain outside this first-release claim.

The external candidate bundle records source commit, archive SHA256, runtime/tooling manifests, Composer version/hash, exact SDK source/dist and declared constraint, dependency mode, native consumer graph and each executed recipe. The baseline SDK is released v0.1.1; adoption transaction profiles explicitly select released v0.1.2 at the [documented pin](compatibility.md). There is no sibling dependency. Keep 24 development lock/version artifacts and 24 native-consumer bundles with the final passing workflow run. A source change or failed required gate requires a new truthful qualification result.

## Decisions and evidence still distinct

Accepted policies [#10](https://github.com/jojeringnax/airwallex-laravel/issues/10), [#34](https://github.com/jojeringnax/airwallex-laravel/issues/34), [#39](https://github.com/jojeringnax/airwallex-laravel/issues/39) and [#40](https://github.com/jojeringnax/airwallex-laravel/issues/40) define the implemented launch and billing choices. [#37](https://github.com/jojeringnax/airwallex-laravel/issues/37) records SDK representation limits. These accepted package policies do not establish unobserved provider capabilities.

The [provider reference](provider-contracts.md#evidence-gaps-and-verification-actions) records fresh raw-schema extraction restrictions, missing native-token provenance, optional routing availability and other account facts. [Sandbox evidence](sandbox.md#current-evidence) explicitly leaves the Laravel account-dependent journeys unexecuted; deterministic synthetic fixtures do not establish activation, actual deliveries, timing, dunning, settlement, link regeneration or remote cleanup. Under the accepted evidence policy, tested guards plus that exact gap record satisfy the optional Sandbox contribution without a passing provider claim.

Validated implementation and reviewable PRs remain distinct from delivery to the default branch. A local `0.1.0-RC1` descriptor is an unpublished review artifact. The release tracker and project record merge and publication status; no tag, merge, production deployment or financial operation is implied by qualification.

## Full-provider adoption evidence

The new programme's required outcomes are in [consumer scope](scope.md#full-provider-adoption-contract). Use the explicit [adoption track driver](release.md#adoption-tracks-and-sdk-selection); baseline first-release evidence does not qualify the new interfaces or account journeys.

| Adoption boundary | Deterministic installed evidence |
| --- | --- |
| Released SDK transaction version, exact source/dist, package manifest, advisory-aware lock and no sibling path repository | `tools/qualify-candidate.py --sdk-version 0.1.2`; SDK resolves from Packagist and package runtime bytes must match the exact archive |
| Cashier and provider-qualified Airwallex current/history/builders/setup/invoices/cancel/recovery on the same model, legacy Stripe refund routing | Reused `CashierCoexistenceTest` against the installed package with a real isolated Cashier dependency |
| Saved-original finite/indefinite restoration and due-generation/item/lateness guards | Installed restoration/transition recipes in `tools/subscription-consumer-check.php`; substantive service tests remain in their implementation PRs |
| Application authorization before commands, reservations and provider calls; persisted allowed command, catalogue aliases and exact minor-unit/exponent conversion, including weak PHP callers | Installed `subscription-consumer-check.php` executes the same application-owned Laravel Gate path with allowed and unrelated actors: denial changes no application command, package operation, subscription or name reservation and sends no SDK request. `adoption-adapters.php` and future/trial assertions cover exact amounts; zero/excess/unknown precision never creates an artificial payment |
| Owned SDK invoice transaction reads, partial payments, zero-cash PAID invoices and non-payment classifications | Installed `invoice-transactions.php`; released-SDK `InvoiceTransactionsTest` reused against the actual selected database backend |
| Distinct deliveries and Billing/Payments references to one canonical intent, independent allocation and clearing configuration | Synthetic application ledger callback with a unique financial identity, separately supplied allocation proof and contradictory-allocation refusal |

The driver records each executed recipe, selected backend/framework/dependency graph and component commits. CI qualifies the exact PR head or selected main revision with released SDK v0.1.2 from Packagist, without a synthetic package merge or SDK repository override. The lowest/latest native consumers explicitly select that optional-feature version; development package graphs still resolve the declared `^0.1.1` baseline normally. Subscription and Payments tracks share that single package/SDK/dependency graph. A later source revision needs its own qualification. Current results and precise failing/pending checks stay in the tracker/PR evidence, not this reference.

Synthetic allocation attestations demonstrate application deduplication and refusal behavior; they establish no provider exclusivity, settlement, accounting deployment or migration. Restoration uses the supported, revision-specific provider combinations in [the provider reference](provider-contracts.md#renewal-restoration). ACTIVE billing holidays and exact boundary guarantees stay unavailable where their provider proof is missing. The [subscription Sandbox adoption gate](https://github.com/jojeringnax/airwallex-laravel/issues/63) and separate Payments journey remain unexecuted without scoped approval, external credentials, verified ownership and cleanup controls. Final tagged SDK/package installation qualification remains a library release gate. Application client/accounting implementation and cutover require their own integration checks.
