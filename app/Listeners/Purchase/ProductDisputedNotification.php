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
            $event->buyer->notifyOnce(
                'purchase-disputed-buyer:' . $event->purchase->id,
                $content,
                $routeName,
                $routeParams
            );
        }

        if ($this->samePresentUserIdentifier($event->initiator, $event->buyer)){
            /**
             * Notify vendor
             */
            $content = 'Purchase for a product you sold is being disputed';
            $routeName = 'profile.sales.single';
            $routeParams = serialize(['sale'=>$event->purchase->id]);
            $event->vendor->user->notifyOnce(
                'purchase-disputed-vendor:' . $event->purchase->id,
                $content,
                $routeName,
                $routeParams
            );
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
}
