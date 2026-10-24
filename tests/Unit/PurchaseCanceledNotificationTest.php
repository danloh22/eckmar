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
            'Your sale has been canceled.',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
        $this->assertSame([
            'Your purchase has been canceled.',
            'profile.purchases.single',
            serialize(['purchase' => $purchase->id]),
        ], $buyer->lastNotification);
        $this->assertSame([
            ['description', 'Your sale has been canceled.'],
            ['route_name', 'profile.sales.single'],
            ['route_params', serialize(['sale' => $purchase->id])],
        ], $vendor->lastNotificationQuery->filters);
        $this->assertSame([
            ['description', 'Your purchase has been canceled.'],
            ['route_name', 'profile.purchases.single'],
            ['route_params', serialize(['purchase' => $purchase->id])],
        ], $buyer->lastNotificationQuery->filters);
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
            'Your sale has been canceled.',
            'profile.sales.single',
            serialize(['sale' => $purchase->id]),
        ], $vendor->lastNotification);
        $this->assertSame([
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
    public $lastNotificationQuery;
    public $notificationsSent = 0;
    public $persistedNotifications = [];

    public function notifications()
    {
        $this->lastNotificationQuery = new PurchaseCanceledNotificationQueryDouble($this);

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

class PurchaseCanceledNotificationQueryDouble
{
    public $filters = [];
    private $user;

    public function __construct(PurchaseCanceledNotificationUserDouble $user)
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
