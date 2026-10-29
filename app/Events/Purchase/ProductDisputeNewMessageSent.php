<?php

namespace App\Events\Purchase;

use App\Purchase;
use App\User;
use Illuminate\Foundation\Events\Dispatchable;
use Ramsey\Uuid\Uuid;

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
    public function __construct(Purchase $purchase, User $user, $messageId = null) {

        $this->buyer = $purchase->buyer;
        $this->vendor = $purchase->vendor;
        $this->product = $purchase->offer->product;
        $this->purchase = $purchase;

        $this->initiator = $user;
        // Preserve the legacy two-argument constructor for internal callers.
        // The production write path supplies the persisted message UUID;
        // legacy callers receive a per-event identity rather than collapsing
        // every later message on the purchase into one delivery.
        $this->messageId = is_scalar($messageId) && (string) $messageId !== ''
            ? (string) $messageId
            : Uuid::uuid4()->toString();
    }
}
