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


## Runner parent-directory hardening on 21 October 2026

The runner now rejects symlinked `vendor` and `vendor/bin` directories as well as symlinked required files. This closes the remaining parent-path escape where regular-looking autoload or PHPUnit files could resolve through an external vendor tree. `bash -n scripts/test-isolated.sh` and all eight Python runner-contract tests passed, including both directory cases. These are shell-runner tests using a PHP stand-in; PHP syntax checks, PHPUnit, Laravel and MySQL were not executed.

## Runner bootstrap-path hardening on 21 October 2026

The runner now rejects symlinked `bootstrap` and `bootstrap/cache` directories before invoking PHP. This keeps Laravel cache reads and writes inside the disposable checkout even when no cached `config.php` exists yet. `bash -n scripts/test-isolated.sh` and all nine Python runner-contract tests passed, including both bootstrap parent-directory cases. These are shell-runner tests using a PHP stand-in; PHP syntax checks, PHPUnit, Laravel and MySQL were not executed.

## Runner storage-path hardening on 21 October 2026

The runner now rejects symlinked `storage` and `storage/framework` directories before invoking PHP. This keeps Laravel runtime writes, compiled views and log paths inside the disposable checkout instead of allowing a parent-path escape. `bash -n scripts/test-isolated.sh` and all ten Python runner-contract tests passed, including both storage directory cases. These are shell-runner tests using a PHP stand-in; PHP syntax checks, PHPUnit, Laravel and MySQL were not executed.


## Runner source-directory hardening on 21 October 2026

The runner now rejects symlinked `app`, `config`, `database` and `tests` directories before invoking PHP. This ensures PHPUnit executes the reviewed application, configuration, migrations and tests from the disposable checkout rather than source trees outside it. `bash -n scripts/test-isolated.sh` and all eleven Python runner-contract tests passed, including all four source-directory cases. These are shell-runner tests using a PHP stand-in; PHP syntax checks, PHPUnit, Laravel and MySQL were not executed.


## Runner route/resource isolation on 21 October 2026

The runner now also rejects symlinked `routes` and `resources` directories before invoking PHP. Route definitions, views and other framework resources must therefore come from the reviewed disposable checkout rather than an external tree. `bash -n scripts/test-isolated.sh` and all twelve Python runner-contract tests passed, including both new directory cases. These remain shell-runner tests using a PHP stand-in; PHP syntax checks, PHPUnit, Laravel and MySQL were not executed.

## Runner nested storage isolation on 21 October 2026

The runner now rejects symlinked `storage/logs` plus `storage/framework/cache`, `storage/framework/sessions` and `storage/framework/views` directories before invoking PHP. This keeps Laravel logs, cache entries, sessions and compiled views inside the disposable checkout even when their parent storage directories are local. `bash -n scripts/test-isolated.sh` and all thirteen Python runner-contract tests passed, including all four nested directory cases. These remain shell-runner tests using a PHP stand-in; PHP syntax checks, PHPUnit, Laravel and MySQL were not executed.


## Runner storage-application isolation on 21 October 2026

The runner now also rejects symlinked `storage/app` and `storage/app/public` directories before invoking PHP. Uploads and other application-managed files must stay inside the disposable checkout even when the parent `storage` directory is local. `bash -n scripts/test-isolated.sh` and all fourteen Python runner-contract tests passed, including both new nested-directory cases. These remain shell-runner tests using a PHP stand-in; PHP syntax checks, PHPUnit, Laravel and MySQL were not executed.


## Offer persistence source contract — 21 October 2026

The persisted-offer branch now checks Eloquent's public `exists` state instead of calling it as a method. The converted-price PHPUnit fixture marks its partial product model with `$product->exists = true`, so the test reaches the database persistence path that production uses.

`python3 tests/test_market_source_contracts.py` passed both source-contract tests. These checks prevent the property/method mismatch from returning, but they do not execute PHP, PHPUnit, Laravel or MySQL. The locked PHP 7.x application suite and disposable MySQL concurrency tests remain required.

## Verified isolated CI run (22 October 2026)

