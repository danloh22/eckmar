# Test environment

The current project declares PHP `^7.1.3`, Laravel `5.7.*` and PHPUnit `^7.0` in `composer.json`. Use an isolated development runtime compatible with the committed Composer lock file; do not bypass platform requirements or update dependencies merely to make a test run. The declared extensions include cURL, GMP and XML-RPC. The default test database also needs PDO SQLite.

`phpunit.xml` forces the testing environment, array cache/session/mail drivers, synchronous queue and an in-memory SQLite connection. This prevents the default connection from inheriting a real MySQL database from the shell or `.env`. It is not a network sandbox. Never provide live wallet RPC credentials to tests; mock RPC and other external calls.

Run the existing suite with `bash scripts/test-isolated.sh` only after the matching PHP runtime and locked dependencies are installed in an isolated, disposable checkout. The runner refuses `.env`, `.env.testing`, and `bootstrap/cache/config.php` (including dangling symlinks), checks PHP 7.x and required extensions, strips inherited environment credentials, and supplies a fixed testing-only application key. It accepts no arguments and pins the committed PHPUnit configuration. It never installs dependencies, deletes configuration, or bypasses platform requirements.

This runner is not a network sandbox and cannot prove that application code, PHP configuration, or dependencies contain no hardcoded external endpoints. Keep the checkout free of secrets and mock all external calls. Use the isolated runtime's network controls for enforcement. It does not provide a disposable MySQL service or prove concurrency safety.

The runner's own safety contract can be checked without PHP using `python3 tests/test_isolated_runner.py`; those tests use a PHP stand-in and are NOT application or PHPUnit results.

The existing tests do not run migrations or use `RefreshDatabase`. The in-memory configuration is a safe default for model-only and mocked tests, not a statement that all migrations work on SQLite. For example, `2019_05_12_220221_create_purchases_cancelation.php` contains MySQL-specific `ALTER TABLE ... MODIFY COLUMN ... ENUM`. Migration, row-lock and concurrent payment tests need a separately configured disposable MySQL database, never the live database. SQLite cannot prove MySQL locking behavior.

## Verification on 8 October 2026

This workspace had no PHP, Composer, PHPUnit dependencies, Docker or Podman. No alternative PHP binary or cached installation was found under `/opt`, `/usr` or `/tmp`. Consequently no PHP test suite was executed. XML parsing and the forced test-configuration values were checked using Python; no cached Laravel configuration was present. No database, wallet RPC, dependency installation, or paid service was invoked.

## Recheck on 9 October 2026

PHP, Composer, `vendor/autoload.php`, Docker and Podman remain unavailable. The lock file specifies Laravel `v5.7.28` and PHPUnit `7.5.8`, both requiring PHP 7.x; installing the host distribution's default PHP 8 would not satisfy those requirements.

A normal `apt-get update` with short network timeouts failed before any package download: the container denied `setgroups`/`seteuid`, and the HTTPS acquisition process exited with code 112 (APT exit 100). No sandbox restrictions were disabled, no dependency requirements were bypassed, and no project dependencies were changed. PHPUnit execution, including `CheckoutTransactionRetryTest`, therefore remains blocked. This is not a passing test result.

## Runner verification on 16 October 2026

`bash -n scripts/test-isolated.sh` and all six Python runner-contract tests passed. Covered rejection paths include environment files, cached configuration, broken environment symlinks, missing locked dependencies and configuration overrides; child-environment sanitization and failure propagation are also checked. The PHP stand-in only checks shell behavior. PHP, Composer, Docker and Podman remain absent, so PHP syntax checks, runtime extension/version validation and PHPUnit execution have NOT run. No package installation was retried.


## Runner symlink hardening on 21 October 2026

The runner now rejects symlinked `composer.lock`, `phpunit.xml`, `vendor/autoload.php` and `vendor/bin/phpunit` before invoking PHP. Locked artifacts must exist directly in the disposable checkout rather than resolving to files outside it. Bash syntax validation and all seven Python runner-contract tests passed, including each symlink rejection. These remain shell-runner tests using a PHP stand-in; PHP syntax checks and PHPUnit were not executed.
