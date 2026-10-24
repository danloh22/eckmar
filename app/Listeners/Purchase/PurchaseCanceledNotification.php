<?php

namespace App\Listeners\Purchase;

use App\Events\Purchase\CanceledPurchase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class PurchaseCanceledNotification
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
     * @param  object  $event
     * @return void
     */
    public function handle(CanceledPurchase $event)
    {
        // Notify vendor
        $content = "Your sale has been canceled.";
        $routeName = 'profile.sales.single';
        $routeParams = serialize(['sale'=>$event->purchase->id]);
        $this->notifyOnce($event->purchase->vendor->user, $content, $routeName, $routeParams);

        // Notifiy buyer
        $content = "Your purchase has been canceled.";
        $routeName = 'profile.purchases.single';
        $routeParams = serialize(['purchase'=>$event->purchase->id]);
        $this->notifyOnce($event->purchase->buyer, $content, $routeName, $routeParams);
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
