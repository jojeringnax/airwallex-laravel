"""Deterministic artifact-boundary checks; no Composer or network access."""

import importlib.util
import json
from pathlib import Path
import stat
import tempfile
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
        self.metadata = {"name": qualifier.PACKAGE, "require": {qualifier.SDK: "^0.1.1"}, "autoload": {"psr-4": {"Example\\": "src/"}}, "autoload-dev": {"psr-4": {"Tests\\": "tests/"}}, "scripts": {"test": "not-a-runtime-command"}}

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
        self.assertEqual("^0.1.1", repository["package"]["require"][qualifier.SDK])
        self.assertEqual("a" * 40, repository["package"]["dist"]["reference"])
        self.assertNotIn("scripts", repository["package"])
        self.assertNotIn("autoload-dev", repository["package"])
        self.assertNotIn("source", repository["package"])

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


if __name__ == "__main__":
    unittest.main()
