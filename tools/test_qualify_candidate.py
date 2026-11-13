"""Deterministic release-tool checks; no Composer or network access."""

import contextlib
import importlib.util
import io
import json
from pathlib import Path
import stat
import subprocess
import tempfile
from types import SimpleNamespace
from unittest.mock import Mock
import unittest
import warnings
import zipfile

spec = importlib.util.spec_from_file_location("qualifier", Path(__file__).with_name("qualify-candidate.py"))
qualifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qualifier)


class ArchiveBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="airwallex-artifact-test-")
        self.addCleanup(self.directory.cleanup)
        self.archive = Path(self.directory.name) / "candidate.zip"
        self.metadata = {"name": qualifier.PACKAGE, "require": {qualifier.SDK: "^0.1.3"}, "autoload": {"psr-4": {"Example\\": "src/"}}, "autoload-dev": {"psr-4": {"Tests\\": "tests/"}}, "scripts": {"test": "not-a-runtime-command"}}

    def make(self, extras=None, omit=()):
        files = {"composer.json": json.dumps(self.metadata), "LICENSE": "MIT", "README.md": "Candidate", "src/Service.php": "<?php", "config/airwallex.php": "<?php return [];"}
        files.update(extras or {})
        with zipfile.ZipFile(self.archive, "w") as zipped:
            for name, value in files.items():
                if name not in omit:
                    zipped.writestr(name, value)

    def test_exact_runtime_manifest_and_descriptor_preserve_sdk_constraint(self):
        self.make()
        manifest, metadata = qualifier.inspect_archive(self.archive, {"src/Service.php", "config/airwallex.php"})
        self.assertEqual(qualifier.digest(b"<?php"), manifest["src/Service.php"]["sha256"])
        repository = qualifier.package_repository(metadata, self.archive, "a" * 40)
        self.assertEqual("0.1.0-RC1", repository["package"]["version"])
        self.assertEqual("^0.1.3", repository["package"]["require"][qualifier.SDK])
        self.assertEqual("a" * 40, repository["package"]["dist"]["reference"])
        self.assertNotIn("scripts", repository["package"])
        self.assertNotIn("autoload-dev", repository["package"])
        self.assertNotIn("source", repository["package"])

    def test_development_sdk_archive_is_exact_and_explicitly_unreleased(self):
        self.metadata = {"name": qualifier.SDK, "require": {"php": ">=8.3 <8.6"}, "autoload": {"psr-4": {"Jojeringnax\\Airwallex\\": "src/"}}}
        self.make({"resources/contracts/payments.json": "{}"})
        manifest, metadata = qualifier.inspect_archive(self.archive, {"resources/contracts/payments.json"}, package_name=qualifier.SDK)
        repository = qualifier.package_repository(metadata, self.archive, "b" * 40, version="0.1.4-dev")
        self.assertEqual("0.1.4-dev", repository["package"]["version"])
        self.assertEqual("b" * 40, repository["package"]["dist"]["reference"])
        self.assertEqual(self.archive.as_uri(), repository["package"]["dist"]["url"])
        self.assertIn("resources/contracts/payments.json", manifest)
        self.assertNotIn("source", repository["package"])

    def test_released_sdk_selection_rejects_development_or_ambiguous_sources(self):
        qualifier.validate_sdk_selection(None, None, "0.1.3")
        qualifier.validate_sdk_selection(None, None, None)
        qualifier.validate_sdk_selection(Path("/read-only/sdk"), "a" * 40, None)
        for repo, candidate, version in [
            (Path("/read-only/sdk"), "a" * 40, "0.1.3"),
            (None, "a" * 40, None),
            (Path("/read-only/sdk"), None, None),
            *[(None, None, version) for version in ["^0.1.3", "v0.1.3", "0.1.4-dev", "dev-main", "0.1", "01.1.2", "../sdk", ""]],
        ]:
            with self.subTest(repo=repo, candidate=candidate, version=version):
                with self.assertRaises(ValueError):
                    qualifier.validate_sdk_selection(repo, candidate, version)

    def test_selected_track_helpers_are_pinned_and_preserve_test_paths(self):
        files = {"tools/qualify-candidate.py": b"driver", "tools/consumer-check.php": b"baseline", "tools/consumer-fixtures.php": b"fixtures", "tools/payments-consumer-check.php": b"payment helper", "tests/fixture.php": b"test helper"}
        destination = Path(self.directory.name) / "selected-track"
        qualifier.extract_tooling(files.__getitem__, destination, b"driver", ("tools/payments-consumer-check.php", "tests/fixture.php"))
        self.assertEqual(b"payment helper", (destination / "payments-consumer-check.php").read_bytes())
        self.assertEqual(b"test helper", (destination / "tests/fixture.php").read_bytes())

    def test_development_credentials_and_cache_names_are_rejected(self):
        for name in [".env", ".env.production", ".gitattributes", "pint.json", "composer.lock", "tests/Test.php", "tools/fixture.php", "vendor/autoload.php", ".github/workflows/check.yml", "src/.env", ".phpstan-cache/cache.php"]:
            with self.subTest(name=name):
                self.make({name: "synthetic excluded content"})
                with self.assertRaises(ValueError):
                    qualifier.inspect_archive(self.archive, {"src/Service.php"})

    def test_missing_runtime_files_cannot_be_hidden_by_export_ignore(self):
        self.make(omit={"src/Service.php"})
        with self.assertRaisesRegex(ValueError, "omitted required"):
            qualifier.inspect_archive(self.archive, {"src/Service.php"})

    def test_unknown_distribution_paths_require_review(self):
        self.make({"accidental-dump.txt": "synthetic"})
        with self.assertRaisesRegex(ValueError, "distribution review"):
            qualifier.inspect_archive(self.archive, set())

    def test_traversal_symlinks_and_duplicate_files_are_rejected(self):
        for name in ["../escape", "/absolute", "src/../../escape", "src\\escape"]:
            with self.subTest(name=name):
                self.make({name: "synthetic"})
                with self.assertRaises(ValueError):
                    qualifier.inspect_archive(self.archive, set())
        self.make()
        with zipfile.ZipFile(self.archive, "a") as zipped:
            entry = zipfile.ZipInfo("src/link.php")
            entry.create_system = 3
            entry.external_attr = (stat.S_IFLNK | 0o777) << 16
            zipped.writestr(entry, "../../outside")
        with self.assertRaisesRegex(ValueError, "symbolic links"):
            qualifier.inspect_archive(self.archive, set())

    def test_repository_override_is_rejected(self):
        self.metadata["repositories"] = [{"type": "path", "url": "../sdk"}]
        self.make()
        with self.assertRaisesRegex(ValueError, "repository overrides"):
            qualifier.inspect_archive(self.archive, set())

    def test_duplicate_archive_paths_are_rejected(self):
        self.make()
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(self.archive, "a") as zipped:
                zipped.writestr("src/Service.php", "different duplicate")
        with self.assertRaisesRegex(ValueError, "duplicate"):
            qualifier.inspect_archive(self.archive, set())

    def test_tooling_is_extracted_from_candidate_blobs(self):
        files = {"tools/qualify-candidate.py": b"pinned driver\n", "tools/consumer-check.php": b"pinned helper\n", "tools/consumer-fixtures.php": b"pinned fixtures\n"}
        destination = Path(self.directory.name) / "helpers"
        manifest = qualifier.extract_tooling(files.__getitem__, destination, b"pinned driver\n")
        self.assertEqual(b"pinned helper\n", (destination / "consumer-check.php").read_bytes())
        self.assertEqual(qualifier.digest(b"pinned fixtures\n"), manifest["consumer-fixtures.php"])

    def test_different_running_qualifier_cannot_validate_a_candidate(self):
        files = {"tools/qualify-candidate.py": b"pinned driver\n", "tools/consumer-check.php": b"helper", "tools/consumer-fixtures.php": b"fixtures"}
        destination = Path(self.directory.name) / "helpers"
        with self.assertRaisesRegex(ValueError, "Running qualifier differs"):
            qualifier.extract_tooling(files.__getitem__, destination, b"uncommitted driver\n")
        self.assertFalse(destination.exists())

    def test_qualifier_git_archive_matches_canonical_utc_bytes(self):
        repo = Path(self.directory.name) / "git-fixture"
        repo.mkdir()
        qualification = qualifier.Qualification(SimpleNamespace(repo=repo, candidate="HEAD", dependencies="latest", laravel="12", database="sqlite", preserve_sdk_objects=False))
        environment = dict(qualification.env, GIT_AUTHOR_NAME="Archive fixture", GIT_AUTHOR_EMAIL="fixture@example.test", GIT_COMMITTER_NAME="Archive fixture", GIT_COMMITTER_EMAIL="fixture@example.test", GIT_AUTHOR_DATE="2026-11-10T12:00:00+0000", GIT_COMMITTER_DATE="2026-11-10T12:00:00+0000")
        subprocess.run(["git", "init", "--quiet"], cwd=repo, env=environment, check=True)
        (repo / "src").mkdir()
        (repo / "src/fixture.txt").write_bytes(b"Timezone-independent archive content.\n")
        subprocess.run(["git", "add", "--", "src"], cwd=repo, env=environment, check=True)
        subprocess.run(["git", "-c", "core.hooksPath=/dev/null", "-c", "commit.gpgsign=false", "commit", "--quiet", "-m", "Fixed-time archive fixture"], cwd=repo, env=environment, check=True)
        commit = subprocess.run(["git", "rev-parse", "HEAD"], cwd=repo, env=environment, check=True, capture_output=True, text=True).stdout.strip()
        actual = Path(self.directory.name) / "qualifier.zip"
        utc = Path(self.directory.name) / "utc.zip"
        honolulu = Path(self.directory.name) / "honolulu.zip"
        qualification.command("archive-regression", ["git", "archive", "--format=zip", f"--output={actual}", commit], repo)
        for path, timezone in [(utc, "UTC"), (honolulu, "Pacific/Honolulu")]:
            subprocess.run(["git", "archive", "--format=zip", f"--output={path}", commit], cwd=repo, env=dict(environment, TZ=timezone), check=True)
        self.assertNotEqual(utc.read_bytes(), honolulu.read_bytes(), "Control archives must expose Git's host-timezone ZIP timestamps.")
        self.assertEqual(utc.read_bytes(), actual.read_bytes(), "Qualifier must produce canonical UTC Git archive bytes on every host.")


