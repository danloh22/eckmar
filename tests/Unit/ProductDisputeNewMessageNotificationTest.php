<?php

namespace Tests\Unit;

use App\Events\Purchase\ProductDisputeNewMessageSent;
use App\Listeners\Purchase\ProductDisputeNewMessageNotification;
use App\Purchase;
use App\User;
use Tests\TestCase;

class ProductDisputeNewMessageNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendDisputeMessageNotificationTwice()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputeNewMessageSent($purchase, $vendor);
        $listener = new ProductDisputeNewMessageNotification();

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, $buyer->notificationsSent);
        $this->assertSame(0, $vendor->notificationsSent);
        $this->assertSame([
            'There is new message on your disputed purchase',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
        $this->assertSame([
            ['description', 'There is new message on your disputed purchase'],
            ['route_name', 'profile.purchases.single'],
            ['route_params', serialize(['purchase' => $purchase->id])],
        ], $buyer->lastNotificationQuery->filters);
    }

    public function testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateDisputeMessageNotification()
    {
        $purchase = $this->purchaseWithNotificationUsers($buyer, $vendor);
        $event = new ProductDisputeNewMessageSent($purchase, $buyer);
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
            'There is new message on your disputed sale',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
        $this->assertSame([
            ['description', 'There is new message on your disputed sale'],
            ['route_name', 'profile.sales.single'],
            ['route_params', serialize(['sale' => $purchase->id])],
        ], $vendor->lastNotificationQuery->filters);
    }

    private function purchaseWithNotificationUsers(&$buyer, &$vendor)
    {
        $purchase = new Purchase();
        $purchase->id = 'dispute-message-purchase-id';

        $buyer = new ProductDisputeNewMessageNotificationUserDouble();
        $buyer->username = 'buyer';
        $vendor = new ProductDisputeNewMessageNotificationUserDouble();
        $vendor->username = 'vendor';

        $purchase->setRelation('buyer', $buyer);
        $purchase->setRelation('vendor', (object) ['user' => $vendor]);
        $purchase->setRelation('offer', (object) [
            'product' => (object) ['id' => 'product-id'],
        ]);

        return $purchase;
    }
}

class ProductDisputeNewMessageNotificationUserDouble extends User
{
    public $failAfterPersisting = false;
    public $lastNotification = [];
    public $lastNotificationQuery;
    public $notificationsSent = 0;
    public $persistedNotifications = [];

    public function notifications()
    {
        $this->lastNotificationQuery = new ProductDisputeNewMessageNotificationQueryDouble($this);

        return $this->lastNotificationQuery;
    }

    public function notify($content, $routeName = null, $routeParams = null)
    {
        $this->notificationsSent++;
        $this->lastNotification = [$content, $routeName, $routeParams];
        $this->persistedNotifications[] = [
            'description' => $content,
            'route_name' => $routeName,
            'route_params' => $routeParams,
        ];

        if ($this->failAfterPersisting) {
            throw new \RuntimeException('post-persistence side effect failed');
        }
    }
}

class ProductDisputeNewMessageNotificationQueryDouble
{
    public $filters = [];
    private $user;

    public function __construct(ProductDisputeNewMessageNotificationUserDouble $user)
    {
        $this->user = $user;
    }

    public function where($column, $value)
    {
        $this->filters[] = [$column, $value];

        return $this;
    }

    public function exists()
    {
        foreach ($this->user->persistedNotifications as $notification) {
            $matches = true;

            foreach ($this->filters as $filter) {
                if (!array_key_exists($filter[0], $notification)
                    || $notification[$filter[0]] !== $filter[1]) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
