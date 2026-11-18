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
use App\Product;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait Purchasable {
    /**
     *  Runs purchased procedure
     *  It has been called in DB transaction
     */
    public function purchased()
    {
        list($offer, $product) = $this->lockActiveOfferAndProduct();
        throw_unless($product->active, new RequestException('This product is no longer available.'));
        $this->validateLockedCheckoutSelection($offer, $product);
        $shipping = $this->lockAndValidateCheckoutShipping($product);
        $offer->setRelation('product', $product);
        $this->setRelation('offer', $offer);
        if ($shipping !== null) {
            $this->setRelation('shipping', $shipping);
        }

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

    /**
     * Lock checkout inventory in the same Product -> Offer order used by
     * offer creation and removal. The second offer read closes the gap between
     * discovering the owning product and acquiring its serialization lock.
     */
    protected function lockActiveOfferAndProduct()
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException(
                'Checkout offer resolution requires an active database transaction.'
            );
        }

        $offerReference = Offer::where('id', $this->offer_id)
            ->select(['id', 'product_id'])
            ->first();
        if (!$offerReference) {
            throw new RequestException('Selected offer is no longer available. Please add the product again.');
        }

        $product = Product::where('id', $offerReference->product_id)
            ->lockForUpdate()
            ->first();
        if (!$product) {
            throw new RequestException('Selected offer is no longer available. Please add the product again.');
        }

        $offer = Offer::where('id', $this->offer_id)
            ->where('product_id', $product->id)
            ->where('deleted', '=', 0)
            ->lockForUpdate()
            ->first();
        if (!$offer) {
            throw new RequestException('Selected offer is no longer available. Please add the product again.');
        }

        return [$offer, $product];
    }

    /**
     * Revalidate session selections against the rows held by checkout locks.
     */
    protected function validateLockedCheckoutSelection(Offer $offer, Product $product)
    {
        if (!$product->supportsCoin($this->coin_name)) {
            throw new RequestException(
                'Selected coin is no longer supported by this product. Please add the product again.'
            );
        }
        if (!in_array($this->type, $product->getTypes(), true)) {
            throw new RequestException(
                'Selected purchase type is no longer supported by this product. Please add the product again.'
            );
        }
        if ((int) $offer->min_quantity > $this->quantity) {
            throw new RequestException(
                'Selected offer no longer applies to this quantity. Please add the product again.'
            );
        }
    }

    /**
     * Reload and lock the selected shipping row after the owning product lock.
     * Session relations may be stale when shipping is deleted, moved to another
     * product, or its quantity range changes before checkout.
     */
    protected function lockAndValidateCheckoutShipping(Product $product)
    {
        $shippingId = $this->getAttribute('shipping_id');
        $this->validateLockedCheckoutShippingPresence($product, $shippingId);
        if ($shippingId === null) {
            return null;
        }

        $shipping = Shipping::where('id', $shippingId)
            ->where('product_id', $product->id)
            ->where('deleted', '=', 0)
            ->lockForUpdate()
            ->first();
        if (!$shipping) {
            throw new RequestException(
                'Selected shipping is no longer available. Please add the product again.'
            );
        }

        $this->validateLockedCheckoutShipping($shipping);

        return $shipping;
    }

    protected function validateLockedCheckoutShippingPresence(Product $product, $shippingId)
    {
        if ($product->isPhysical() && $shippingId === null) {
            throw new RequestException(
                'A shipping option is required for physical products. Please add the product again.'
            );
        }
    }

    protected function validateLockedCheckoutShipping(Shipping $shipping)
    {
        if ($shipping->from_quantity > $this->quantity
            || $shipping->to_quantity < $this->quantity) {
            throw new RequestException(
                'Selected shipping no longer applies to this quantity. Please add the product again.'
            );
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
