<?php

use App\Http\Requests\Admin\RemoveProductFromFeaturedReuqest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 5) {
    fwrite(STDERR, "Disposable MySQL featured-removal worker invoked without its test contract.\n");
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
$doneFile = $argv[4];

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

$request = RemoveProductFromFeaturedReuqest::create('/admin/featuredproducts/remove', 'POST', [
    'product_id' => $productId,
]);

try {
    $request->persist();
    $status = 'removed';
} catch (ModelNotFoundException $expected) {
    $status = 'missing';
}

if (!touch($doneFile)) {
    fwrite(STDERR, "Unable to signal worker completion.\n");
    exit(5);
}

echo json_encode(['status' => $status]);
