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
            'Your product has been purchased by [buyer]',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $user->lastNotification);
        $this->assertSame([
            ['route_name', 'profile.sales.single'],
            ['route_params', serialize(['sale' => $purchase->id])],
            ['route_name', 'profile.sales.single'],
            ['route_params', serialize(['sale' => $purchase->id])],
        ], $user->notificationQuery->filters);
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
        $this->assertTrue($user->notificationQuery->delivered);
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
    public $notificationQuery;
    public $notificationsSent = 0;

    public function __construct()
    {
        $this->notificationQuery = new PurchaseNotificationQueryDouble();
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

class PurchaseNotificationQueryDouble
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
