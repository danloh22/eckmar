<?php

namespace App\Listeners\Purchase;

use App\Events\Purchase\ProductDelivered;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProductDeliveredNotification
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  ProductDelivered  $event
     * @return void
     */
    public function handle(ProductDelivered $event)
    {
        $content = 'Your product has been marked delivered by buyer ['.$event->buyer->username.']';
        $routeName = 'profile.sales.single';
        $routeParams = serialize(['sales'=>$event->purchase->id]);
        // Two workers may replay the same lifecycle event concurrently. The
        // deterministic key is enforced by the existing notification primary
        // key, so only the winning insert dispatches the optional side effect.
        $event->vendor->user->notifyOnce(
            'purchase-delivered:' . $event->purchase->id,
            $content,
            $routeName,
            $routeParams
        );
    }
}
