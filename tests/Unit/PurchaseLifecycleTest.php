<?php

namespace Tests\Unit;

use App\Exceptions\RequestException;
use App\Purchase;
use Tests\TestCase;

class PurchaseLifecycleTest extends TestCase
{
    public function testDeliveredPurchaseCannotBeCanceledAgain()
    {
        $purchase = new Purchase();
        $purchase->state = 'delivered';

        $this->expectException(RequestException::class);
        $purchase->cancel();
    }

    public function testDisputedPurchaseCannotBypassResolutionThroughCancellation()
    {
        $purchase = new Purchase();
        $purchase->state = 'disputed';

        $this->expectException(RequestException::class);
        $purchase->cancel();
    }
}
