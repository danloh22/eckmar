<?php

namespace App\Listeners\Purchase;

use App\Events\Purchase\ProductDisputeNewMessageSent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProductDisputeNewMessageNotification
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
     * @param  ProductDisputeNewMessageSent  $event
     * @return void
     */
    public function handle(ProductDisputeNewMessageSent $event)
    {
        $initiatedByVendor = $this->samePresentUserIdentifier($event->initiator, $event->vendor->user);
        $initiatedByBuyer = $this->samePresentUserIdentifier($event->initiator, $event->buyer);
        $initiatedByAdmin = !$initiatedByVendor && !$initiatedByBuyer && $event->initiator->isAdmin();

        if ($initiatedByVendor || $initiatedByAdmin){
            /**
             * Notify Buyer
             */
            $content = 'There is new message on your disputed purchase';
            $routeName = 'profile.purchases.single';
            $routeParams = serialize(['purchase'=>$event->purchase->id]);
            $event->buyer->notifyOnce(
                'dispute-message-buyer:' . $event->messageId,
                $content,
                $routeName,
                $routeParams
            );
        }

        if ($initiatedByBuyer || $initiatedByAdmin){
            /**
             * Notify vendor
             */
            $content = 'There is new message on your disputed sale';
            $routeName = 'profile.sales.single';
            $routeParams = serialize(['sale'=>$event->purchase->id]);
            $event->vendor->user->notifyOnce(
                'dispute-message-vendor:' . $event->messageId,
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
