<?php

namespace Tests\Integration;

use App\MarketFeeWallet;
use App\Purchase;
use App\Services\WalletLedgerService;
use App\User;
use App\Wallet;
use App\WalletEscrowHold;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs only in the disposable MySQL service from mysql-wallet-escrow.yml.
 * No real wallet RPC or network payment service is contacted.
 */
class MySqlWalletEscrowIntegrationTest extends TestCase
{
    protected function setUp()
    {
        parent::setUp();
        if (getenv('ECKMAR_DISPOSABLE_MYSQL') !== 'yes') {
            $this->markTestSkipped('Requires explicit disposable MySQL CI opt-in.');
        }
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'mysql',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'eckmar_ci',
            'database.connections.mysql.username' => 'eckmar_ci',
            'database.connections.mysql.password' => 'ci_only_not_a_secret',
            'coins.atomic_decimals.stb' => 8,
            'marketplace.market_fee_percent' => 3,
        ]);
        DB::purge('mysql');
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->createTables();
    }

    protected function tearDown()
    {
        if (getenv('ECKMAR_DISPOSABLE_MYSQL') === 'yes') {
            Schema::dropIfExists('wallet_escrow_holds');
            Schema::dropIfExists('wallet_ledger_entries');
            Schema::dropIfExists('market_fee_wallets');
            Schema::dropIfExists('wallets');
            Schema::dropIfExists('purchases');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTables()
    {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamps();
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('buyer_id');
            $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable();
            $t->string('coin', 10);
            $t->enum('status', ['active', 'frozen'])->default('active');
            $t->decimal('available_atomic', 36, 0)->default(0);
            $t->decimal('reserved_atomic', 36, 0)->default(0);
            $t->unique(['user_id', 'coin']);
            $t->timestamps();
        });
        Schema::create('wallet_ledger_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('wallet_id');
            $t->string('coin', 10);
            $t->string('type');
            $t->decimal('available_delta_atomic', 36, 0);
            $t->decimal('reserved_delta_atomic', 36, 0);
            $t->string('reference_type');
            $t->uuid('reference_id')->nullable();
            $t->string('idempotency_key', 120)->unique();
            $t->uuid('created_by')->nullable();
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('market_fee_wallets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('coin', 10)->unique();
            $t->text('address');
            $t->uuid('wallet_id')->nullable();
            $t->timestamps();
        });
        Schema::create('wallet_escrow_holds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('purchase_id')->unique();
            $t->uuid('buyer_wallet_id');
            $t->string('coin', 10);
            $t->decimal('amount_atomic', 36, 0);
            $t->string('status')->default('active');
            $t->uuid('released_to')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
        });
    }

    public function testEscrowReserveReleaseAndIdempotencyOnMySql()
    {
        $service = new WalletLedgerService();
        $buyer = new User();
        $buyer->id = '11111111-1111-4111-8111-111111111111';
        $buyer->exists = true;
        $seller = new User();
        $seller->id = '22222222-2222-4222-8222-222222222222';
        $seller->exists = true;
        DB::table('users')->insert([
            ['id' => $buyer->id], ['id' => $seller->id],
        ]);
        $purchase = new Purchase();
        $purchase->id = '33333333-3333-4333-8333-333333333333';
        $purchase->buyer_id = $buyer->id;
        $purchase->coin_name = 'stb';
        $purchase->to_pay = '1.00000000';
        DB::table('purchases')->insert(['id' => $purchase->id, 'buyer_id' => $buyer->id]);
        $wallet = $service->walletFor($buyer->id, 'stb');
        $service->book($wallet, 'deposit', '100000000', '0', 'deposit', null, 'ci-deposit');
        $hold = $service->reservePurchase($purchase);
        $this->assertSame('100000000', (string) $hold->amount_atomic);
        $this->assertSame('0', (string) $wallet->fresh()->available_atomic);
        $this->assertSame('100000000', (string) $wallet->fresh()->reserved_atomic);
        $this->assertSame($hold->id, $service->reservePurchase($purchase)->id);
        $this->assertSame(2, DB::table('wallet_ledger_entries')->count());

        $feeWallet = new MarketFeeWallet();
        $feeWallet->coin = 'stb';
        $feeWallet->address = 'ci-only-market-fee-wallet';
        $feeWallet->save();
        $service->releasePurchase($purchase, $seller);
        $this->assertSame('released', WalletEscrowHold::findOrFail($hold->id)->status);
        $this->assertSame('0', (string) $wallet->fresh()->reserved_atomic);
        $this->assertSame('97000000', (string) $service->walletFor($seller->id, 'stb')->available_atomic);
        $this->assertSame('3000000', (string) $service->marketWalletFor('stb')->available_atomic);
        $this->assertSame(5, DB::table('wallet_ledger_entries')->count());
        try {
            $service->releasePurchase($purchase, $seller);
            $this->fail('An already settled escrow must not be paid twice.');
        } catch (\App\Exceptions\RequestException $expected) {
            $this->assertSame('Purchase escrow was already settled.', $expected->getMessage());
        }
        $this->assertSame(5, DB::table('wallet_ledger_entries')->count());
        $this->assertSame('97000000', (string) $service->walletFor($seller->id, 'stb')->fresh()->available_atomic);
    }


    public function testEscrowRefundReturnsReservedFundsWithoutExternalRpc()
    {
        $service = new WalletLedgerService();
        $buyerId = '44444444-4444-4444-8444-444444444444';
        $purchaseId = '55555555-5555-4555-8555-555555555555';
        DB::table('users')->insert(['id' => $buyerId]);
        DB::table('purchases')->insert(['id' => $purchaseId, 'buyer_id' => $buyerId]);

        $purchase = new Purchase();
        $purchase->id = $purchaseId;
        $purchase->buyer_id = $buyerId;
        $purchase->coin_name = 'stb';
        $purchase->to_pay = '0.50000000';

        $wallet = $service->walletFor($buyerId, 'stb');
        $service->book($wallet, 'deposit', '50000000', '0', 'deposit', null, 'refund-fixture');
        $hold = $service->reservePurchase($purchase);
        $service->refundPurchase($purchase);

        $this->assertSame('refunded', WalletEscrowHold::findOrFail($hold->id)->status);
        $this->assertSame('50000000', (string) $wallet->fresh()->available_atomic);
        $this->assertSame('0', (string) $wallet->fresh()->reserved_atomic);
        $this->assertSame(3, DB::table('wallet_ledger_entries')->count());
    }

    public function testLedgerRejectsOverdraftAndDuplicateKeys()
    {
        $service = new WalletLedgerService();
        $wallet = $service->walletFor(null, 'stb');
        $service->book($wallet, 'admin_credit', '50', '0', 'test', null, 'once');
        $service->book($wallet, 'admin_credit', '50', '0', 'test', null, 'once');
        $this->assertSame('50', (string) $wallet->fresh()->available_atomic);
        $this->assertSame(1, DB::table('wallet_ledger_entries')->count());
        try {
            $service->book($wallet, 'admin_debit', '-51', '0', 'test', null, 'overdraft');
            $this->fail('Overdraft must be rejected.');
        } catch (\App\Exceptions\RequestException $expected) {
            $this->assertSame('50', (string) $wallet->fresh()->available_atomic);
        }
    }
}
