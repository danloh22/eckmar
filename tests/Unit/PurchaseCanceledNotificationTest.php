<?php

namespace Tests\Unit;

use App\Events\Purchase\CanceledPurchase;
use App\Listeners\Purchase\PurchaseCanceledNotification;
use App\Purchase;
use App\User;
use Tests\TestCase;

class PurchaseCanceledNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendCanceledPurchaseNotificationsTwice()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new CanceledPurchase($purchase);
        $listener = new PurchaseCanceledNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame([
            'purchase-canceled-vendor:' . $purchase->id,
            'Your sale has been canceled.',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
        $this->assertSame([
            'purchase-canceled-buyer:' . $purchase->id,
            'Your purchase has been canceled.',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateCanceledPurchaseNotification()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new CanceledPurchase($purchase);
        $listener = new PurchaseCanceledNotification();
        $vendor->failAfterPersisting = true;

        try {
            $listener->handle($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $vendor->failAfterPersisting = false;
        $listener->handle($event);

        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame([
            'purchase-canceled-vendor:' . $purchase->id,
            'Your sale has been canceled.',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
        $this->assertSame([
            'purchase-canceled-buyer:' . $purchase->id,
            'Your purchase has been canceled.',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
    }

    private function purchaseWithNotificationUsers(&$buyer, &$vendor)
    {
        $purchase = new Purchase();
        $purchase->id = 'canceled-purchase-id';

        $buyer = new PurchaseCanceledNotificationUserDouble();
        $buyer->username = 'buyer';
        $vendor = new PurchaseCanceledNotificationUserDouble();
        $vendor->username = 'vendor';

        $purchase->setRelation('buyer', $buyer);
        $purchase->setRelation('vendor', (object) ['user' => $vendor]);

        return $purchase;
    }
}

class PurchaseCanceledNotificationUserDouble extends User
{
    public $failAfterPersisting = false;
    public $lastNotification = [];
    public $notificationsSent = 0;
    public $persistedNotifications = [];

    public function notifyOnce(
        string $deliveryKey,
        string $content,
        ?string $routeName = null,
        ?string $routeParams = null
    ): bool
    {
        if (array_key_exists($deliveryKey, $this->persistedNotifications)) {
            return false;
        }

        $this->notificationsSent++;
        $this->lastNotification = [$deliveryKey, $content, $routeName, $routeParams];
        $this->persistedNotifications[$deliveryKey] = true;

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }

        return true;
    }
}
