<?php

namespace App\Events\Purchase;

use App\Purchase;
use App\User;
use Illuminate\Foundation\Events\Dispatchable;

class ProductDisputeNewMessageSent
{
    use Dispatchable;


    /**
     * User who purchased the product
     *
     * @var mixed
     */
    public $buyer;

    /**
     * User who sells the product
     *
     * @var mixed
     */
    public $vendor;

    /**
     * Product
     *
     * @var
     */
    public $product;

    /**
     * Complete instance of a purchase
     *
     * @var
     */
    public $purchase;

    /**
     * User that initiated dispute
     *
     * @var User
     */
    public $initiator;

    /**
     * Stable UUID of the persisted dispute message.
     *
     * @var string
     */
    public $messageId;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Purchase $purchase, User $user, string $messageId) {

        $this->buyer = $purchase->buyer;
        $this->vendor = $purchase->vendor;
        $this->product = $purchase->offer->product;
        $this->purchase = $purchase;

        $this->initiator = $user;
        $this->messageId = $messageId;
    }
}
