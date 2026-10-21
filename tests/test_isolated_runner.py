"""Runner contract tests using a PHP stand-in; these do NOT execute PHP/PHPUnit."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class IsolatedRunnerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="eckmar-runner-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "scripts").mkdir()
        self.runner = self.root / "scripts/test-isolated.sh"
        shutil.copyfile(Path(__file__).resolve().parents[1] / "scripts/test-isolated.sh", self.runner)
        for name in ("composer.lock", "phpunit.xml", "vendor/autoload.php", "vendor/bin/phpunit"):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.touch()
        (self.root / "bin").mkdir()
        self.php = self.root / "bin/php"
        self.php.write_text("#!/bin/bash\nprintf 'PHP_STAND_IN_CALLED\\n'\n", encoding="utf-8")
        self.php.chmod(0o700)

    def run_script(self, *args):
        environment = dict(os.environ, PATH=str(self.root / "bin") + ":/usr/bin:/bin",
                           DB_PASSWORD="must-not-reach-child", WALLET_RPC_PASSWORD="must-not-reach-child",
                           APP_ENV="production", PHP_INI_SCAN_DIR="must-not-reach-child")
        return subprocess.run(["/bin/bash", str(self.runner), *args], env=environment,
                              text=True, capture_output=True, cwd="/")

    def test_refuses_environment_and_cached_configuration_before_php(self):
        for name in (".env", ".env.testing", "bootstrap/cache/config.php"):
            with self.subTest(name=name):
                path = self.root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.touch()
                result = self.run_script()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(name, result.stderr)
                self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)
                path.unlink()

    def test_refuses_broken_environment_symlink(self):
        (self.root / ".env").symlink_to(self.root / "missing-env")
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

    def test_refuses_symlinked_bootstrap_directories_before_php(self):
        external_bootstrap = self.root / "external-bootstrap"
        (external_bootstrap / "cache").mkdir(parents=True)
        (self.root / "bootstrap").symlink_to(external_bootstrap, target_is_directory=True)
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("bootstrap is symlinked", result.stderr)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

        (self.root / "bootstrap").unlink()
        (self.root / "bootstrap").mkdir()
        external_cache = self.root / "external-cache"
        external_cache.mkdir()
        (self.root / "bootstrap/cache").symlink_to(external_cache, target_is_directory=True)
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("bootstrap/cache is symlinked", result.stderr)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

    def test_refuses_symlinked_storage_directories_before_php(self):
        external_storage = self.root / "external-storage"
        (external_storage / "framework").mkdir(parents=True)
        (self.root / "storage").symlink_to(external_storage, target_is_directory=True)
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("storage is symlinked", result.stderr)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

        (self.root / "storage").unlink()
        (self.root / "storage").mkdir()
        external_framework = self.root / "external-framework"
        external_framework.mkdir()
        (self.root / "storage/framework").symlink_to(external_framework, target_is_directory=True)
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("storage/framework is symlinked", result.stderr)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

    def test_refuses_symlinked_nested_storage_directories_before_php(self):
        external_runtime = self.root / "external-runtime"
        external_runtime.mkdir()
        for name in ("storage/logs", "storage/framework/cache",
                     "storage/framework/sessions", "storage/framework/views"):
            with self.subTest(name=name):
                path = self.root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.symlink_to(external_runtime, target_is_directory=True)
                result = self.run_script()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(f"{name} is symlinked", result.stderr)
                self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)
                path.unlink()

    def test_refuses_symlinked_source_directories_before_php(self):
        external_source = self.root / "external-source"
        external_source.mkdir()
        for name in ("app", "config", "database", "tests"):
            with self.subTest(name=name):
                path = self.root / name
                path.symlink_to(external_source, target_is_directory=True)
                result = self.run_script()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(f"{name} is symlinked", result.stderr)
                self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)
                path.unlink()

    def test_refuses_symlinked_route_and_resource_directories_before_php(self):
        external_source = self.root / "external-route-source"
        external_source.mkdir()
        for name in ("routes", "resources"):
            with self.subTest(name=name):
                path = self.root / name
                path.symlink_to(external_source, target_is_directory=True)
                result = self.run_script()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(f"{name} is symlinked", result.stderr)
                self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)
                path.unlink()

    def test_refuses_missing_locked_dependencies_before_php(self):
        for name in ("composer.lock", "phpunit.xml", "vendor/autoload.php", "vendor/bin/phpunit"):
            with self.subTest(name=name):
                path = self.root / name
                path.unlink()
                result = self.run_script()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(name, result.stderr)
                self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)
                path.touch()

    def test_refuses_symlinked_locked_dependencies_before_php(self):
        target = self.root / "external-artifact"
        target.touch()
        for name in ("composer.lock", "phpunit.xml", "vendor/autoload.php", "vendor/bin/phpunit"):
            with self.subTest(name=name):
                path = self.root / name
                path.unlink()
                path.symlink_to(target)
                result = self.run_script()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(name, result.stderr)
                self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)
                path.unlink()
                path.touch()

    def test_refuses_symlinked_vendor_directories_before_php(self):
        external_vendor = self.root / "external-vendor"
        (external_vendor / "bin").mkdir(parents=True)
        (external_vendor / "autoload.php").touch()
        (external_vendor / "bin/phpunit").touch()

        shutil.rmtree(self.root / "vendor")
        (self.root / "vendor").symlink_to(external_vendor, target_is_directory=True)
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("vendor directory", result.stderr)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

        (self.root / "vendor").unlink()
        (self.root / "vendor").mkdir()
        (self.root / "vendor/autoload.php").touch()
        (self.root / "vendor/bin").symlink_to(external_vendor / "bin", target_is_directory=True)
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("vendor/bin directory", result.stderr)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

    def test_refuses_configuration_overrides(self):
        result = self.run_script("--configuration", "/tmp/other.xml")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("PHP_STAND_IN_CALLED", result.stdout)

    def test_stops_when_runtime_preflight_fails(self):
        self.php.write_text('#!/bin/bash\n[[ "$1" == "-r" ]] && exit 27\nexit 99\n', encoding="utf-8")
        self.assertEqual(self.run_script().returncode, 27)

    def test_sanitizes_environment_and_pins_absolute_configuration(self):
        self.php.write_text('''#!/bin/bash
set -eu
[[ -z "${DB_PASSWORD+x}" && -z "${WALLET_RPC_PASSWORD+x}" && -z "${PHP_INI_SCAN_DIR+x}" ]]
[[ "$APP_ENV" == testing && "$DB_CONNECTION" == sqlite && "$DB_DATABASE" == :memory: ]]
[[ "$CACHE_DRIVER" == array && "$SESSION_DRIVER" == array && "$MAIL_DRIVER" == array && "$QUEUE_DRIVER" == sync ]]
[[ "$1" == -r ]] && exit 0
[[ "$#" == 3 && "$1" == "$PWD/vendor/bin/phpunit" && "$2" == --configuration && "$3" == "$PWD/phpunit.xml" ]]
printf 'SANITIZED_RUNNER_CONTRACT_OK\\n'
''', encoding="utf-8")
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("SANITIZED_RUNNER_CONTRACT_OK", result.stdout)


if __name__ == "__main__":
    unittest.main()

