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
        Event::fake([NewPurchase::class]);
        CheckoutPurchaseDouble::$startingStates = [];

        $purchase = new CheckoutPurchaseDouble();
        $purchase->id = 'successful-purchase-id';
        $purchase->message = 'buyer message';
        session()->put(Cart::SESSION_NAME, ['product-id' => $purchase]);

        $reserved = [];
        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldReceive('reservePurchase')->once()->andReturnUsing(function (Purchase $item) use (&$reserved) {
            $reserved[] = $item;
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
        Event::assertDispatched(NewPurchase::class, 1);
    }

    public function testEmptyCartRejectsCheckoutBeforeOpeningTransaction()
    {
        Event::fake([NewPurchase::class]);
        Log::shouldReceive('error')->once()->with('Add at least one product before opening checkout.');
        session()->forget(Cart::SESSION_NAME);

        $ledger = Mockery::mock(WalletLedgerService::class);
        $ledger->shouldNotReceive('reservePurchase');
        DB::shouldNotReceive('transaction');

        try {
            (new MakePurchasesRequest())->persist($ledger);
            $this->fail('Empty cart must be rejected.');
        } catch (RequestException $expected) {
            $this->assertSame('Add at least one product before opening checkout.', $expected->getMessage());
        }

        Event::assertNotDispatched(NewPurchase::class);
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
        $this->setRelation('offer', (object) ['title' => 'fresh offer']);
    }

    public function save(array $options = [])
    {
        $this->exists = true;
        return true;
    }
}
