# Eckmar integration review — 2026-10-23

Scope: development branches only; no production deployment, wallet RPC, or money movement.

## Verified GitHub state
- Base: `codex/finde-probleme-in-der-codebasis-f84svo` at `20dc6ad5edcd9efc1cdcca9a7efac526da5d7452`.
- Draft PR #3: `codex/market-hardening-2026-10-09` at `4cc375d8f61c723da52093be642125bc30ceb4b4`.
- GitHub compare: hardening branch 96 commits ahead and 10 behind the base; merge base `df5e84c566722b4583940f8e832ac9ec64d03547`.
- GitHub reports PR #3 mergeable, but that is not evidence of semantic compatibility or a passed post-integration test.

## Overlap requiring inspection before integration
The ten base-only commits include the PHP 7.4 and MySQL workflows, checkout request validation, test runner, and checkout regression tests. Those paths also occur in the hardening comparison. In particular inspect:
- `app/Http/Requests/Cart/MakePurchasesRequest.php`
- `phpunit.xml`
- `scripts/test-isolated.sh`
- `tests/Unit/CheckoutTransactionRetryTest.php`
- `tests/Integration/MySqlWalletEscrowIntegrationTest.php`
- `.github/workflows/php74-isolated.yml`
- `.github/workflows/mysql-wallet-escrow.yml`

## Integration acceptance gate
1. Review `docs/REVIEW_2026-10-09.md` and `tests/README.md` from the hardening head and inspect the actual overlapping file contents.
2. Integrate onto a fresh branch based on the verified base SHA; do not force-push or rewrite PR #3.
3. Run compatible PHP 7.4 isolated tests and isolated MySQL wallet/escrow tests on the resulting integration SHA, verifying actual run IDs and assertions.
4. Review checkout idempotency, ambiguous transaction commits, and MySQL concurrency before declaring production readiness.
5. Keep PR draft until review; do not merge to production or call live wallet services.

This note is a verified integration checklist, not a claim that the merge or tests have been completed.
