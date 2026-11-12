<?php

namespace Tests\Unit;

use App\Events\Purchase\ProductSent;
use App\Listeners\Purchase\ProductSentNotification;
use App\Purchase;
use Tests\TestCase;

class ProductSentNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendProductSentNotificationTwice()
    {
        $purchase = $this->purchaseWithNotificationBuyer($buyer);
        $event = new ProductSent($purchase);
        $listener = new ProductSentNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame([
            'purchase-sent:' . $purchase->id,
            'Your product has been sent by vendor [vendor]',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateProductSentNotification()
    {
        $purchase = $this->purchaseWithNotificationBuyer($buyer);
        $event = new ProductSent($purchase);
        $listener = new ProductSentNotification();
        $buyer->failAfterPersisting = true;

        try {
            $listener->handle($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $buyer->failAfterPersisting = false;
        $listener->handle($event);

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertArrayHasKey('purchase-sent:' . $purchase->id, $buyer->deliveredKeys);
    }

    private function purchaseWithNotificationBuyer(&$buyer)
    {
        $purchase = new Purchase();
        $purchase->id = 'sent-purchase-id';

        $buyer = new ProductSentNotificationUserDouble();
        $purchase->setRelation('buyer', $buyer);
        $purchase->setRelation('vendor', (object) [
            'user' => (object) ['username' => 'vendor'],
        ]);
        $purchase->setRelation('offer', (object) [
            'product' => (object) ['id' => 'product-id'],
        ]);

        return $purchase;
    }
}

class ProductSentNotificationUserDouble
{
    public $failAfterPersisting = false;
    public $lastNotification = [];
    public $notificationsSent = 0;
    public $deliveredKeys = [];

    public function __construct()
    {
        // no-op
    }

    public function notifyOnce($deliveryKey, $content, $routeName, $routeParams)
    {
        if (isset($this->deliveredKeys[$deliveryKey])) {
            return false;
        }

        $this->deliveredKeys[$deliveryKey] = true;
        $this->notificationsSent++;
        $this->lastNotification = [$deliveryKey, $content, $routeName, $routeParams];

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }

        return true;
    }
}
