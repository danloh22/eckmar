"""Contracts for the bounded CI-only apt package installer retry."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


class SystemPackageInstallerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="eckmar-apt-retry-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.log = self.root / "apt.log"
        self.count = self.root / "count"
        self.script = Path(__file__).resolve().parents[1] / "scripts/install-system-packages.sh"
        (self.bin / "apt-get").write_text(
            "#!/bin/sh\n"
            "count=0\n"
            "[ ! -f \"$FAKE_COUNT\" ] || count=$(cat \"$FAKE_COUNT\")\n"
            "count=$((count + 1))\n"
            "printf '%s' \"$count\" > \"$FAKE_COUNT\"\n"
            "printf '%s|%s\\n' \"${DEBIAN_FRONTEND-}\" \"$*\" >> \"$FAKE_LOG\"\n"
            "[ \"$count\" -gt \"$FAKE_FAILURES\" ]\n"
        )
        (self.bin / "sleep").write_text(
            "#!/bin/sh\nprintf 'sleep|%s\\n' \"$*\" >> \"$FAKE_LOG\"\n"
        )
        (self.bin / "apt-get").chmod(0o755)
        (self.bin / "sleep").chmod(0o755)

    def run_installer(self, failures, attempts="3", delay="0", packages=("git", "unzip")):
        env = os.environ.copy()
        env.update({
            "PATH": f"{self.bin}:{env['PATH']}",
            "FAKE_COUNT": str(self.count),
            "FAKE_LOG": str(self.log),
            "FAKE_FAILURES": str(failures),
            "APT_INSTALL_MAX_ATTEMPTS": attempts,
            "APT_INSTALL_RETRY_DELAY_SECONDS": delay,
        })
        return subprocess.run(
            ["sh", str(self.script), *packages], env=env,
            text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )

    def lines(self):
        return self.log.read_text().splitlines() if self.log.exists() else []

    def test_immediate_success_preserves_install_contract(self):
        result = self.run_installer(0)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.lines(), [
            "noninteractive|-o Acquire::Retries=3 install -y --no-install-recommends git unzip"
        ])

    def test_transient_failures_retry_then_succeed(self):
        result = self.run_installer(2, delay="7")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(sum("Acquire::Retries=3 install" in line for line in self.lines()), 3)
        self.assertEqual([line for line in self.lines() if line.startswith("sleep|")], ["sleep|7", "sleep|7"])

    def test_permanent_failure_stops_at_exact_cap(self):
        result = self.run_installer(9, attempts="2")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(sum("Acquire::Retries=3 install" in line for line in self.lines()), 2)
        self.assertIn("failed after 2 attempt(s)", result.stderr)

    def test_invalid_configuration_and_empty_package_list_fail_closed(self):
        self.assertEqual(self.run_installer(0, attempts="0").returncode, 2)
        self.assertEqual(self.run_installer(0, delay="later").returncode, 2)
        self.assertEqual(self.run_installer(0, packages=()).returncode, 2)


if __name__ == "__main__":
    unittest.main()
