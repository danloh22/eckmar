# Test environment

The current project declares PHP `^7.1.3`, Laravel `5.7.*` and PHPUnit `^7.0` in `composer.json`. Use an isolated development runtime compatible with the committed Composer lock file; do not bypass platform requirements or update dependencies merely to make a test run. The declared extensions include cURL, GMP and XML-RPC. The default test database also needs PDO SQLite.

`phpunit.xml` forces the testing environment, array cache/session/mail drivers, synchronous queue and an in-memory SQLite connection. This prevents the default connection from inheriting a real MySQL database from the shell or `.env`. It is not a network sandbox. Never provide live wallet RPC credentials to tests; mock RPC and other external calls.

Run the existing suite with `vendor/bin/phpunit` only after the matching PHP runtime and locked dependencies are installed in the isolated environment. Ensure `bootstrap/cache/config.php` is absent: Laravel's cached configuration can override environment-based settings. Use a disposable checkout with a testing-only application key for request tests.

The existing tests do not run migrations or use `RefreshDatabase`. The in-memory configuration is a safe default for model-only and mocked tests, not a statement that all migrations work on SQLite. For example, `2019_05_12_220221_create_purchases_cancelation.php` contains MySQL-specific `ALTER TABLE ... MODIFY COLUMN ... ENUM`. Migration, row-lock and concurrent payment tests need a separately configured disposable MySQL database, never the live database. SQLite cannot prove MySQL locking behavior.

## Verification on 8 October 2026

This workspace had no PHP, Composer, PHPUnit dependencies, Docker or Podman. No alternative PHP binary or cached installation was found under `/opt`, `/usr` or `/tmp`. Consequently no PHP test suite was executed. XML parsing and the forced test-configuration values were checked using Python; no cached Laravel configuration was present. No database, wallet RPC, dependency installation, or paid service was invoked.

## Recheck on 9 October 2026

PHP, Composer, `vendor/autoload.php`, Docker and Podman remain unavailable. The lock file specifies Laravel `v5.7.28` and PHPUnit `7.5.8`, both requiring PHP 7.x; installing the host distribution's default PHP 8 would not satisfy those requirements.

A normal `apt-get update` with short network timeouts failed before any package download: the container denied `setgroups`/`seteuid`, and the HTTPS acquisition process exited with code 112 (APT exit 100). No sandbox restrictions were disabled, no dependency requirements were bypassed, and no project dependencies were changed. PHPUnit execution, including `CheckoutTransactionRetryTest`, therefore remains blocked. This is not a passing test result.
