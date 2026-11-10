# Security and sensitive billing data

The first-release support and maintenance policy is provisional in [compatibility](docs/compatibility.md). Apply current dependency security updates and run Composer's locked-graph audit; a version range alone is not an advisory-free guarantee.

Report a suspected vulnerability privately through the repository's GitHub Security reporting option when available. If no private reporting channel is available, open a minimal issue requesting a confidential contact without exploit details, credentials or customer data. Do not include API keys, webhook secrets, bearer tokens, client secrets, checkout/document URLs, receipt bodies or customer profiles in a public report.

Applications authorize the local billable before billing operations and link delivery. The package checks immutable environment/organization/customer/resource identity; it does not select an authentication guard or make application ownership decisions. High-level financial workflows require configured routing proof. Keep raw SDK access behind the application's own authorization boundary.

Preserve the Laravel encryption key for retained encrypted operation, checkout and receipt data; protect database files, backups and private evidence journals. Default model/DTO diagnostics omit protected payloads and URLs. Explicit snapshot/link access and direct database access remain sensitive. Custom model subclasses must retain identity restrictions, casts and hidden fields. See [usage](docs/usage.md) and [retention/offboarding](docs/operations.md#retention-replay-and-offboarding).

Deploy the signed webhook receiver with the selected version/context and durable workers/scheduler. SDK decoding has the documented native-token/duplicate-key provenance boundary; canonical ownership/correlation checks remain mandatory. Durable receipts/outbox and stable IDs support recovery, but applications must handle listener deduplication and Laravel queued-listener retries. Never replay an uncertain financial mutation or treat browser success as payment/access proof.

Sandbox/account observations and production financial validation are distinct from deterministic package checks. [Provider evidence](docs/provider-contracts.md) records the remaining account-dependent behavior and deliberate deferrals.
