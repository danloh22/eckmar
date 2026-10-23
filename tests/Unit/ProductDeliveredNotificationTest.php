<?php

namespace Tests\Unit;

use App\Events\Purchase\ProductDelivered;
use App\Listeners\Purchase\ProductDeliveredNotification;
use App\Purchase;
use Tests\TestCase;

class ProductDeliveredNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendProductDeliveredNotificationTwice()
    {
        $purchase = $this->purchaseWithNotificationVendor($vendorUser);
        $event = new ProductDelivered($purchase);
        $listener = new ProductDeliveredNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $vendorUser->notificationsSent);
        $this->assertSame([
            'Your product has been marked delivered by buyer [buyer]',
            'profile.sales.single',
            serialize(['sales' => $purchase->id]),
        ], $vendorUser->lastNotification);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateProductDeliveredNotification()
    {
        $purchase = $this->purchaseWithNotificationVendor($vendorUser);
        $event = new ProductDelivered($purchase);
        $listener = new ProductDeliveredNotification();
        $vendorUser->failAfterPersisting = true;

        try {
            $listener->handle($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $vendorUser->failAfterPersisting = false;
        $listener->handle($event);

        $this->assertSame(1, $vendorUser->notificationsSent);
        $this->assertTrue($vendorUser->notificationQuery->delivered);
    }

    private function purchaseWithNotificationVendor(&$vendorUser)
    {
        $purchase = new Purchase();
        $purchase->id = 'delivered-purchase-id';

        $vendorUser = new ProductDeliveredNotificationUserDouble();
        $purchase->setRelation('buyer', (object) ['username' => 'buyer']);
        $purchase->setRelation('vendor', (object) ['user' => $vendorUser]);
        $purchase->setRelation('offer', (object) [
            'product' => (object) ['id' => 'product-id'],
        ]);

        return $purchase;
    }
}

class ProductDeliveredNotificationUserDouble
{
    public $failAfterPersisting = false;
    public $lastNotification = [];
    public $notificationQuery;
    public $notificationsSent = 0;

    public function __construct()
    {
        $this->notificationQuery = new ProductDeliveredNotificationQueryDouble();
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

class ProductDeliveredNotificationQueryDouble
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
