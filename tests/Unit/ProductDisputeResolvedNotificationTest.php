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
        $this->assertSame([
            ['description', 'Dispute for your purchase is now resolved'],
            ['route_name', 'profile.purchases.single'],
            ['route_params', serialize(['purchase' => $purchase->id])],
        ], $buyer->lastNotificationQuery->filters);
        $this->assertSame([
            ['description', 'Dispute for your sale is now resolved'],
            ['route_name', 'profile.sales.single'],
            ['route_params', serialize(['sale' => $purchase->id])],
        ], $vendor->lastNotificationQuery->filters);
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
    public $lastNotification = [];
    public $lastNotificationQuery;
    public $notificationsSent = 0;
    public $persistedNotifications = [];

    public function notifications()
    {
        $this->lastNotificationQuery = new ProductDisputeResolvedNotificationQueryDouble($this);

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

class ProductDisputeResolvedNotificationQueryDouble
{
    public $filters = [];
    private $user;

    public function __construct(ProductDisputeResolvedNotificationUserDouble $user)
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
