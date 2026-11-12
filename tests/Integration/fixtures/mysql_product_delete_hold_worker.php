<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 6) {
    fwrite(STDERR, "Disposable MySQL held product-delete worker invoked without its test contract.\n");
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

$productId = $argv[1];
$readyFile = $argv[2];
$goFile = $argv[3];
$mutationHeldFile = $argv[4];
$releaseFile = $argv[5];

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

$holdingMutation = false;
DB::listen(function (QueryExecuted $query) use (&$holdingMutation, $mutationHeldFile, $releaseFile) {
    $normalized = strtolower(preg_replace('/\s+/', ' ', $query->sql));
    if ($holdingMutation || strpos($normalized, 'delete from `products`') === false) {
        return;
    }
    $holdingMutation = true;
    if (!touch($mutationHeldFile)) {
        throw new RuntimeException('Unable to signal held product deletion.');
    }
    $deadline = microtime(true) + 10;
    while (!is_file($releaseFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out while holding the product deletion.');
        }
        usleep(10000);
    }
});

DB::transaction(function () use ($productId) {
    if (DB::table('products')->where('id', $productId)->delete() !== 1) {
        throw new RuntimeException('Expected exactly one product deletion.');
    }
});

echo json_encode(['status' => 'deleted']);
