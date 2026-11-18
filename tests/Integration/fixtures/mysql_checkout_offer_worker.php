<?php

use App\Exceptions\RequestException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes' || count($argv) !== 5) {
    fwrite(STDERR, "Disposable MySQL checkout-offer worker invoked without its test contract.\n");
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

$offerId = $argv[1];
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

$probe = new class($offerId) {
    use \App\Traits\Purchasable;

    public $offer_id;

    public function __construct($id)
    {
        $this->offer_id = $id;
    }

    public function resolve()
    {
        return $this->lockActiveOfferAndProduct();
    }
};

try {
    $resolved = DB::transaction(function () use ($probe) {
        return $probe->resolve();
    });
    if (!touch($doneFile)) {
        fwrite(STDERR, "Unable to signal worker completion.\n");
        exit(5);
    }
    echo json_encode([
        'status' => 'resolved',
        'offer_id' => $resolved[0]->id,
        'product_id' => $resolved[1]->id,
        'deleted' => (bool) $resolved[0]->deleted,
    ]);
} catch (RequestException $exception) {
    if (!touch($doneFile)) {
        fwrite(STDERR, "Unable to signal worker completion.\n");
        exit(5);
    }
    echo json_encode([
        'status' => 'rejected',
        'message' => $exception->getMessage(),
    ]);
}
