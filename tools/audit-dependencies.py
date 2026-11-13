#!/usr/bin/env python3
"""Retry the locked Composer audit only after a transient network error."""

import argparse
import re
import subprocess
import sys
import time


TRANSIENT_CURL = re.compile(r"curl error (?:6|7|28|35|52|56)\b", re.IGNORECASE)


def audit(command, *, run=subprocess.run, pause=time.sleep):
    for attempt in range(3):
        result = run([*command, "audit", "--locked"], capture_output=True, text=True)
        sys.stdout.write(result.stdout)
        sys.stderr.write(result.stderr)
        # The observed transport failure returned 100; other resolver errors can too.
        # Require transient connection evidence as well, and preserve all other failures.
        if result.returncode != 100 or not TRANSIENT_CURL.search(result.stdout + result.stderr) or attempt == 2:
            return result.returncode
        print("Transient advisory-service connection failure; retrying the locked audit.", file=sys.stderr)
        pause(2 ** (attempt + 1))
    raise AssertionError("Audit attempts must return a result.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--composer", type=str, help="Path to a Composer PHAR; otherwise use composer from PATH")
    options = parser.parse_args()
    sys.exit(audit(["php", options.composer] if options.composer else ["composer"]))
