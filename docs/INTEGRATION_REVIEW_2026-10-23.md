# Eckmar integration review — 2026-10-23

Scope: development branches only; no production deployment, wallet RPC, or money movement.

## Verified GitHub state
- Base: `codex/finde-probleme-in-der-codebasis-f84svo` at `20dc6ad5edcd9efc1cdcca9a7efac526da5d7452`.
- Draft PR #3: `codex/market-hardening-2026-10-09` at `4cc375d8f61c723da52093be642125bc30ceb4b4`.
- GitHub compare: hardening branch 96 commits ahead and 10 behind the base; merge base `df5e84c566722b4583940f8e832ac9ec64d03547`.
- GitHub reports PR #3 mergeable, but that is not evidence of semantic compatibility or a passed post-integration test.

## Verified overlapping content (Git blob SHA equal on both refs)
- `app/Http/Requests/Cart/MakePurchasesRequest.php` — `831a2fef12ca7a8db414c151535a6ff255630613`
- `phpunit.xml` — previously verified equal
- `scripts/test-isolated.sh` — previously verified equal
- `tests/Unit/CheckoutTransactionRetryTest.php` — previously verified equal
- `.github/workflows/php74-isolated.yml` — `60a9da618331de53173bc60371f1b99ab1d9adb6`
- `.github/workflows/mysql-wallet-escrow.yml` — `c26eaa1793c53df5e3f734fd0d2cd45cea784144`
- `tests/Integration/MySqlWalletEscrowIntegrationTest.php` — `f164563f10d2aa1d7068468035e19bb4532fe016`

## Confirmed differences requiring semantic review
- `app/Http/Controllers/Admin/AdminController.php`: hardening `2fc9438400293728c3f279a90242fcfd2be6c7ca`, base `721e7c6fff42df91afac9c7c58f652a94d0e1beb`.
- `app/Http/Requests/Product/NewOfferRequest.php`: hardening `76167ff76f0507d8845fcb9f683efa4054a32a85`, base `9d8055b935b80f5e8e1b0fe020c98136bcd58c90`.
- `app/Policies/ProductPolicy.php`: hardening `67c8e3af0e3b36ac32890eef202b5c89d8abf93a`, base `994dfc5896fe01ef70ed45a2bcb3e4a8e04eb7ec`.
- `tests/Unit/ConvertedOfferPriceTest.php` exists on hardening head, not on base.

## Verified GitHub Actions jobs (each completed successfully)
- Hardening PHP 7.4: https://github.com/danloh22/eckmar/actions/runs/39846909774
- Hardening disposable MySQL: https://github.com/danloh22/eckmar/actions/runs/39846909685
- Base PHP 7.4: https://github.com/danloh22/eckmar/actions/runs/39849122687
- Base disposable MySQL: https://github.com/danloh22/eckmar/actions/runs/39849122745

These are separate-ref results, not post-integration results. The PR #6 documentation-only head has no associated PR-triggered runs returned by the commit-run lookup.

## Integration acceptance gate
1. Inspect actual divergent files and review `docs/REVIEW_2026-10-09.md` and `tests/README.md`.
2. Integrate onto a fresh branch based on the current verified base SHA; do not force-push or rewrite PR #3.
3. Run compatible PHP 7.4 isolated tests and disposable MySQL tests on the resulting integration SHA; verify run IDs and assertions.
4. Address persistent checkout idempotency, ambiguous commit results, and concurrent MySQL transactions.
5. Keep PR draft until review; no production merge, live wallet service, or real money movement.

Next safe step: inspect and integrate the remaining divergent controller, policy, request and regression-test files. Do not claim code integration or integrated CI until performed.
