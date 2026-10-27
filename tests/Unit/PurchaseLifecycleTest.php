<?php

namespace Tests\Unit;

use App\Exceptions\RequestException;
use App\Purchase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PurchaseLifecycleTest extends TestCase
{
    public function testDeliveredPurchaseCannotBeCanceledAgain()
    {
        $this->assertPersistedStateRejectsCancellation('delivered');
    }

    public function testDisputedPurchaseCannotBypassResolutionThroughCancellation()
    {
        $this->assertPersistedStateRejectsCancellation('disputed');
    }

    private function assertPersistedStateRejectsCancellation(string $persistedState)
    {
        $connection = DB::connection();
        // Fail before any schema writes if cached/environment config selects a
        // real database. This test needs only a disposable minimal SQLite table.
        $this->assertSame('sqlite', $connection->getConfig('driver'));
        $this->assertSame(':memory:', $connection->getConfig('database'));
        $this->assertSame($connection, (new Purchase())->getConnection());

        $schema = $connection->getSchemaBuilder();
        $schema->create('purchases', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('state');
        });

        try {
            $id = 'purchase-' . $persistedState;
            $connection->table('purchases')->insert(['id' => $id, 'state' => $persistedState]);
            Log::shouldReceive('error')->once();

            // A stale caller still thinks this is cancelable. The persisted
            // state must be reloaded and rejected before product/wallet access.
            $purchase = new Purchase();
            $purchase->id = $id;
            $purchase->state = 'purchased';
            $purchase->exists = true;
            $failure = null;
            try {
                $purchase->cancel();
            } catch (RequestException $exception) {
                $failure = $exception;
            }

            $this->assertInstanceOf(RequestException::class, $failure);
            // A missing row/table or connection error produces a different
            // message and therefore cannot falsely satisfy this regression test.
            $this->assertSame('Only purchased or sent orders can be canceled.', $failure->getMessage());
            $this->assertSame($persistedState, $connection->table('purchases')->where('id', $id)->value('state'));
            $this->assertSame(1, $connection->table('purchases')->count());
        } finally {
            $schema->drop('purchases');
        }
    }
}
