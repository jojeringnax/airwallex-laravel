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
| Bounded context/cursor commands, strict candidate selectors, canonical recovery and terminal-only pruning | `RecoveryCommandTest`, `QueueRecoveryTest`, `WebhookReceiptTest` |
| Owned Invoice/line paging, exact money, nullable observed links, configured/current owner checks and access after termination | `InvoiceTest`; installed invoice recipe and exact-money assertion |
| Every shipped consumer recipe, deny-network real SDK, installed runtime byte equality and Packagist SDK provenance | `tools/qualify-candidate.py`, candidate-pinned consumer helpers and `consumer-recipes.json` |
| Artifact exclusions, path/symlink/duplicate boundaries, required runtime files and pinned validation tooling | `tools/test_qualify_candidate.py`; exact Git archive manifest and installed-file comparison |
| Explicit Sandbox opt-in, production/missing-input rejection, redaction and no implicit SDK/bootstrap/credential-file loading | `SandboxPreflightTest`; standalone preflight and [unexecuted journey record](sandbox.md) |

## Matrix and artifacts

The candidate support boundary is in [compatibility](compatibility.md): 64-bit PHP 8.3/8.4/8.5, lowest/latest security-aware dependency resolutions, Laravel 12.69+/13, SQLite >=3.43.2 and MySQL >=8.4.11 <8.5 with the documented InnoDB prerequisites, array/file caches and sync/database queues. Each of the 24 PHP/framework/database/dependency cells runs the package suite, style/static checks, dependency audit, artifact-boundary tests and exact-commit native-consumer installation/recipes. The queue/cache cross-product executes inside each package suite. Record the actual engine, framework, dependency and SDK versions from each cell rather than inventing unavailable combinations.

Actual two-process receiver/reservation, duplicate-command, execute-status and stale-generation tests and worker lease/restart/interleaving tests establish the named boundaries. The database-queue worker restarts are real Laravel worker invocations and scope resets in the test process; they are not an OS-process-kill or Redis/SQS qualification. Unqualified database engines, older SQLite, other cache/queue drivers and unsupported PHP/framework majors remain outside this first-release claim.

The external candidate bundle records source commit, archive SHA256, runtime/tooling manifests, Composer version/hash, exact SDK source/dist and declared constraint, dependency mode, native consumer graph and each executed recipe. The SDK is released v0.1.1 at the documented pin; there is no sibling dependency. Keep 24 development lock/version artifacts and 24 native-consumer bundles with the final passing workflow run. A source change or failed required gate requires a new truthful qualification result.

## Decisions and evidence still distinct

Accepted policies [#10](https://github.com/jojeringnax/airwallex-laravel/issues/10), [#34](https://github.com/jojeringnax/airwallex-laravel/issues/34), [#39](https://github.com/jojeringnax/airwallex-laravel/issues/39) and [#40](https://github.com/jojeringnax/airwallex-laravel/issues/40) define the implemented launch and billing choices. [#37](https://github.com/jojeringnax/airwallex-laravel/issues/37) records SDK representation limits. These accepted package policies do not establish unobserved provider capabilities.

The [provider reference](provider-contracts.md#evidence-gaps-and-verification-actions) records fresh raw-schema extraction restrictions, missing native-token provenance, optional routing availability and other account facts. [Sandbox evidence](sandbox.md#current-evidence) explicitly leaves the Laravel account-dependent journeys unexecuted; deterministic synthetic fixtures do not establish activation, actual deliveries, timing, dunning, settlement, link regeneration or remote cleanup. Under the accepted evidence policy, tested guards plus that exact gap record satisfy the optional Sandbox contribution without a passing provider claim.

Validated implementation and reviewable PRs remain distinct from delivery to the default branch. A local `0.1.0-RC1` descriptor is an unpublished review artifact. The release tracker and project retain open awaiting-merge work; no tag, merge, production deployment or financial operation is implied by qualification.
