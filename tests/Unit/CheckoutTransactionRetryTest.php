<?php

namespace Tests\Unit;

use App\Events\Purchase\NewPurchase;
use App\Exceptions\RequestException;
use App\Http\Requests\Cart\MakePurchasesRequest;
use App\Marketplace\Cart;
use App\Purchase;
use App\Services\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CheckoutTransactionRetryTest extends TestCase
{
    public function testSuccessfulCheckoutClearsCartAndEmitsOneEvent()
    {
        // Intercept dispatch directly so no application listeners are executed.
        Event::shouldReceive('dispatch')->once()->with(Mockery::type(NewPurchase::class));
        CheckoutPurchaseDouble::$startingStates = [];

        $purchase = new CheckoutPurchaseDouble();
        $purchase->id = 'successful-purchase-id';
        $purchase->message = 'buyer message';
        $purchase->setRelation('offer', (object) ['product' => (object) ['id' => 'product-id']]);
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $reserved = [];
        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldReceive('reservePurchase')->once()->andReturnUsing(function (Purchase $item) use (&$reserved) {
            $reserved[] = $item;
            return new \App\WalletEscrowHold();
        });
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            return $callback();
        });

        (new MakePurchasesRequest())->persist($ledger);

        $this->assertCount(1, $reserved);
        $this->assertNotSame($purchase, $reserved[0]);
        $this->assertFalse($purchase->exists);
        $this->assertSame('buyer message', $purchase->message);
        $this->assertEmpty(Cart::getCart()->items());
        // The dispatch mock verifies exactly one event without invoking listeners.
    }

    public function testSuccessfulCheckoutRunsTransactionAndReservationOnce()
    {
        // A normal checkout must execute one transaction and one reservation.
        // Post-commit session failures require a separate recovery test.
        Event::fake([NewPurchase::class]);

        $purchase = new CheckoutPurchaseDouble();
        $purchase->id = 'post-commit-purchase-id';
        $purchase->message = 'buyer message';
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $reservationCount = 0;
        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldReceive('reservePurchase')->once()->andReturnUsing(function () use (&$reservationCount) {
            $reservationCount++;
            return new \App\WalletEscrowHold();
        });

        $transactionCount = 0;
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use (&$transactionCount) {
            $transactionCount++;
            return $callback();
        });

        // No exception is expected on the successful checkout path.
        (new MakePurchasesRequest())->persist($ledger);
        $this->assertSame(1, $transactionCount);
        $this->assertSame(1, $reservationCount);
        $this->assertEmpty(Cart::getCart()->items());
    }

    public function testEmptyCartRejectsCheckoutBeforeOpeningTransaction()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->once()->with('Add at least one product before opening checkout.');
        session()->forget(Cart::SESSION_NAME);

        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldNotReceive('reservePurchase');

        try {
            (new MakePurchasesRequest())->persist($ledger);
            $this->fail('Empty cart must be rejected.');
        } catch (RequestException $expected) {
            $this->assertSame('Add at least one product before opening checkout.', $expected->getMessage());
        }

        Event::assertNotDispatched(NewPurchase::class);
    }

    public function testAmbiguousCommitRecoversMatchingPersistedPurchases()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->once()->with('Duplicate entry for key purchases.PRIMARY');

        $purchase = new Purchase();
        $purchase->id = 'recoverable-purchase-id';
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';
        $purchase->offer_id = 'offer-id';
        $purchase->shipping_id = null;
        $purchase->quantity = 2;
        $purchase->coin_name = 'btc';
        $purchase->type = 'normal';
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $committed = new Purchase();
        $committed->forceFill($purchase->getAttributes());
        $committed->exists = true;
        $committed->setRelation('buyer', (object) ['id' => 'buyer-id']);
        $committed->setRelation('vendor', (object) ['id' => 'vendor-id']);
        $committed->setRelation('offer', (object) ['product' => (object) ['id' => 'product-id']]);

        $request = new CheckoutRecoveryRequestDouble();
        $request->committedPurchases[$purchase->id] = $committed;

        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldNotReceive('reservePurchase');
        $duplicate = new \PDOException('Duplicate entry for key purchases.PRIMARY', 23000);
        $duplicate->errorInfo = ['23000', 1062, 'Duplicate entry for key purchases.PRIMARY'];
        DB::shouldReceive('transaction')->once()->andThrow($duplicate);

        $request->persist($ledger);

        $this->assertEmpty(Cart::getCart()->items());
        Event::assertDispatched(NewPurchase::class, function (NewPurchase $event) use ($committed) {
            return $event->purchase === $committed;
        });
    }

    /**
     * @dataProvider ambiguousCommitDatabaseErrors
     */
    public function testAmbiguousCommitClassifierAcceptsOnlyConnectionLosses(
        $sqlState,
        $driverCode,
        $expected
    ) {
        $exception = new \PDOException('database connection outcome', 0);
        $exception->errorInfo = [$sqlState, $driverCode, 'database connection outcome'];

        $method = new \ReflectionMethod(MakePurchasesRequest::class, 'isAmbiguousCommitFailure');
        $method->setAccessible(true);

        $this->assertSame(
            $expected,
            $method->invoke(new MakePurchasesRequest(), $exception)
        );
    }

    public function ambiguousCommitDatabaseErrors()
    {
        return [
            'standard connection failure' => ['08006', 7, true],
            'mysql server gone away' => ['HY000', 2006, true],
            'mysql lost connection' => ['HY000', 2013, true],
            'mysql deadlock' => ['40001', 1213, false],
            'mysql lock timeout' => ['HY000', 1205, false],
            'mysql generic error' => ['HY000', 1105, false],
        ];
    }

    public function testAmbiguousDisconnectRecoversCommittedPurchasesWithoutReplay()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->once()->with('Lost connection during COMMIT');

        $purchase = new Purchase();
        $purchase->id = 'disconnect-recovery-purchase-id';
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';
        $purchase->offer_id = 'offer-id';
        $purchase->shipping_id = null;
        $purchase->quantity = 2;
        $purchase->coin_name = 'btc';
        $purchase->type = 'normal';
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $committed = new Purchase();
        $committed->forceFill($purchase->getAttributes());
        $committed->exists = true;
        $committed->setRelation('buyer', (object) ['id' => 'buyer-id']);
        $committed->setRelation('vendor', (object) ['id' => 'vendor-id']);
        $committed->setRelation('offer', (object) ['product' => (object) ['id' => 'product-id']]);

        $request = new CheckoutRecoveryRequestDouble();
        $request->committedPurchases[$purchase->id] = $committed;

        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldNotReceive('reservePurchase');
        $disconnect = new \PDOException('Lost connection during COMMIT', 0);
        $disconnect->errorInfo = ['HY000', 2013, 'Lost connection during COMMIT'];
        DB::shouldReceive('transaction')->once()->andThrow($disconnect);

        $request->persist($ledger);

        $this->assertEmpty(Cart::getCart()->items());
        Event::assertDispatched(NewPurchase::class, 1);
        Event::assertDispatched(NewPurchase::class, function (NewPurchase $event) use ($committed) {
            return $event->purchase === $committed;
        });
    }

    public function testAmbiguousDisconnectWithoutCommittedRowsFailsWithoutReplay()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->once()->with('Lost connection before COMMIT');

        $purchase = new Purchase();
        $purchase->id = 'uncommitted-disconnect-purchase-id';
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';
        $purchase->offer_id = 'offer-id';
        $purchase->quantity = 1;
        $purchase->coin_name = 'btc';
        $purchase->type = 'normal';
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $request = new CheckoutRecoveryRequestDouble();

        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldNotReceive('reservePurchase');
        $disconnect = new \PDOException('Lost connection before COMMIT', 0);
        $disconnect->errorInfo = ['08006', 7, 'Lost connection before COMMIT'];
        DB::shouldReceive('transaction')->once()->andThrow($disconnect);

        try {
            $request->persist($ledger);
            $this->fail('Uncommitted ambiguous checkout must fail without replay.');
        } catch (RequestException $exception) {
            $this->assertSame('Error happened! Try again later!', $exception->getMessage());
        }

        $this->assertSame($purchase, session()->get(Cart::SESSION_NAME)['product-id']);
        Event::assertNotDispatched(NewPurchase::class);
    }

    public function testAmbiguousCommitFailsClosedOnConflictingIdentity()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->once()->with('Duplicate entry for key purchases.PRIMARY');
        Log::shouldReceive('error')->once()->with('Checkout status is inconsistent. Please contact support.');

        $purchase = new Purchase();
        $purchase->id = 'conflicting-purchase-id';
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';
        $purchase->offer_id = 'offer-id';
        $purchase->quantity = 1;
        $purchase->coin_name = 'btc';
        $purchase->type = 'normal';
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $committed = new Purchase();
        $committed->forceFill($purchase->getAttributes());
        $committed->buyer_id = 'different-buyer-id';
        $committed->exists = true;

        $request = new CheckoutRecoveryRequestDouble();
        $request->committedPurchases[$purchase->id] = $committed;

        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldNotReceive('reservePurchase');
        $duplicate = new \PDOException('Duplicate entry for key purchases.PRIMARY', 23000);
        $duplicate->errorInfo = ['23000', 1062, 'Duplicate entry for key purchases.PRIMARY'];
        DB::shouldReceive('transaction')->once()->andThrow($duplicate);

        try {
            $request->persist($ledger);
            $this->fail('Conflicting persisted identity must fail closed.');
        } catch (RequestException $exception) {
            $this->assertSame('Checkout status is inconsistent. Please contact support.', $exception->getMessage());
        }

        $this->assertSame($purchase, session()->get(Cart::SESSION_NAME)['product-id']);
        Event::assertNotDispatched(NewPurchase::class);
    }

    public function testPostCommitSessionClearFailureRecoversWithoutRepeatingReservation()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->once()->with('Session store write failed after commit');

        $purchase = new CheckoutPurchaseDouble();
        $purchase->id = 'session-recovery-purchase-id';
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';
        $purchase->offer_id = 'offer-id';
        $purchase->shipping_id = null;
        $purchase->quantity = 2;
        $purchase->coin_name = 'btc';
        $purchase->type = 'normal';
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $committed = new Purchase();
        $committed->forceFill($purchase->getAttributes());
        $committed->exists = true;
        $committed->setRelation('buyer', (object) ['id' => 'buyer-id']);
        $committed->setRelation('vendor', (object) ['id' => 'vendor-id']);
        $committed->setRelation('offer', (object) ['product' => (object) ['id' => 'product-id']]);

        $request = new SessionClearFailureRequestDouble();
        $request->committedPurchases[$purchase->id] = $committed;

        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldReceive('reservePurchase')->once()->andReturn(new \App\WalletEscrowHold());
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            return $callback();
        });

        $request->persist($ledger);

        $this->assertSame(2, $request->clearAttempts);
        $this->assertEmpty(Cart::getCart()->items());
        Event::assertDispatched(NewPurchase::class, 1);
        Event::assertDispatched(NewPurchase::class, function (NewPurchase $event) use ($committed) {
            return $event->purchase === $committed;
        });
    }

    /**
     * @dataProvider duplicateIdentityDatabaseErrors
     */
    public function testDuplicateRecoveryOnlyAcceptsUniqueConstraintErrors(
        $sqlState,
        $driverCode,
        $message,
        $expected
    ) {
        $exception = new \PDOException($message, (int) $sqlState);
        $exception->errorInfo = [$sqlState, $driverCode, $message];

        $method = new \ReflectionMethod(MakePurchasesRequest::class, 'isDuplicateIdentityFailure');
        $method->setAccessible(true);

        $this->assertSame(
            $expected,
            $method->invoke(new MakePurchasesRequest(), $exception)
        );
    }

    public function duplicateIdentityDatabaseErrors()
    {
        return [
            'mysql purchase primary key' => ['23000', 1062, "Duplicate entry 'purchase-id' for key 'purchases.PRIMARY'", true],
            'mysql unqualified primary key' => ['23000', 1062, "Duplicate entry 'purchase-id' for key 'PRIMARY'", true],
            'mysql unquoted qualified primary key' => ['23000', 1062, 'Duplicate entry for key purchases.PRIMARY', true],
            'mysql backtick primary key' => ['23000', 1062, 'Duplicate entry purchase-id for key `purchases.PRIMARY`', true],
            'postgres purchase primary key' => ['23505', 7, 'duplicate key value violates unique constraint "purchases_pkey"', true],
            'sqlite purchase primary key' => ['23000', 19, 'UNIQUE constraint failed: purchases.id', true],
            'mysql unrelated unique key' => ['23000', 1062, "Duplicate entry 'buyer-btc' for key 'wallets.user_id_coin_unique'", false],
            'postgres unrelated unique key' => ['23505', 7, 'duplicate key value violates unique constraint "wallets_user_coin_key"', false],
            'sqlite unrelated unique key' => ['23000', 19, 'UNIQUE constraint failed: wallets.user_id, wallets.coin', false],
            'deadlock with duplicate-like text' => ['40001', 1213, 'Deadlock after duplicate entry diagnostic', false],
            'misleading sqlite-like message' => ['HY000', 5, 'unique constraint failed while handling I/O', false],
            'mysql foreign key' => ['23000', 1452, 'Cannot add or update a child row', false],
            'mysql deadlock' => ['40001', 1213, 'Deadlock found when trying to get lock', false],
        ];
    }

    public function testFailedCheckoutsDoNotReplayOrMutateTheSessionPurchase()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->twice();
        CheckoutPurchaseDouble::$startingStates = [];

        $purchase = new CheckoutPurchaseDouble();
        $purchase->id = 'original-purchase-id';
        $purchase->state = 'purchased';
        $purchase->message = 'original buyer message';
        $originalOffer = (object) ['title' => 'original offer'];
        $purchase->setRelation('offer', $originalOffer);
        $originalAttributes = $purchase->getAttributes();
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $attemptedPurchases = [];
        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldReceive('reservePurchase')->andReturnUsing(function (Purchase $attempt) use (&$attemptedPurchases) {
            $attemptedPurchases[] = $attempt;
            throw new \PDOException('Deadlock found when trying to get lock');
        });

        $executions = 0;
        // Simulate the retry contract, not database locking or rollback itself.
        DB::shouldReceive('transaction')->twice()->andReturnUsing(function ($callback, $attempts = 1) use (&$executions) {
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $executions++;
                try {
                    return $callback();
                } catch (\PDOException $exception) {
                    if ($attempt === $attempts) {
                        throw $exception;
                    }
                }
            }
        });

        for ($request = 0; $request < 2; $request++) {
            $failure = null;
            try {
                (new MakePurchasesRequest())->persist($ledger);
            } catch (RequestException $exception) {
                $failure = $exception;
            }
            $this->assertInstanceOf(RequestException::class, $failure);
            $this->assertSame('Error happened! Try again later!', $failure->getMessage());
            $this->assertSame($request + 1, $executions, 'A failed checkout must run only once.');
            $this->assertFalse($purchase->exists);
            $this->assertSame($originalAttributes, $purchase->getAttributes());
            $this->assertSame($originalOffer, $purchase->getRelation('offer'));
            $this->assertSame('original offer', $originalOffer->title);
            $this->assertSame($purchase, session()->get(Cart::SESSION_NAME)['product-id']);
        }

        $this->assertCount(2, $attemptedPurchases);
        $this->assertNotSame($attemptedPurchases[0], $attemptedPurchases[1]);
        foreach ($attemptedPurchases as $attempt) {
            $this->assertNotSame($purchase, $attempt);
            $this->assertSame('original-purchase-id', $attempt->id);
            $this->assertTrue($attempt->exists);
        }
        $this->assertSame([
            [false, 'original buyer message', []],
            [false, 'original buyer message', []],
        ], CheckoutPurchaseDouble::$startingStates);
        Event::assertNotDispatched(NewPurchase::class);
    }
}

// Mutations affect the actual clone ($this), not a closure's original model.
class CheckoutPurchaseDouble extends Purchase
{
    public static $startingStates = [];

    public function purchased()
    {
        self::$startingStates[] = [$this->exists, $this->message, $this->getRelations()];
        $this->message = 'encrypted message';
        $this->state = 'sent';
        if ($this->relationLoaded('offer')) {
            $this->getRelation('offer')->title = 'mutated cached offer';
        }
        $this->setRelation('offer', (object) ['title' => 'fresh offer', 'product' => (object) ['id' => 'product-id']]);
    }

    public function save(array $options = [])
    {
        $this->exists = true;
        return true;
    }
}


class CheckoutRecoveryRequestDouble extends MakePurchasesRequest
{
    public $committedPurchases = [];

    protected function findCommittedPurchase($id)
    {
        return $this->committedPurchases[$id] ?? null;
    }
}

class SessionClearFailureRequestDouble extends CheckoutRecoveryRequestDouble
{
    public $clearAttempts = 0;

    protected function clearCheckoutCart()
    {
        $this->clearAttempts++;
        if ($this->clearAttempts === 1) {
            throw new \RuntimeException('Session store write failed after commit');
        }

        parent::clearCheckoutCart();
    }
}
