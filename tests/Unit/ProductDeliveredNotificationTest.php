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
            'purchase-delivered:' . $purchase->id,
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
        $this->assertArrayHasKey('purchase-delivered:' . $purchase->id, $vendorUser->deliveredKeys);
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
