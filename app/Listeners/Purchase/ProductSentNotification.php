<?php

namespace App\Listeners\Purchase;

use App\Events\Purchase\ProductSent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProductSentNotification
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
     * @param  ProductSent  $event
     * @return void
     */
    public function handle(ProductSent $event)
    {

        $content = 'Your product has been sent by vendor ['.$event->vendor->user->username.']';
        $routeName = 'profile.purchases.single';
        $routeParams = serialize(['purchase'=>$event->purchase->id]);
        $notifications = $event->buyer->notifications();

        // Lifecycle event delivery may be replayed after a later side effect
        // fails. The stable purchase route payload identifies an already
        // persisted notification and suppresses notify() on that replay.
        $alreadyDelivered = $notifications
            ->where('route_name', $routeName)
            ->where('route_params', $routeParams)
            ->exists();

        if ($alreadyDelivered) {
            return;
        }

        $event->buyer->notify($content,$routeName,$routeParams);
    }
}
