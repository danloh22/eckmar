<?php

namespace Tests\Unit;

use App\Events\Purchase\ProductDisputed;
use App\Listeners\Purchase\ProductDisputedNotification;
use App\Purchase;
use App\User;
use Tests\TestCase;

class ProductDisputedNotificationTest extends TestCase
{
    public function testStaleBuyerModelStillRoutesDisputeNotificationByIdentifier()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $staleBuyer = new User();
        $staleBuyer->id = (string) $buyer->id;
        $staleBuyer->username = 'stale-buyer-state';

        (new ProductDisputedNotification())->handle(
            new ProductDisputed($purchase, $staleBuyer)
        );

        $this->assertSame(0, $buyer->notificationsSent);
        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame(
            'purchase-disputed-vendor:' . $purchase->id,
            $vendor->lastDeliveryKey
        );
    }

    public function testRecoveryReplayDoesNotSendProductDisputedNotificationTwice()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputed($purchase, $buyer);
        $listener = new ProductDisputedNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(0, $buyer->notificationsSent);
        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame(
            'purchase-disputed-vendor:' . $purchase->id,
            $vendor->lastDeliveryKey
        );
        $this->assertSame([
            'Purchase for a product you sold is being disputed',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
    }

    public function testBuyerAndVendorUseSeparateDeliveryIdentities()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $listener = new ProductDisputedNotification();

        $listener->handle(new ProductDisputed($purchase, $vendor));
        $listener->handle(new ProductDisputed($purchase, $buyer));

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame(
            'purchase-disputed-buyer:' . $purchase->id,
            $buyer->lastDeliveryKey
        );
        $this->assertSame(
            'purchase-disputed-vendor:' . $purchase->id,
            $vendor->lastDeliveryKey
        );
        $this->assertNotSame($buyer->lastDeliveryKey, $vendor->lastDeliveryKey);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateProductDisputedNotification()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputed($purchase, $vendor);
        $listener = new ProductDisputedNotification();
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
        $this->assertSame(0, $vendor->notificationsSent);
        $this->assertSame(
            'purchase-disputed-buyer:' . $purchase->id,
            $buyer->lastDeliveryKey
        );
        $this->assertSame([
            'Purchase for a product you bought is being disputed',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
    }

    private function purchaseWithNotificationUsers(&$buyer, &$vendor)
    {
        $purchase = new Purchase();
        $purchase->id = 'disputed-purchase-id';

        $buyer = new ProductDisputedNotificationUserDouble();
        $buyer->id = 101;
        $buyer->username = 'buyer';
        $vendor = new ProductDisputedNotificationUserDouble();
        $vendor->id = 202;
        $vendor->username = 'vendor';

        $purchase->setRelation('buyer', $buyer);
        $purchase->setRelation('vendor', (object) ['user' => $vendor]);
        $purchase->setRelation('offer', (object) [
            'product' => (object) ['id' => 'product-id'],
        ]);

        return $purchase;
    }
}

class ProductDisputedNotificationUserDouble extends User
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
        if (array_key_exists($deliveryKey, $this->persistedDeliveryKeys)) {
            return false;
        }

        $this->persistedDeliveryKeys[$deliveryKey] = [
            'description' => $content,
            'route_name' => $routeName,
            'route_params' => $routeParams,
        ];
        $this->notificationsSent++;
        $this->lastDeliveryKey = $deliveryKey;
        $this->lastNotification = [$content, $routeName, $routeParams];

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }

        return true;
    }
}
