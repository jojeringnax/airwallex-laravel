#!/usr/bin/env python3
"""Qualify an exact Git archive in a fresh native Laravel consumer; never call Airwallex."""

from __future__ import annotations

import argparse
from collections.abc import Callable
import datetime as dt
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import zipfile


VERSION = "0.1.0-RC1"
PACKAGE = "jojeringnax/airwallex-laravel"
SDK = "jojeringnax/airwallex-php-sdk"
# Official 2.11.2 PHAR, independently verified during foundation qualification.
# https://getcomposer.org/download/2.11.2/composer.phar.sha256sum
COMPOSER_SHA256 = "dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028"
RUNTIME_PREFIXES = ("src/", "config/", "database/migrations/", "routes/", "examples/")
ALLOWED_PREFIXES = (*RUNTIME_PREFIXES, "docs/")
ALLOWED_FILES = {"composer.json", "LICENSE", "README.md", "SECURITY.md", "CHANGELOG.md", "UPGRADING.md"}
EXCLUDED_PARTS = {".git", ".github", ".agents", "vendor", "node_modules", "tests", "tools", "build", ".phpstan-cache", ".phpunit.cache", "__pycache__"}
EXCLUDED_FILES = {".gitattributes", ".gitignore", "AGENTS.md", "CLAUDE.md", "composer.lock", "pint.json", "phpunit.xml", "phpstan.neon", ".phpunit.result.cache", ".DS_Store"}


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def inspect_archive(archive: Path, required: set[str]) -> tuple[dict, dict]:
    """Check names before reading contents, so excluded credential files are never read."""
    manifest: dict[str, dict] = {}
    with zipfile.ZipFile(archive) as zipped:
        for entry in zipped.infolist():
            name = entry.filename
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or "\\" in name or "\x00" in name:
                raise ValueError("Archive contains an unsafe path.")
            if entry.is_dir():
                continue
            if name in manifest or stat.S_ISLNK(entry.external_attr >> 16):
                raise ValueError("Archive contains duplicate paths or symbolic links.")
            if any(part in EXCLUDED_PARTS or part.startswith(".env") for part in path.parts) or path.name in EXCLUDED_FILES:
                raise ValueError(f"Archive contains an excluded development/sensitive path: {name}")
            if name not in ALLOWED_FILES and not name.startswith(ALLOWED_PREFIXES):
                raise ValueError(f"Archive path needs an explicit distribution review: {name}")
            data = zipped.read(entry)
            manifest[name] = {"sha256": digest(data), "bytes": len(data)}
        missing = required - manifest.keys()
        if missing:
            raise ValueError("Archive omitted required tracked runtime files: " + ", ".join(sorted(missing)))
        metadata = json.loads(zipped.read("composer.json"))
    if metadata.get("name") != PACKAGE or not isinstance(metadata.get("require", {}).get(SDK), str):
        raise ValueError("Candidate must declare the distributable SDK dependency.")
    if "repositories" in metadata:
        raise ValueError("Candidate repository overrides require explicit distribution review.")
    return manifest, metadata


def package_repository(metadata: dict, archive: Path, commit: str) -> dict:
    # Only dependency runtime metadata is needed. No dev autoloading/scripts or fallback source checkout.
    package = {key: metadata[key] for key in ("name", "description", "type", "license", "require", "autoload", "extra") if key in metadata}
    package.update(version=VERSION, dist={"type": "zip", "url": archive.as_uri(), "reference": commit, "shasum": hashlib.sha1(archive.read_bytes()).hexdigest()})
    return {"type": "package", "package": package}


def extract_tooling(read_committed_file: Callable[[str], bytes], destination: Path, running_qualifier: bytes) -> dict[str, str]:
    """Pin runtime helpers to candidate blobs, and reject a different qualification driver."""
    files = {name: read_committed_file("tools/" + name) for name in ("qualify-candidate.py", "consumer-check.php", "consumer-fixtures.php")}
    if files["qualify-candidate.py"] != running_qualifier:
        raise ValueError("Running qualifier differs from the selected candidate commit; execute that commit's qualifier.")
    destination.mkdir()
    for name, data in files.items():
        (destination / name).write_bytes(data)
    return {name: digest(data) for name, data in files.items()}


