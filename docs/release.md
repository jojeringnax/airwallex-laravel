# Reproducing a review candidate

Use this reference to prepare or install an exact first-release review archive. [Compatibility](compatibility.md#release-evidence) defines the release gates; [provider evidence](provider-contracts.md) distinguishes deterministic checks from account observations. These tools prepare local artifacts and do not merge, tag, publish or perform Airwallex financial operations.

Run from a complete repository checkout of the reviewed candidate, with Git, Python 3, a supported PHP runtime and the verified [Composer 2.11.2 PHAR](https://getcomposer.org/download/2.11.2/composer.phar). Compare the PHAR against the [official SHA256](https://getcomposer.org/download/2.11.2/composer.phar.sha256sum); the qualifier also rejects a different hash. Runtime archives intentionally omit the repository's development tools/tests.

```sh
php /absolute/path/composer-2.11.2.phar validate --strict
php /absolute/path/composer-2.11.2.phar check
php /absolute/path/composer-2.11.2.phar audit --locked
python3 tools/test_qualify_candidate.py
python3 tools/qualify-candidate.py \
  --candidate FULL_40_CHARACTER_COMMIT \
  --composer /absolute/path/composer-2.11.2.phar \
  --laravel 13 --database sqlite --dependencies latest \
  --workspace build/qualification \
  --output build/candidates
```

Replace the commit placeholder with the reviewed exact commit. The qualifier resolves it once, archives that immutable commit and records its identity, SHA256, runtime file hashes, tooling provenance, exact dependency lock/versions, commands and results. The tool fixes child-process timezone to UTC so Git ZIP timestamps do not vary with the host timezone. It rejects credential-file paths such as `.env*`, caches, development-only paths, symlinks/traversal, missing tracked runtime files and repository/path SDK overrides. Review tracked contents for embedded credentials before archiving; path checks do not scan arbitrary source/document contents for secrets. By default the candidate's declared SDK dependency resolves from Packagist; the installed source/dist provenance is recorded. The explicit development mode below instead archives one exact SDK commit and keeps tagged-release qualification pending.

Every run creates new private directories rather than overwriting existing work. Public Composer dependency downloads use an isolated Composer home/cache without inheriting provider credentials or Composer auth. The fresh native Laravel consumer installs the exact local ZIP, discovers the provider, caches configuration, applies/rolls back/reapplies migrations, and executes every shipped recipe with the real SDK and a synthetic transport that denies unexpected requests. It compares all installed runtime bytes against the archive manifest. `--archive-only` reports only `archive_verified_only`; it cannot establish consumer qualification.

The final JSON output names an `evidence-*/evidence.json` file. Its directory also contains `airwallex-laravel-FULL_COMMIT.zip`, `archive-manifest.json`, `package-repository.json`, `tooling-manifest.json`, `consumer-recipes.json`, `consumer.lock` and `dependency-versions.json`.

Keep the emitted evidence JSON, archive, `archive-manifest.json`, `package-repository.json`, consumer lock and dependency versions together with the 24 PHP/framework/database/dependency CI job locks. The descriptor's provisional `0.1.0-RC1` identifies a local review candidate, not a Git tag or published version. Use `--dependencies lowest` for the audited minimum graph and `--dependencies latest` for the current graph. CI runs both native-consumer resolutions for Laravel 12/13 and SQLite/MySQL on PHP 8.3, 8.4 and 8.5 at the exact PR head and retains their bundles alongside the development locks. A local passing consumer check does not replace that full matrix, backend journeys, independent reviews or sandbox evidence policy.

For a MySQL native consumer, precreate a dedicated empty schema named `airwallex_test_consumer` (or another `airwallex_test_` suffix), then pass `--database mysql --laravel 12` or `13`. `AIRWALLEX_QUALIFY_MYSQL_DATABASE` selects that disposable schema; `AIRWALLEX_TEST_MYSQL_HOST`, `PORT`, `USER`, `PASSWORD` supply only its test connection (defaults 127.0.0.1:33079/root/empty). Use a separate schema from package tests. Qualification isolates these values from provider credentials and excludes the consumer environment file from artifacts. Reuse only a fresh empty schema, since migrations/recipes deliberately leave evidence in it.

## Install the generated archive in another application

The generated package descriptor contains the exact archive's local file URL, distribution reference and checksum. Keep the archive at that path, or deliberately update only its location after copying the identical verified ZIP. In an authorized test Laravel application, add the descriptor as one Composer package repository and require the explicit candidate:

```sh
composer config repositories.airwallex-candidate "$(cat /absolute/path/package-repository.json)"
composer require jojeringnax/airwallex-laravel:0.1.0-RC1 --with-all-dependencies
php artisan package:discover
php artisan vendor:publish --tag=airwallex-config
php artisan migrate
php artisan config:cache
```

Inspect the dependency change in that application before adopting it. Follow the [consumer guide](usage.md) for real application configuration; generated qualification credentials and provider responses are synthetic. The descriptor intentionally has no fallback source/path repository. Development SDK selection must use the explicit archived-commit mode below, never a sibling path repository.

The final candidate's external evidence records its exact source/SDK revisions, passing checks, remaining upstream limitations, unexecuted provider scenarios and artifact hashes. A failed required check remains incomplete; a new source commit requires qualification of that commit. Keep publication, tags, merging and production operations separate from local preparation.

## Adoption tracks and an unreleased SDK

New Billing transaction inspection requires the SDK extension in [SDK PR #160](https://github.com/jojeringnax/airwallex-php-sdk/pull/160). Qualify its exact reviewed commit independently of a future tag:

```sh
python3 tools/qualify-candidate.py \
  --candidate FULL_PACKAGE_COMMIT \
  --composer /absolute/path/composer-2.11.2.phar \
  --sdk-repo /absolute/read-only/sdk-repository \
  --sdk-candidate FULL_REVIEWED_SDK_COMMIT \
  --track subscriptions --laravel 13 --database sqlite --dependencies latest
```

The SDK repository is read only: the tool exports its selected Git commit into a ZIP, records hashes/manifests and installs that archive with a visibly unreleased `0.1.2-dev` descriptor. The package's distributed `^0.1.1` constraint is preserved; the isolated consumer explicitly selects the development descriptor. Exact source/dist references and installed SDK/package bytes must match. A version label or sibling working tree never substitutes for those checks. Qualification against the final tagged SDK and published package remains pending.

`--track baseline` exercises the released recipe set. `subscriptions` adds the adoption subscription/accounting adapters and real optional Cashier fixture; `payments` adds its account-owned helper; `all` runs both against the same package/SDK/dependency graph. The driver requires the union of actually executed recipe names to match every shipped example. An omitted or unsupported track cannot be reported as a passing complete candidate. Each helper is extracted from the same selected package commit; a local uncommitted helper cannot qualify another candidate. The optional Cashier/Testbench/PHPUnit dependencies exist only in that disposable development consumer.

Archive, migrate/rollback/reapply, config-cache and installed recipes run without provider credentials. The SQLite/MySQL selection is an explicit synthetic qualification profile; each application selects and verifies its own supported deployment profile. Passing this tool does not qualify an application deployment, browser clients, an application ledger, provider eligibility or an account journey. Keep `subscriptions-recipes.json`, `payments-recipes.json` when selected, the real-Cashier log and any precise external blockers with the evidence bundle.

## First Packagist registration and updates

**[Publish for the first time →](https://packagist.org/packages/submit)**

After the release tracker’s required checks pass and the release tag is published, sign in to Packagist, submit `https://github.com/jojeringnax/airwallex-laravel`, and confirm the package. Browser submission needs no Main API Token in GitHub. Verify that the resulting [package page](https://packagist.org/packages/jojeringnax/airwallex-laravel) lists the intended tag/source revision.

The [Update Packagist action](https://github.com/jojeringnax/airwallex-laravel/actions/workflows/packagist.yml) runs on published GitHub releases and also offers **Run workflow** for a manual refresh after registration. It uses repository secrets `PACKAGIST_USERNAME` and the **Safe** `PACKAGIST_API_TOKEN`, sent in the recommended Authorization header. It only refreshes the fixed registered repository; it does not create a package, tag or release. [Packagist’s API contract](https://packagist.org/apidoc) permits Safe-token updates and requires Main only for API-based first registration.