class DependencyAuditTest(unittest.TestCase):
    def test_only_transient_composer_network_errors_are_retried_with_a_bound(self):
        spec = importlib.util.spec_from_file_location("audit", Path(__file__).with_name("audit-dependencies.py"))
        audit = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(audit)
        for responses, expected, attempts, delays in [
            ([(0, "")], 0, 1, []),
            ([(100, "curl error 28: Connection timed out"), (0, "")], 0, 2, [2]),
            ([(100, "curl error 6: Could not resolve host")] * 3, 100, 3, [2, 4]),
            ([(1, "Security advisory; curl error 28 appears in advisory text")], 1, 1, []),
            ([(2, "Abandoned package")], 2, 1, []),
            ([(100, "Invalid repository configuration")], 100, 1, []),
        ]:
            with self.subTest(responses=responses):
                run = Mock(side_effect=[SimpleNamespace(returncode=code, stdout="", stderr=message) for code, message in responses])
                pause = Mock()
                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    result = audit.audit(["php", "/synthetic/composer.phar"], run=run, pause=pause)
                self.assertEqual(expected, result)
                self.assertEqual(attempts, run.call_count)
                self.assertEqual(delays, [call.args[0] for call in pause.call_args_list])
                for call in run.call_args_list:
                    self.assertEqual(["php", "/synthetic/composer.phar", "audit", "--locked"], call.args[0])


if __name__ == "__main__":
    unittest.main()
