<?php

use App\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 5) {
    fwrite(STDERR, "Disposable MySQL notification worker invoked without its test contract.\n");
    exit(2);
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => 'mysql',
    'database.connections.mysql.port' => '3306',
    'database.connections.mysql.database' => 'eckmar_ci',
    'database.connections.mysql.username' => 'eckmar_ci',
    'database.connections.mysql.password' => 'ci_only_not_a_secret',
    'bitmessage.enabled' => false,
]);
DB::purge('mysql');

$userId = $argv[1];
$deliveryKey = $argv[2];
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

$created = User::findOrFail($userId)->notifyOnce(
    $deliveryKey,
    'Your product has been purchased by [buyer]',
    'profile.sales.single',
    serialize(['sale' => '18181818-1818-4181-8181-181818181818'])
);

echo json_encode(['created' => $created]);
