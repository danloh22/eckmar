<?php

namespace Tests\Unit;

use App\Events\Purchase\NewPurchase;
use App\Listeners\Purchase\ProductBoughtNotification;
use App\Purchase;
use Tests\TestCase;

class ProductBoughtNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendPurchaseNotificationTwice()
    {
        $purchase = $this->purchaseWithNotificationUser($user);
        $event = new NewPurchase($purchase);
        $listener = new ProductBoughtNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $user->notificationsSent);
        $this->assertSame([
            'purchase-bought:' . $purchase->id,
            'Your product has been purchased by [buyer]',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $user->lastNotification);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateNotification()
    {
        $purchase = $this->purchaseWithNotificationUser($user);
        $event = new NewPurchase($purchase);
        $listener = new ProductBoughtNotification();
        $user->failAfterPersisting = true;

        try {
            $listener->handle($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $user->failAfterPersisting = false;
        $listener->handle($event);

        $this->assertSame(1, $user->notificationsSent);
        $this->assertArrayHasKey('purchase-bought:' . $purchase->id, $user->deliveredKeys);
    }

    private function purchaseWithNotificationUser(&$user)
    {
        $purchase = new Purchase();
        $purchase->id = 'replayed-purchase-id';
        $purchase->setRelation('buyer', (object) ['username' => 'buyer']);
        $purchase->setRelation('offer', (object) ['product' => (object) ['id' => 'product-id']]);

        $user = new PurchaseNotificationUserDouble();
        $purchase->setRelation('vendor', (object) ['user' => $user]);

        return $purchase;
    }
}

class PurchaseNotificationUserDouble
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
