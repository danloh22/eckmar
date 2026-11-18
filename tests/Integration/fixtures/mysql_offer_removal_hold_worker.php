<?php

use App\Http\Controllers\VendorController;
use App\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 7) {
    fwrite(STDERR, "Disposable MySQL held offer-removal worker invoked without its test contract.\n");
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
$quantity = $argv[2];
$readyFile = $argv[3];
$goFile = $argv[4];
$mutationHeldFile = $argv[5];
$releaseFile = $argv[6];

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
    if ($holdingMutation || strpos($normalized, 'update `offers`') === false || strpos($normalized, '`deleted`') === false) {
        return;
    }
    $holdingMutation = true;
    if (!touch($mutationHeldFile)) {
        throw new RuntimeException('Unable to signal held offer mutation.');
    }
    $deadline = microtime(true) + 10;
    while (!is_file($releaseFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out while holding the offer mutation.');
        }
        usleep(10000);
    }
});

$controller = new class extends VendorController {
    public function authorize($ability, $arguments = [])
    {
        return true;
    }
};
$controller->removeOffer($quantity, Product::findOrFail($productId));

echo json_encode(['status' => 'removed']);
