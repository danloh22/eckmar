#!/usr/bin/env bash
# Safety preflight, not a network sandbox. Run only in a disposable checkout.
set -euo pipefail

fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $# -eq 0 ]] || fail 'This runner accepts no arguments; the committed PHPUnit configuration is mandatory.'
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd -- "$root"

for unsafe in .env .env.testing bootstrap/cache/config.php; do
    [[ ! -e "$root/$unsafe" && ! -L "$root/$unsafe" ]] || fail "Refusing test run: $unsafe exists. Use a disposable checkout without environment files or cached configuration."
done
php_bin="$(command -v php)" || fail 'PHP is unavailable; install the locked-compatible PHP 7.x runtime in an authorized isolated environment.'
for required in composer.lock phpunit.xml vendor/autoload.php vendor/bin/phpunit; do
    [[ -f "$root/$required" && ! -L "$root/$required" ]] || fail "Missing or symlinked $required; prepare locked dependencies directly in an authorized isolated environment."
done

# Do not forward shell database, wallet, mail or cloud credentials into PHP.
# A fixed, non-secret key is for disposable request tests only.
clean_env=(env -i "PATH=$PATH" LC_ALL=C APP_ENV=testing
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
    DB_CONNECTION=sqlite DB_DATABASE=:memory: BCRYPT_ROUNDS=4
    CACHE_DRIVER=array SESSION_DRIVER=array QUEUE_DRIVER=sync MAIL_DRIVER=array)
"${clean_env[@]}" "$php_bin" -r '
if (PHP_VERSION_ID < 70103 || PHP_VERSION_ID >= 80000) {
    fwrite(STDERR, "Tests require PHP 7.x compatible with composer.lock.\n"); exit(1);
}
foreach (["curl", "gmp", "xmlrpc", "pdo_sqlite"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Missing PHP extension: " . $extension . "\n"); exit(1);
    }
}
'
exec "${clean_env[@]}" "$php_bin" "$root/vendor/bin/phpunit" --configuration "$root/phpunit.xml"
