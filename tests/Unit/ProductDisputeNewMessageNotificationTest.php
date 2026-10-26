<?php

namespace Tests\Unit;

use App\Events\Purchase\ProductDisputeNewMessageSent;
use App\Listeners\Purchase\ProductDisputeNewMessageNotification;
use App\Purchase;
use App\User;
use Tests\TestCase;

class ProductDisputeNewMessageNotificationTest extends TestCase
{
    public function testLegacyEventConstructorAssignsPerEventDeliveryIdentity()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);

        $first = new ProductDisputeNewMessageSent($purchase, $vendor);
        $second = new ProductDisputeNewMessageSent($purchase, $vendor);

        $this->assertNotSame('', $first->messageId);
        $this->assertNotSame($first->messageId, $second->messageId);
    }

    public function testAdministratorDisputeMessageNotifiesBothParticipants()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $administrator = new ProductDisputeNewMessageNotificationAdminDouble();
        $administrator->id = 505;

        (new ProductDisputeNewMessageNotification())->handle(
            new ProductDisputeNewMessageSent($purchase, $administrator, 'admin-message-id')
        );

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame(1, $vendor->notificationsSent);
    }

    public function testStaleVendorModelStillRoutesDisputeMessageByIdentifier()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $staleVendor = new User();
        $staleVendor->id = (string) $vendor->id;
        $staleVendor->username = 'stale-vendor-state';

        (new ProductDisputeNewMessageNotification())->handle(
            new ProductDisputeNewMessageSent($purchase, $staleVendor, 'stale-vendor-message-id')
        );

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame(0, $vendor->notificationsSent);
    }

    public function testRecoveryReplayDoesNotSendDisputeMessageNotificationTwice()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputeNewMessageSent($purchase, $vendor, 'replayed-message-id');
        $listener = new ProductDisputeNewMessageNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame(0, $vendor->notificationsSent);
        $this->assertSame([
            'dispute-message-buyer:replayed-message-id',
            'There is new message on your disputed purchase',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateDisputeMessageNotification()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputeNewMessageSent($purchase, $buyer, 'persisted-message-id');
        $listener = new ProductDisputeNewMessageNotification();
        $vendor->failAfterPersisting = true;

        try {
            $listener->handle($event);
            $this->fail('Expected the post-persistence side effect to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-persistence side effect failed', $exception->getMessage());
        }

        $vendor->failAfterPersisting = false;
        $listener->handle($event);

        $this->assertSame(0, $buyer->notificationsSent);
        $this->assertSame(1, $vendor->notificationsSent);
        $this->assertSame([
            'dispute-message-vendor:persisted-message-id',
            'There is new message on your disputed sale',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
    }

    public function testDifferentMessagesOnSameDisputeEachNotifyOnce()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $listener = new ProductDisputeNewMessageNotification();

        $listener->handle(new ProductDisputeNewMessageSent(
            $purchase,
            $vendor,
            'first-message-id'
        ));
        $listener->handle(new ProductDisputeNewMessageSent(
            $purchase,
            $vendor,
            'second-message-id'
        ));

        $this->assertSame(2, $buyer->notificationsSent);
        $this->assertSame(0, $vendor->notificationsSent);
        $this->assertSame([
            'dispute-message-buyer:first-message-id',
            'dispute-message-buyer:second-message-id',
        ], array_keys($buyer->persistedNotifications));
    }

    private function purchaseWithNotificationUsers(&$buyer, &$vendor)
    {
        $purchase = new Purchase();
        $purchase->id = 'dispute-message-purchase-id';

        $buyer = new ProductDisputeNewMessageNotificationUserDouble();
        $buyer->id = 303;
        $buyer->username = 'buyer';
        $vendor = new ProductDisputeNewMessageNotificationUserDouble();
        $vendor->id = 404;
        $vendor->username = 'vendor';

        $purchase->setRelation('buyer', $buyer);
        $purchase->setRelation('vendor', (object) ['user' => $vendor]);
        $purchase->setRelation('offer', (object) [
            'product' => (object) ['id' => 'product-id'],
        ]);

        return $purchase;
    }
}

class ProductDisputeNewMessageNotificationAdminDouble extends User
{
    public function isAdmin()
    {
        return true;
    }
}

class ProductDisputeNewMessageNotificationUserDouble extends User
{
    public $failAfterPersisting = false;
    public $lastNotification = [];
    public $notificationsSent = 0;
    public $persistedNotifications = [];

    public function notifyOnce($deliveryKey, $content, $routeName = null, $routeParams = null)
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
