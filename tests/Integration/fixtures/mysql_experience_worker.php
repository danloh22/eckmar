<?php

use App\Vendor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 6) {
    fwrite(STDERR, "Disposable MySQL experience worker invoked without its test contract.\n");
    exit(2);
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => 'mysql',
    'database.connections.mysql.port' => '3306',
    'database.connections.mysql.database' => 'eckmar_ci',
    'database.connections.mysql.username' => 'eckmar_ci',
    'database.connections.mysql.password' => 'ci_only_not_a_secret',
]);
DB::purge('mysql');

$vendorId = $argv[1];
$operation = $argv[2];
$amount = (int) $argv[3];
$readyFile = $argv[4];
$goFile = $argv[5];

if (!in_array($operation, ['grant', 'take'], true)) {
    fwrite(STDERR, "Unknown experience operation.\n");
    exit(5);
}

// Load before the barrier so the regression reproduces the stale-model
// read-modify-save race that previously lost one concurrent delta.
$vendor = Vendor::findOrFail($vendorId);

if (!touch($readyFile)) {
    fwrite(STDERR, "Unable to signal worker readiness.\n");
    exit(3);
}

$deadline = microtime(true) + 10;
while (!is_file($goFile)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the concurrency barrier.\n");
        exit(4);
    }
    usleep(10000);
}

if ($operation === 'grant') {
    $vendor->grantExperience($amount);
} else {
    $vendor->takeExperience($amount);
}
echo json_encode(['experience' => (int) $vendor->experience]);
