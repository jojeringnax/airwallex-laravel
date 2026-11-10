# Compatibility and release policy

This policy is **provisional**, tracked in open [question #10](https://github.com/jojeringnax/airwallex-laravel/issues/10). It permits implementation under the delegated decision policy; it is not maintainer approval or a passing qualification report.

| Concern | Candidate target |
| --- | --- |
| Package / namespace | `jojeringnax/airwallex-laravel` / `Jojeringnax\AirwallexLaravel` |
| License / numbering | MIT; initial candidate 0.1.0; semantic versioning |
| PHP | 64-bit 8.3, 8.4 and 8.5 (`>=8.3 <8.6`) |
| Framework | Laravel 12.69+ (`^12.69`), Testbench 10 |
| Database | SQLite >=3.35, foreign keys enabled, binary case-sensitive opaque identities; file-backed concurrency qualification |
| SDK | Packagist `jojeringnax/airwallex-php-sdk:^0.1.1`; v0.1.1 source `bd11abb67e2e5761b86a9ca9d4a1b7993900de6e` |
| HTTP / cache | Guzzle `^7.15.2`, PSR7 `^2.13` PSR-18/17; Laravel configured cache via PSR-16 v3; no token or HTTP behavior duplicated |
| Development / qualification | PHPUnit `^11.5.50`; Composer 2.11.2 with advisory blocking and locked-graph audit |
| Queue | Laravel configured queue; database receipts remain recoverable after dispatch/worker failure |
| Package / billable keys | ULID package IDs; integer/UUID/ULID morph keys stored exactly as strings |
| Provider time / quantity | Timezone-bearing text preserves precision/offset; SDK-decoded decimal strings, never floats |

SQLite narrows initial qualification without inventing untested cross-engine collation/locking guarantees. More engines/framework majors require actual migration, concurrency and dependency evidence. The matrix is a target until every allowed combination passes; narrow constraints or record a new question if evidence fails. [Laravel support](https://laravel.com/docs/12.x/releases) and [package mechanics](https://laravel.com/docs/12.x/packages) inform this choice.

Fresh consumers obtain the tagged SDK through Packagist, with no path repository/sibling checkout. Development locks capture exact resolutions; package archives omit that root lock. Test lowest/latest allowed dependency sets on each PHP line, including real registration, config caching, migrations, executable recipes and deterministic failures. Qualification uses Composer 2.11.2 with its default security-aware resolver: lowest means the lowest permitted graph that passes current advisory policy, not an insecure historical graph. The direct floors above exclude known affected Laravel/Guzzle/PHPUnit versions; transitive dependencies still require resolution and audit. This refinement remains provisional under [the support question](https://github.com/jojeringnax/airwallex-laravel/issues/10#issuecomment-6768780428).

Run `composer validate --strict`, `composer check` (deterministic tests, style and static analysis), and `composer check:dependencies` (locked-graph advisory audit) in the repository. CI runs each lowest/latest graph on PHP 8.3, 8.4 and 8.5 and retains its lock and exact versions. Record actual results for the candidate, not only workflow configuration; new advisory findings require remediation or a separately recorded decision rather than suppressing the audit.

During 0.x, breaking public changes require a new minor, release notes and migration guidance. Patches preserve documented public behavior. Maintain only the latest minor; 1.0 readiness and any broader maintenance promise require a separate decision. Public surface includes documented methods/parameter names, model fields, configuration, payload semantics and exceptions.

## Release evidence

Mandatory gates: verified provider/SDK contracts; accepted scope implemented; meaningful deterministic real-SDK tests and independent review; full declared runtime/database/cache/queue/lowest-latest checks; actual file-database races; executable consumer recipes; clean artifact installation/discovery/config cache/migrations; archive exclusions, license/security/release notes; reproducible exact candidate and limitations. Unimplemented requirements or failing required validation remain incomplete.

Sandbox journeys are supplemental under this provisional policy. Run only with explicit sandbox selection, external credentials, authorized organization/entity/collector/source mappings, resource ownership and cleanup controls. Otherwise verify guards and list exact unexecuted journeys. Deterministic fixtures establish package behavior, never provider entitlement, charge success, settlement or production readiness. [Provider evidence](provider-contracts.md) owns account prerequisites and unknown behavior.

A candidate may retain open non-blocking questions, explicitly listed with the implemented assumptions. Awaiting-merge implementation remains open. Preparing/pushing branches and PRs does not authorize merging, default-branch pushes, tags or release publication.
