<?php

namespace App\Traits;


use App\Dispute;
use App\DisputeMessage;
use App\Events\Purchase\CanceledPurchase;
use App\Events\Purchase\ProductDelivered;
use App\Events\Purchase\ProductDisputed;
use App\Events\Purchase\ProductDisputeResolved;
use App\Events\Purchase\ProductSent;
use App\Exceptions\RequestException;

use App\Marketplace\Cart;
use App\Shipping;
use App\User;
use App\Offer;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait Purchasable {
    /**
     *  Runs purchased procedure
     *  It has been called in DB transaction
     */
    public function purchased()
    {
        // check if shipping is not deleted in the mean time
        // shipping is deleted between adding to cart and checkout
        if($this->shipping && Shipping::where('id',$this->shipping->id)->where('deleted', '=', 1)->exists()){
            Cart::getCart()->clearCart(); // clear cart
            throw new RequestException('Selected shipping is deleted, please add the product again.');
        }

        $offer = Offer::where('id', $this->offer_id)->lockForUpdate()->firstOrFail();
        $product = $offer->product()->lockForUpdate()->firstOrFail();
        throw_unless($product->active, new RequestException('This product is no longer available.'));
        $offer->setRelation('product', $product);
        $this->setRelation('offer', $offer);

        // Generate Payment Service from Service Container
        $this -> payment = app() -> makeWith(\App\Marketplace\Payment\Payment::class, ['purchase' => $this]);

        // Runs purchased procedure of the payment
        $this -> getPayment() -> purchased();
        // Prepare purchase
        $this -> encryptMessage();
        // calculate bitcoin to pay in this moment
        $this -> to_pay = $this -> getPayment() -> usdToCoin($this -> getSumDollars());
        // Substract the quantity from the product
        $this -> offer -> product -> substractQuantity($this -> quantity);
        $this -> offer -> product -> save();

        // if it is autodelivery mark as sent and run sent procedure
        if($this -> offer -> product -> isAutodelivery()){
            // Mark as sent and run sent procedure
            $this -> getPayment() -> sent();
            $this -> state = 'sent';
            $this -> save();

            // pull products to the delivered product section
            $productsToDelivery = $this -> offer -> product -> digital -> getProducts($this -> quantity);

            $this -> delivered_product = implode("\n", $productsToDelivery);
            $this -> save();
        }
    }

    private function markingAsSent(){
        try {
            $sent = DB::transaction(function () {
                $purchase = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
                throw_unless($purchase->state === 'purchased', new RequestException('Purchase must be in purchased state.'));
                throw_unless($purchase->enoughBalance(), new RequestException('Order must be paid by buyer.'));

                $purchase->getPayment()->sent();
                $purchase->state = 'sent';
                $purchase->save();

                return $purchase;
            });

            $this->refresh();
            event(new ProductSent($sent));
        } catch (\Exception $exception) {
            Log::error("Purchase $this->id shipment update failed: " . $exception->getMessage());
            if ($exception instanceof RequestException) {
                throw $exception;
            }
            throw new RequestException('The purchase could not be marked as sent. Please try again later.');
        }
    }

    /**
     * Runs procedure when the product is sent
     * Atomic in transaction
     */
    public function sent()
    {
        // checking for vendor
        if(!$this -> isVendor())
            throw new RequestException('You must be vendor of this product to mark this sale as sent!');

        // checking for purchased
        throw_unless($this->state=='purchased', new RequestException(
            'Purchase must be in purchased state!'
        ));

        // checking for normal type
        throw_if($this->type!='normal',new RequestException('This purchase is not Escrow type!'));

        // Calling procedure for marking as sent
        $this->markingAsSent();
    }

    /**
     * Function procedure same as delivered but without buyer check
     * Releasing sent purchases and making the purchase delivered
     */
    public function release(){
        $this->settleDeliveredPurchase();
    }

    /**
     * Adapted for Finalize Early purchases
     *
     * Function that does marks the purchase as delivered but in case of error restores to purchased state
     * Adapted for completing purchases
     *
     * @throws RequestException
     * @throws \Throwable
     */
    private function markingAsDelivered(){
        $this->settleDeliveredPurchase();
    }


    /**
     * Runs procedure when the product is delivered
     * Atomic in transactions
     */
    public function delivered()
    {
        if(!$this -> isBuyer())
            throw new RequestException('You must be buyer to mark this purchase as delivered!');

        throw_if($this->type!='normal', new RequestException('This purchase must be Escrow type!'));

        $this->settleDeliveredPurchase();

    }

    /**
     * Atomically settle a sent purchase and its wallet escrow hold.
     * The row lock prevents concurrent buyer and scheduler releases from
     * overwriting a completed purchase back to the sent state.
     */
    private function settleDeliveredPurchase()
    {
        try {
            $settled = DB::transaction(function () {
                $purchase = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
                throw_unless($purchase->state === 'sent', new RequestException('This purchase is not awaiting delivery.'));

                $purchase->state = 'delivered';
                $purchase->save();
                $purchase->getPayment()->delivered();

                return $purchase;
            });

            $this->refresh();
            event(new ProductDelivered($settled));
        } catch (\Exception $exception) {
            Log::error("Purchase $this->id settlement failed: " . $exception->getMessage());
            if ($exception instanceof RequestException) {
                throw $exception;
            }
            throw new RequestException('The purchase could not be settled. Please try again later.');
        }
    }

    /**
     * Returns if the purchase is completable by the Complete Purchase Command
     *
     * @return bool
     */
    private function isCompletable(): bool
    {
        // Purchase type must be Finalize Early
        if($this->type != 'fe')
            return false;
        return in_array($this->state, ['purchased', 'sent'], true);
    }

    /**
     * Called by command, for Finalize Early purchases if there is enough Balance on the address
     * Transform Purchase from 'purchased' state to 'delivered' state
     *
     * @throws RequestException
     * @throws \Throwable
     */
    public function complete()
    {
        // checking if the purchase is FE
        throw_if($this->type!='fe', new RequestException('The purchase you selected is not Finalize Early type!'));
        // checking if it is not in purchased state
        throw_if(!$this->isCompletable(), new RequestException('The purchase you selected is not in purchased state or not in sent state and product is not autodelivery!'));

        // Mark as sent once. A prior failed settlement safely remains sent and can be retried.
        if ($this->state === 'purchased') {
            $this->markingAsSent();
        }

        // Releasing funds to vendor
        $this->markingAsDelivered();

    }

    /**
     * Make dispute and dispute message
     *
     * @throws RequestException
     */
    public function makeDispute($message)
    {
        $author = auth()->user();
        if (!$author) {
            throw new RequestException('You must be logged in to open a dispute.');
        }

        try {
            $disputed = DB::transaction(function () use ($message, $author) {
                $purchase = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
                throw_unless($purchase->canMakeDispute(), new RequestException('This purchase cannot be disputed.'));

                $newDispute = new Dispute();
                $newDispute->save();

                $newDisputeMessage = new DisputeMessage();
                $newDisputeMessage->setDispute($newDispute);
                $newDisputeMessage->message = $message;
                $newDisputeMessage->setAuthor($author);
                $newDisputeMessage->save();

                $purchase->state = 'disputed';
                $purchase->setDispute($newDispute);
                $purchase->save();

                return $purchase;
            });

            $this->refresh();
            event(new ProductDisputed($disputed, $author));
        } catch (\Exception $exception) {
            Log::error("Purchase $this->id dispute creation failed: " . $exception->getMessage());
            if ($exception instanceof RequestException) {
                throw $exception;
            }
            throw new RequestException('The dispute could not be opened. Please try again later.');
        }
    }

    /**
     * Resolving disputes
     *
     * @param string $winnerId
     * @throws RequestException
     */
    public function resolveDispute(string $winnerId)
    {
        $winner = User::find($winnerId);
        if(is_null($winner)) throw new RequestException('This user can not be winner!');

        try {
            $resolved = DB::transaction(function () use ($winner) {
                $purchase = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
                throw_unless($purchase->state === 'disputed' && $purchase->dispute_id, new RequestException('This purchase has no open dispute.'));
                throw_unless($purchase->isBuyer($winner) || $purchase->isVendor($winner), new RequestException('User must be the vendor or buyer.'));

                $dispute = Dispute::where('id', $purchase->dispute_id)->lockForUpdate()->firstOrFail();
                throw_if($dispute->isResolved(), new RequestException('The dispute is already resolved.'));

                $purchase->getPayment()->resolved(['winner_id' => $winner->id]);
                $dispute->winner_id = $winner->id;
                $dispute->save();
                $purchase->state = 'delivered';
                $purchase->save();

                return $purchase;
            });

            $this->refresh();
            event(new ProductDisputeResolved($resolved));
        } catch (\Exception $exception) {
            Log::error("Purchase $this->id dispute resolution failed: " . $exception->getMessage());
            if ($exception instanceof RequestException) {
                throw $exception;
            }
            throw new RequestException('The dispute could not be resolved. Please try again later.');
        }
    }

    /**
     * Cancel the purchase
     */
    public function cancel()
    {
        try {
            $canceled = DB::transaction(function () {
                $purchase = self::where('id', $this->id)->lockForUpdate()->firstOrFail();
                throw_unless(in_array($purchase->state, ['purchased', 'sent'], true), new RequestException('Only purchased or sent orders can be canceled.'));

                $product = $purchase->offer->product()->lockForUpdate()->firstOrFail();
                if (!$product->isUnlimited()) {
                    $product->quantity += $purchase->quantity;
                    $product->save();
                }

                $purchase->state = 'canceled';
                $purchase->save();
                $purchase->getPayment()->canceled();

                return $purchase;
            });

            $this->refresh();
            event(new CanceledPurchase($canceled));
        } catch (\Exception $exception) {
            Log::error("Purchase $this->id cancellation failed: " . $exception->getMessage());
            if ($exception instanceof RequestException) {
                throw $exception;
            }
            throw new RequestException('The purchase could not be canceled. Please try again later.');
        }
    }


}
