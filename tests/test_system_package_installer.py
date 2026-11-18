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
        self.update_count = self.root / "update-count"
        self.install_count = self.root / "install-count"
        self.script = Path(__file__).resolve().parents[1] / "scripts/install-system-packages.sh"
        (self.bin / "apt-get").write_text(
            "#!/bin/sh\n"
            "printf '%s|%s\\n' \"${DEBIAN_FRONTEND-}\" \"$*\" >> \"$FAKE_LOG\"\n"
            "case \" $* \" in\n"
            "  *' update '*) count_file=$FAKE_UPDATE_COUNT; failures=$FAKE_UPDATE_FAILURES ;;\n"
            "  *' install '*) count_file=$FAKE_INSTALL_COUNT; failures=$FAKE_INSTALL_FAILURES ;;\n"
            "  *) exit 91 ;;\n"
            "esac\n"
            "count=0\n"
            "[ ! -f \"$count_file\" ] || count=$(cat \"$count_file\")\n"
            "count=$((count + 1))\n"
            "printf '%s' \"$count\" > \"$count_file\"\n"
            "[ \"$count\" -gt \"$failures\" ]\n"
        )
        (self.bin / "sleep").write_text(
            "#!/bin/sh\nprintf 'sleep|%s\\n' \"$*\" >> \"$FAKE_LOG\"\n"
        )
        (self.bin / "apt-get").chmod(0o755)
        (self.bin / "sleep").chmod(0o755)

    def run_installer(self, update_failures=0, install_failures=0, attempts="3", delay="0", packages=("git", "unzip")):
        env = os.environ.copy()
        env.pop("DEBIAN_FRONTEND", None)
        env.update({
            "PATH": f"{self.bin}:{env['PATH']}",
            "FAKE_UPDATE_COUNT": str(self.update_count),
            "FAKE_INSTALL_COUNT": str(self.install_count),
            "FAKE_LOG": str(self.log),
            "FAKE_UPDATE_FAILURES": str(update_failures),
            "FAKE_INSTALL_FAILURES": str(install_failures),
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
        result = self.run_installer()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.lines(), [
            "|-o Acquire::Retries=3 -o APT::Update::Error-Mode=any update",
            "noninteractive|-o Acquire::Retries=3 install -y --no-install-recommends git unzip"
        ])

    def test_transient_update_failures_retry_whole_bootstrap_then_succeed(self):
        result = self.run_installer(update_failures=2, delay="7")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(sum("Error-Mode=any update" in line for line in self.lines()), 3)
        self.assertEqual(sum("Acquire::Retries=3 install" in line for line in self.lines()), 1)
        self.assertEqual([line for line in self.lines() if line.startswith("sleep|")], ["sleep|7", "sleep|7"])

    def test_transient_install_failure_refreshes_index_before_retry(self):
        result = self.run_installer(install_failures=1)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(sum("Error-Mode=any update" in line for line in self.lines()), 2)
        self.assertEqual(sum("Acquire::Retries=3 install" in line for line in self.lines()), 2)

    def test_permanent_failure_stops_at_exact_cap(self):
        result = self.run_installer(update_failures=9, attempts="2")
        self.assertEqual(result.returncode, 1)
        self.assertEqual(sum("Error-Mode=any update" in line for line in self.lines()), 2)
        self.assertEqual(sum("Acquire::Retries=3 install" in line for line in self.lines()), 0)
        self.assertIn("bootstrap failed after 2 attempt(s)", result.stderr)

    def test_invalid_configuration_and_empty_package_list_fail_closed(self):
        self.assertEqual(self.run_installer(attempts="0").returncode, 2)
        self.assertEqual(self.run_installer(delay="later").returncode, 2)
        self.assertEqual(self.run_installer(packages=()).returncode, 2)


if __name__ == "__main__":
    unittest.main()