GitHub Actions run [39841904670](https://github.com/danloh22/eckmar/actions/runs/39841904670), commit `3cfd6582e077845b150b2d8b478cfb9e428ec845`, completed successfully: **104 tests, 557 assertions**. This supersedes the older notes above about the absence of an executable PHP runtime *for CI only*. The workflow runs PHP 7.4 in a disposable container, installs locked dependencies, and applies PHP 7.4 compatibility edits to the installed Mockery files **only inside that container**. Neither `composer.lock` nor production dependencies are updated by this workaround.

### Production-readiness gates not covered by this result

- **Supported runtime and dependency security:** Laravel 5.7 and PHP 7.4 are legacy/EOL. Inventory dependencies and plan a supported upgrade, then retest before any public deployment. A passing legacy suite is not a vulnerability assessment.
- **Real database behavior:** Validate migrations, rollback, unique constraints, transactions, deadlock retry, row locks, and simultaneous checkout/escrow flows against a disposable MySQL instance. SQLite in-memory tests cannot establish these properties.
- **External integrations:** Exercise wallet daemon/RPC connectivity, deposits, withdrawals, escrow settlement, coin-rate API failure handling, messaging, and search indexing against isolated test services or mocks. Do not use live funds, live wallet credentials, or production endpoints.
- **Deployment controls:** Verify secret management, `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, restricted admin access, worker/scheduler configuration, durable storage, backups, restoration drills, monitoring, and rollback on a separate staging environment.
- **Release decision:** PR #3 remains a draft until the above checks are evidenced. Do not equate green PHPUnit CI with a production approval or deploy automatically.


## Atomic experience-update regression — 27 October 2026

The `Experience` trait now delegates grants and deductions to Eloquent's database-side `increment` and `decrement` operations, checks that exactly one row changed, and refreshes the model only after success. `ExperienceAtomicUpdateTest` covers both operations and the zero-row failure path; the Python source contract rejects the former read-modify-save sequence.

This prevents lost deltas between concurrent workers, but it does not deduplicate a replayed business event. A durable event identity remains necessary before an identical reward or deduction can be proven exactly-once. No live database, wallet RPC, funds, migration or production endpoint is used by this test batch.


## Disposable-MySQL concurrent experience regression — 28 October 2026

The MySQL integration suite now starts two independent PHP processes that both load the same vendor before a shared barrier and then grant different XP deltas. Starting at 100 XP, the final database value must be 118 after grants of 7 and 11. This would expose the former stale-model read-modify-save race while exercising the database-side atomic increment used by the current trait.

The test uses only the disposable CI MySQL service and a minimal test-only vendors table. It does not contact wallets, payment services or production systems. It still does not make replayed business events idempotent.

The same harness also runs a mixed-operation race: one stale worker grants 11 XP while another takes 7 XP from a starting value of 100. The final value must be 104. The worker accepts only the explicit operations `grant` and `take`; any other operation fails closed. This covers concurrent database-side decrement behavior but does not provide business-event replay identity.

The experience worker also validates its amount as a canonical positive 32-bit integer. Runtime regressions reject unknown operations and amounts such as `0`, `-1`, `7oops`, `07` and overflow before a readiness file can be created. These checks protect the disposable test contract; they do not alter production XP semantics.

The disposable MySQL suite also calls checkout's production offer resolver without entering payment logic. It verifies the shared Product -> Offer lock order, accepts an active offer, and rejects a soft-deleted offer before any payment or inventory work. This reduces checkout/offer-edit deadlock risk and prevents stale cart entries from purchasing removed tiers.

A separate deterministic two-process regression holds persisted offer removal after its update and before commit, then starts the production checkout resolver while the product lock is still held. Checkout must wait, then reject the committed soft-deleted tier; database deadlocks, uncaught lock failures and an active removed tier fail the test. The checkout worker does not construct payment or wallet services.

The production resolver also rejects calls made outside an active database transaction before issuing its first query. This keeps its Product -> Offer `FOR UPDATE` contract from silently degrading under a future internal caller or refactor; the normal checkout and disposable workers already run it inside `DB::transaction()`.

`CheckoutTransactionRetryTest` also rejects a corrupt session cart before ledger service resolution, any transaction, reservation or `NewPurchase` event when a purchase ID is missing, non-string, blank, whitespace-padded or duplicated under different cart keys. This protects read-only ambiguous-commit recovery from counting the same committed purchase twice and keeps invalid-cart rejection independent of wallet/ledger initialization. It does not replace the pending durable, buyer-bound checkout request key.

The same suite now proves that an empty cart also fails before resolving `WalletLedgerService` from the application container. The regression opens no transaction and dispatches no purchase event; it does not construct a wallet service or perform any wallet/RPC operation.

Known checkout `RequestException` instances are also rethrown unchanged after logging. The regression preserves the exact exception object, status code and previous cause while proving no transaction, reservation or purchase event occurs and the session cart remains intact.

Checkout preflight also requires a positive native-integer quantity. Missing, zero, negative, fractional and numeric-string quantities fail before ledger resolution, transaction, reservation or event dispatch, and the corrupt session cart remains available for explicit recovery. This avoids permissive integer casts aliasing distinct corrupt inputs during ambiguous-commit comparison; it does not replace the pending durable buyer-bound request key.

A sequential retry now checks the stable purchase IDs against committed rows immediately after the side-effect-free identity preflight. When every row exists and matches, checkout clears the stale cart and emits the existing recovery event without reopening a transaction, reconstructing payment or reserving ledger funds. Partial/conflicting rows remain fail-closed. Concurrent first submissions and exactly-once event delivery still require a durable buyer-bound request key and transactional outbox.

Recovery identity preflight also rejects missing, non-string, empty or whitespace-padded buyer, vendor, offer, coin and purchase-type values before querying committed purchases. A shipping ID may be `null` for digital purchases; if present it must be a canonical non-empty string. PHPUnit coverage verifies rejection before recovery lookup, ledger reservation, transaction or event dispatch. This is not an authenticated-buyer binding and does not replace the pending durable request key.

Checkout preflight now also binds that validated buyer ID to the authenticated profile identity before recovery lookup or any ledger/transaction work. Runtime regressions cover a missing identity and an account-mismatched cart, preserve the cart, and prove that recovery, reservation, transaction and purchase-event dispatch are not reached. This is still not a durable request key or transactional outbox.
