<?php

namespace App\Listeners\Purchase;

use App\Events\Purchase\ProductDisputeResolved;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProductDisputeResolvedNotification
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
     * @param  ProductDisputeResolved  $event
     * @return void
     */
    public function handle(ProductDisputeResolved $event)
    {
        /**
         * Notify Buyer
         */
        $content = 'Dispute for your purchase is now resolved';
        $routeName = 'profile.purchases.single';
        $routeParams = serialize(['purchase'=>$event->purchase->id]);
        $this->notifyOnce($event->buyer, $content, $routeName, $routeParams);

        /**
         * Notify vendor
         */
        $content = 'Dispute for your sale is now resolved';
        $routeName = 'profile.sales.single';
        $routeParams = serialize(['sale'=>$event->purchase->id]);
        $this->notifyOnce($event->vendor->user, $content, $routeName, $routeParams);
    }

    /**
     * Suppress a sequential replay after the notification was persisted.
     *
     * The description is part of the identity because several purchase
     * lifecycle notifications intentionally share the same destination.
     */
    private function notifyOnce($user, $content, $routeName, $routeParams)
    {
        $alreadyDelivered = $user->notifications()
            ->where('description', $content)
            ->where('route_name', $routeName)
            ->where('route_params', $routeParams)
            ->exists();

        if ($alreadyDelivered) {
            return;
        }

        $user->notify($content, $routeName, $routeParams);
    }
}
