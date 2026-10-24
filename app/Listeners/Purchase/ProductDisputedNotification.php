<?php

namespace App\Listeners\Purchase;

use App\Events\Purchase\ProductDisputed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProductDisputedNotification
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
     * @param  ProductDisputed  $event
     * @return void
     */
    public function handle(ProductDisputed $event)
    {
        if ($this->samePresentUserIdentifier($event->initiator, $event->vendor->user)){
            /**
             * Notify Buyer
             */
            $content = 'Purchase for a product you bought is being disputed';
            $routeName = 'profile.purchases.single';
            $routeParams = serialize(['purchase'=>$event->purchase->id]);
            $this->notifyOnce($event->buyer, $content, $routeName, $routeParams);
        }

        if ($this->samePresentUserIdentifier($event->initiator, $event->buyer)){
            /**
             * Notify vendor
             */
            $content = 'Purchase for a product you sold is being disputed';
            $routeName = 'profile.sales.single';
            $routeParams = serialize(['sale'=>$event->purchase->id]);
            $this->notifyOnce($event->vendor->user, $content, $routeName, $routeParams);
        }
    }

    /**
     * Route notifications by persisted identity, not loaded model state.
     */
    private function samePresentUserIdentifier($left, $right)
    {
        $leftId = $left->id;
        $rightId = $right->id;

        return is_scalar($leftId) && is_scalar($rightId)
            && (string) $leftId !== '' && (string) $rightId !== ''
            && (string) $leftId === (string) $rightId;
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
