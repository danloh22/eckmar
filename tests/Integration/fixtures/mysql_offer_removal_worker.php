<?php

use App\Http\Controllers\VendorController;
use App\Offer;
use App\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 5) {
    fwrite(STDERR, "Disposable MySQL offer-removal worker invoked without its test contract.\n");
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

$controller = new class extends VendorController {
    public function authorize($ability, $arguments = [])
    {
        return true;
    }
};
$controller->removeOffer($quantity, Product::findOrFail($productId));

$active = Offer::where('product_id', $productId)
    ->where('min_quantity', (int) $quantity)
    ->where('deleted', false)
    ->exists();

echo json_encode(['status' => $active ? 'kept' : 'removed']);
