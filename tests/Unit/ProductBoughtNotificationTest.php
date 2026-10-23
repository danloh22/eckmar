<?php

namespace Tests\Unit;

use App\Events\Purchase\NewPurchase;
use App\Listeners\Purchase\ProductBoughtNotification;
use App\Purchase;
use Mockery;
use Tests\TestCase;

class ProductBoughtNotificationTest extends TestCase
{
    public function testRecoveryReplayDoesNotSendPurchaseNotificationTwice()
    {
        $purchase = new Purchase();
        $purchase->id = 'replayed-purchase-id';
        $purchase->setRelation('buyer', (object) ['username' => 'buyer']);
        $purchase->setRelation('offer', (object) ['product' => (object) ['id' => 'product-id']]);

        $notificationQuery = Mockery::mock();
        $notificationQuery->shouldReceive('where')
            ->twice()
            ->with('route_name', 'profile.sales.single')
            ->andReturnSelf();
        $notificationQuery->shouldReceive('where')
            ->twice()
            ->with('route_params', serialize(['sale' => $purchase->id]))
            ->andReturnSelf();
        $notificationQuery->shouldReceive('exists')
            ->twice()
            ->andReturn(false, true);

        $user = Mockery::mock();
        $user->shouldReceive('notifications')
            ->twice()
            ->andReturn($notificationQuery);
        $user->shouldReceive('notify')
            ->once()
            ->with(
                'Your product has been purchased by [buyer]',
                'profile.sales.single',
                serialize(['sale' => $purchase->id])
            );

        $purchase->setRelation('vendor', (object) ['user' => $user]);
        $event = new NewPurchase($purchase);
        $listener = new ProductBoughtNotification();

        $listener->handle($event);
        $listener->handle($event);
    }
}
