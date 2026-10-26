<?php

use App\Exceptions\RequestException;
use App\Http\Requests\Product\NewOfferRequest;
use App\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 6) {
    fwrite(STDERR, "Disposable MySQL offer worker invoked without its test contract.\n");
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
$price = $argv[2];
$readyFile = $argv[3];
$goFile = $argv[4];
$minQuantity = $argv[5];

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

$request = NewOfferRequest::create('/', 'POST', [
    'min_quantity' => $minQuantity,
    'price' => $price,
]);

try {
    $request->persist(Product::findOrFail($productId));
    echo json_encode(['status' => 'created']);
} catch (RequestException $exception) {
    echo json_encode([
        'status' => 'duplicate',
        'message' => $exception->getMessage(),
    ]);
}
