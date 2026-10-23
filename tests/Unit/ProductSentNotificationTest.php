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
        $this->assertTrue($buyer->notificationQuery->delivered);
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
    public $notificationQuery;
    public $notificationsSent = 0;

    public function __construct()
    {
        $this->notificationQuery = new ProductSentNotificationQueryDouble();
    }

    public function notifications()
    {
        return $this->notificationQuery;
    }

    public function notify($content, $routeName, $routeParams)
    {
        $this->notificationsSent++;
        $this->lastNotification = [$content, $routeName, $routeParams];
        $this->notificationQuery->delivered = true;

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }
    }
}

class ProductSentNotificationQueryDouble
{
    public $delivered = false;
    public $filters = [];

    public function where($column, $value)
    {
        $this->filters[] = [$column, $value];

        return $this;
    }

    public function exists()
    {
        return $this->delivered;
    }
}