class Qualification:
    def __init__(self, args: argparse.Namespace):
        self.args = args
        self.repo = args.repo.resolve()
        self.workspace: Path | None = None
        self.output: Path | None = None
        self.records: list[dict] = []
        self.evidence: dict = {"status": "incomplete", "provisional_version": VERSION, "candidate_input": args.candidate, "dependencies": args.dependencies, "laravel_major": args.laravel, "database": args.database, "financial_network": "disabled; synthetic PSR-18 transport only", "tool_runtime": {"python": sys.version, "executable": sys.executable, "platform": sys.platform}, "commands": self.records}
        # Do not inherit provider credentials, Composer auth, or application configuration.
        self.env = {key: os.environ[key] for key in ("PATH", "SYSTEMROOT", "TMPDIR") if key in os.environ}
        self.env.update({"TZ": "UTC", "GIT_TERMINAL_PROMPT": "0", "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": os.devnull, "COMPOSER_NO_INTERACTION": "1", "COMPOSER_ROOT_VERSION": "1.0.0"})

    def command(self, name: str, argv: list[str], cwd: Path, *, timeout: int = 600) -> str:
        if self.output is None:
            result = subprocess.run(argv, cwd=cwd, env=self.env, capture_output=True, text=True, timeout=timeout)
            if result.returncode:
                raise RuntimeError(f"Preflight command failed: {name}")
            return result.stdout.strip()
        log = self.output / f"{len(self.records) + 1:02d}-{name}.log"
        record = {"name": name, "argv": argv, "cwd": str(cwd), "log": str(log), "status": "running"}
        self.records.append(record)
        self.save()
        print(json.dumps({"step": name, "status": "running"}), flush=True)
        started = time.monotonic()
        with log.open("w") as stream:
            try:
                result = subprocess.run(argv, cwd=cwd, env=self.env, stdout=stream, stderr=subprocess.STDOUT, timeout=timeout)
            except subprocess.TimeoutExpired:
                record.update(status="timeout", seconds=round(time.monotonic() - started, 3))
                self.save()
                raise RuntimeError(f"Qualification timed out: {name}; inspect {log}") from None
        record.update(status="passed" if result.returncode == 0 else "failed", exit_code=result.returncode, seconds=round(time.monotonic() - started, 3), log_sha256=digest(log.read_bytes()))
        self.save()
        if result.returncode:
            raise RuntimeError(f"Qualification failed: {name}; inspect {log}")
        return log.read_text().strip()

    def save(self) -> None:
        if self.output is not None:
            write_json(self.output / "evidence.json", self.evidence)

    def safe_output_base(self, base: Path) -> Path:
        base = base.resolve()
        if base == self.repo or self.repo in base.parents:
            relative = base.relative_to(self.repo)
            result = subprocess.run(["git", "check-ignore", "--quiet", "--", str(relative)], cwd=self.repo, env=self.env)
            if result.returncode != 0:
                raise ValueError("Workspace/output inside the repository must be ignored (for example build/qualification).")
        base.mkdir(mode=0o700, parents=True, exist_ok=True)
        return base

    def run(self) -> None:
        composer = self.args.composer.resolve()
        if digest(composer.read_bytes()) != COMPOSER_SHA256:
            raise ValueError("Composer PHAR does not match the verified 2.11.2 SHA256.")
        php = shutil.which(self.args.php)
        if php is None:
            raise ValueError("PHP executable was not found.")
        commit = self.command("resolve-commit", ["git", "rev-parse", "--verify", "--end-of-options", self.args.candidate + "^{commit}"], self.repo)
        if re.fullmatch(r"[0-9a-f]{40}", commit) is None:
            raise ValueError("Candidate did not resolve to one exact Git commit.")
        workspace_base = self.safe_output_base(self.args.workspace)
        self.workspace = Path(tempfile.mkdtemp(prefix=f"candidate-{commit[:12]}-", dir=workspace_base))
        output_base = self.safe_output_base(self.args.output) if self.args.output else self.workspace
        self.output = Path(tempfile.mkdtemp(prefix="evidence-", dir=output_base))
        self.env.update({"COMPOSER_HOME": str(self.workspace / "composer-home"), "COMPOSER_CACHE_DIR": str(self.workspace / "composer-cache")})
        for path in (self.workspace / "composer-home", self.workspace / "composer-cache"):
            path.mkdir(mode=0o700)
        version = self.command("composer-version", [php, str(composer), "--version", "--no-ansi"], self.repo)
        if not re.search(r"Composer version 2\.11\.2\b", version):
            raise ValueError("Qualification requires Composer 2.11.2.")
        self.evidence.update(candidate_commit=commit, workspace=str(self.workspace), output=str(self.output), started_at=dt.datetime.now(dt.timezone.utc).isoformat(), composer={"version": version, "sha256": COMPOSER_SHA256})
        self.save()
        archive = self.output / f"airwallex-laravel-{commit}.zip"
        self.command("git-archive", ["git", "archive", "--format=zip", f"--output={archive}", commit], self.repo)
        tree = subprocess.run(["git", "ls-tree", "-r", "-z", "--name-only", commit], cwd=self.repo, env=self.env, capture_output=True, check=True).stdout
        tracked = {path.decode("utf-8") for path in tree.split(b"\0") if path}
        required = {"composer.json", "LICENSE", "README.md", "config/airwallex.php"} | {path for path in tracked if path.startswith(RUNTIME_PREFIXES)}
        manifest, metadata = inspect_archive(archive, required)
        manifest_path = self.output / "archive-manifest.json"
        write_json(manifest_path, manifest)
        repository = package_repository(metadata, archive, commit)
        write_json(self.output / "package-repository.json", repository)
        self.evidence.update(archive={"path": str(archive), "sha256": digest(archive.read_bytes()), "files": len(manifest), "manifest_sha256": digest(manifest_path.read_bytes())}, sdk_constraint=metadata["require"][SDK])
        if self.args.archive_only:
            self.evidence["status"] = "archive_verified_only"
            self.save()
            return
        def committed_tool(path: str) -> bytes:
            argv = ["git", "show", f"{commit}:{path}"]
            result = subprocess.run(argv, cwd=self.repo, env=self.env, capture_output=True, timeout=30)
            self.records.append({"name": "candidate-tooling-" + Path(path).name, "argv": argv, "cwd": str(self.repo), "exit_code": result.returncode, "status": "passed" if result.returncode == 0 else "failed", "blob_sha256": digest(result.stdout) if result.returncode == 0 else None})
            self.save()
            if result.returncode:
                raise ValueError("Full qualification requires validation tooling in the selected candidate commit: " + path)
            return result.stdout
        helper_dir = self.workspace / "qualification-tools"
        helper_manifest = extract_tooling(committed_tool, helper_dir, Path(__file__).read_bytes())
        write_json(self.output / "tooling-manifest.json", helper_manifest)
        self.evidence["tooling"] = {"commit": commit, "source": "exact candidate Git blobs", "running_qualifier_matches_commit": True, "manifest_sha256": digest((self.output / "tooling-manifest.json").read_bytes()), "note": "Helpers are extracted from the candidate commit; export-ignore keeps them out of the runtime archive."}
        self.save()
        consumer = self.workspace / "consumer"
        consumer.mkdir()
        for directory in ("bootstrap/cache", "config", "database", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/logs"):
            (consumer / directory).mkdir(parents=True, exist_ok=True)
        (consumer / "database/database.sqlite").touch()
        (consumer / "bootstrap/app.php").write_text("<?php\nreturn Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->withExceptions()->create();\n")
        (consumer / "artisan").write_text("<?php\nrequire __DIR__.'/vendor/autoload.php';\n$app = require __DIR__.'/bootstrap/app.php';\nexit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n")
        (consumer / ".env").write_text("APP_ENV=testing\nAPP_DEBUG=false\nAPP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=\nDB_CONNECTION=sqlite\nDB_DATABASE=" + json.dumps(str(consumer / "database/database.sqlite")) + "\nCACHE_STORE=array\nQUEUE_CONNECTION=sync\n")
        if self.args.database == "mysql":
            database = os.environ.get("AIRWALLEX_QUALIFY_MYSQL_DATABASE", "airwallex_test_consumer")
            if re.fullmatch(r"airwallex_test_[a-z0-9]+", database) is None:
                raise ValueError("MySQL consumer requires a dedicated disposable airwallex_test_ database.")
            self.env.update({"DB_CONNECTION": "mysql", "DB_HOST": os.environ.get("AIRWALLEX_TEST_MYSQL_HOST", "127.0.0.1"), "DB_PORT": os.environ.get("AIRWALLEX_TEST_MYSQL_PORT", "33079"), "DB_DATABASE": database, "DB_USERNAME": os.environ.get("AIRWALLEX_TEST_MYSQL_USER", "root"), "DB_PASSWORD": os.environ.get("AIRWALLEX_TEST_MYSQL_PASSWORD", "")})
        framework = "^12.69" if self.args.laravel == "12" else "^13.0"
        if not isinstance(framework, str):
            raise ValueError("Candidate has no declared Laravel support constraint.")
        consumer_metadata = {"name": "qualification/airwallex-consumer", "description": "Isolated deterministic archive consumer qualification.", "type": "project", "license": "MIT", "require": {"php": metadata["require"]["php"], "laravel/framework": framework, PACKAGE: VERSION}, "repositories": [repository], "minimum-stability": "stable", "prefer-stable": True, "config": {"allow-plugins": {}}}
        write_json(consumer / "composer.json", consumer_metadata)
        composer_argv = [php, str(composer), "--no-ansi", "--no-interaction"]
        resolve = [*composer_argv, "update", "--prefer-dist", "--no-progress", "--no-dev"]
        if self.args.dependencies == "lowest":
            resolve.extend(["--prefer-lowest", "--prefer-stable"])
        self.command("consumer-resolve-" + self.args.dependencies, resolve, consumer)
        self.command("consumer-validate", [*composer_argv, "validate", "--strict"], consumer)
        self.command("consumer-audit", [*composer_argv, "audit", "--locked", "--format=json"], consumer)
        self.command("consumer-discover", [php, "artisan", "package:discover", "--no-ansi"], consumer)
        self.command("consumer-config-cache", [php, "artisan", "config:cache", "--no-ansi"], consumer)
        helper = [php, str(helper_dir / "consumer-check.php"), "--consumer", str(consumer)]
        self.command("consumer-boot", [*helper, "--mode", "boot", "--evidence", str(self.output / "consumer-boot.json")], consumer)
        self.command("consumer-migrate", [php, "artisan", "migrate", "--force", "--no-ansi"], consumer)
        self.command("consumer-schema", [*helper, "--mode", "migrated", "--evidence", str(self.output / "consumer-schema.json")], consumer)
        self.command("consumer-rollback", [php, "artisan", "migrate:rollback", "--force", "--no-ansi"], consumer)
        self.command("consumer-schema-down", [*helper, "--mode", "rolled-back", "--evidence", str(self.output / "consumer-schema-down.json")], consumer)
        self.command("consumer-remigrate", [php, "artisan", "migrate", "--force", "--no-ansi"], consumer)
        self.command("consumer-recipes", [*helper, "--mode", "recipes", "--evidence", str(self.output / "consumer-recipes.json")], consumer)
        installed = consumer / "vendor" / PACKAGE
        actual_files = {str(path.relative_to(installed)) for path in installed.rglob("*") if path.is_file() or path.is_symlink()}
        if actual_files != manifest.keys() or any(path.is_symlink() for path in installed.rglob("*")):
            raise ValueError("Installed package has extra/missing files or symbolic links.")
        mismatches = [name for name, value in manifest.items() if not (installed / name).is_file() or digest((installed / name).read_bytes()) != value["sha256"]]
        if mismatches:
            raise ValueError("Installed runtime differs from the exact archive: " + ", ".join(mismatches))
        lock = json.loads((consumer / "composer.lock").read_text())
        packages = {value["name"]: value for value in lock["packages"]}
        sdk = packages[SDK]
        if sdk.get("dist", {}).get("type") != "zip" or sdk.get("source", {}).get("url") != "https://github.com/jojeringnax/airwallex-php-sdk.git":
            raise ValueError("Installed SDK does not have the expected released Packagist/GitHub provenance.")
        candidate = packages[PACKAGE]
        if candidate.get("version") != VERSION or candidate.get("dist", {}).get("reference") != commit or candidate.get("dist", {}).get("url") != archive.as_uri():
            raise ValueError("Installed package is not the selected local exact-commit archive.")
        shutil.copyfile(consumer / "composer.lock", self.output / "consumer.lock")
        versions = [{"name": name, "version": package["version"], "source": package.get("source"), "dist": package.get("dist")} for name, package in sorted(packages.items())]
        write_json(self.output / "dependency-versions.json", versions)
        self.evidence.update(status="passed", consumer={"path": str(consumer), "lock_sha256": digest((consumer / "composer.lock").read_bytes()), "installed_runtime_matches_manifest": True}, sdk={"version": sdk["version"], "source": sdk.get("source"), "dist": sdk.get("dist")}, runtime=json.loads((self.output / "consumer-boot.json").read_text())["runtime"], finished_at=dt.datetime.now(dt.timezone.utc).isoformat())
        self.save()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, epilog="Full qualification extracts tools from the selected commit and requires this qualifier's bytes to match it. Outputs are isolated per run. Network is used only for public Composer dependencies; recipe requests are denied outside their synthetic transport. No tag, push, publication or Airwallex request is performed.")
    parser.add_argument("--candidate", required=True, help="Explicit Git commit/ref, resolved once and archived by its full commit ID")
    parser.add_argument("--composer", required=True, type=Path, help="Previously verified Composer 2.11.2 PHAR")
    parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parent.parent)
    parser.add_argument("--php", default="php", help="PHP executable/path; defaults to php on PATH")
    parser.add_argument("--dependencies", choices=("lowest", "latest"), default="latest", help="Security-aware fresh consumer resolution; defaults to latest")
    parser.add_argument("--laravel", choices=("12", "13"), default="12", help="Explicit native framework major")
    parser.add_argument("--database", choices=("sqlite", "mysql"), default="sqlite", help="Explicit native database backend; MySQL requires a precreated disposable schema")
    parser.add_argument("--workspace", type=Path, default=Path(tempfile.gettempdir()) / "airwallex-candidate-qualification", help="Base for a new private isolated run directory")
    parser.add_argument("--output", type=Path, help="Optional evidence base; a new directory is created per run")
    parser.add_argument("--archive-only", action="store_true", help="Verify archive/descriptor only; never reports consumer qualification passed")
    args = parser.parse_args()
    qualification = Qualification(args)
    try:
        qualification.run()
        print(json.dumps({"status": qualification.evidence["status"], "evidence": str(qualification.output / "evidence.json")}), flush=True)
        return 0
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError, zipfile.BadZipFile) as error:
        qualification.evidence.update(status="failed", failure=str(error))
        qualification.save()
        print(json.dumps({"status": "failed", "reason": str(error), "evidence": str(qualification.output / "evidence.json") if qualification.output else None}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
