<?php

namespace App\Listeners\Purchase;

use App\Events\Purchase\NewPurchase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProductBoughtNotification
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
     * @param  NewPurchase  $event
     * @return void
     */
    public function handle(NewPurchase $event)
    {
        $content = 'Your product has been purchased by ['.$event->buyer->username.']';
        $routeName = 'profile.sales.single';
        $routeParams = serialize(['sale'=>$event->purchase->id]);
        $notifications = $event->vendor->user->notifications();

        // Checkout recovery can replay NewPurchase after the database commit.
        // Reuse the stable sale route payload as the delivery identity so a
        // prior notification also suppresses its optional Bitmessage side
        // effect, which is dispatched by notify().
        $alreadyDelivered = $notifications
            ->where('route_name', $routeName)
            ->where('route_params', $routeParams)
            ->exists();

        if ($alreadyDelivered) {
            return;
        }

        $event->vendor->user->notify($content, $routeName, $routeParams);
    }
}
