<?php

namespace Tests\Unit;

use App\Events\Purchase\ProductDisputeResolved;
use App\Listeners\Purchase\ProductDisputeResolvedNotification;
use App\Purchase;
use App\User;
use Tests\TestCase;

class ProductDisputeResolvedNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendResolvedDisputeNotificationsTwice()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputeResolved($purchase);
        $listener = new ProductDisputeResolvedNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame(
            'dispute-resolved-buyer:' . $purchase->id,
            $buyer->lastDeliveryKey
        );
        $this->assertSame(
            'dispute-resolved-vendor:' . $purchase->id,
            $vendor->lastDeliveryKey
        );
        $this->assertSame([
            'Dispute for your purchase is now resolved',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
        $this->assertSame([
            'Dispute for your sale is now resolved',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateResolvedDisputeNotification()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputeResolved($purchase);
        $listener = new ProductDisputeResolvedNotification();
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
        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame([
            'Dispute for your purchase is now resolved',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
        $this->assertSame([
            'Dispute for your sale is now resolved',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
    }

    private function purchaseWithNotificationUsers(&$buyer, &$vendor)
    {
        $purchase = new Purchase();
        $purchase->id = 'resolved-dispute-purchase-id';

        $buyer = new ProductDisputeResolvedNotificationUserDouble();
        $buyer->username = 'buyer';
        $vendor = new ProductDisputeResolvedNotificationUserDouble();
        $vendor->username = 'vendor';

        $purchase->setRelation('buyer', $buyer);
        $purchase->setRelation('vendor', (object) ['user' => $vendor]);
        $purchase->setRelation('offer', (object) [
            'product' => (object) ['id' => 'product-id'],
        ]);

        return $purchase;
    }
}

class ProductDisputeResolvedNotificationUserDouble extends User
{
    public $failAfterPersisting = false;
    public $lastDeliveryKey;
    public $lastNotification = [];
    public $notificationsSent = 0;
    public $persistedDeliveryKeys = [];

    public function notifyOnce(
        string $deliveryKey,
        string $content,
        ?string $routeName = null,
        ?string $routeParams = null
    ): bool {
        $this->lastDeliveryKey = $deliveryKey;

        if (in_array($deliveryKey, $this->persistedDeliveryKeys, true)) {
            return false;
        }

        $this->persistedDeliveryKeys[] = $deliveryKey;
        $this->notificationsSent++;
        $this->lastNotification = [$content, $routeName, $routeParams];

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }

        return true;
    }
}
