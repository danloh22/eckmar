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
        // Checkout recovery can replay NewPurchase concurrently. The stable
        // delivery key maps to a deterministic notification primary key, so
        // exactly one replay persists and dispatches the optional side effect.
        $event->vendor->user->notifyOnce(
            'purchase-bought:' . $event->purchase->id,
            $content,
            $routeName,
            $routeParams
        );
    }
}
