<?php

namespace Tests\Unit;

use App\Exceptions\RequestException;
use App\Offer;
use App\Product;
use App\Shipping;
use App\Traits\Purchasable;
use Tests\TestCase;

class LockedCheckoutSelectionTest extends TestCase
{
    /**
     * @dataProvider invalidLockedSelectionValues
     */
    public function testLockedSelectionIsRejectedBeforePaymentResolution(
        $coin,
        $type,
        $quantity,
        $minimumQuantity,
        $expectedMessage
    ) {
        $product = new Product();
        $product->coins = 'btc,xmr';
        $product->types = 'normal';

        $offer = new Offer();
        $offer->min_quantity = $minimumQuantity;

        $purchase = new LockedCheckoutSelectionProbe($coin, $type, $quantity);

        try {
            $purchase->validate($offer, $product);
            $this->fail('Invalid locked checkout selection must fail closed.');
        } catch (RequestException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }

        $this->assertFalse($purchase->paymentResolved);
    }

    public function invalidLockedSelectionValues()
    {
        return [
            'unsupported coin' => [
                'ltc', 'normal', 2, 1,
                'Selected coin is no longer supported by this product. Please add the product again.',
            ],
            'unsupported purchase type' => [
                'btc', 'fe', 2, 1,
                'Selected purchase type is no longer supported by this product. Please add the product again.',
            ],
            'offer minimum exceeds quantity' => [
                'btc', 'normal', 2, 3,
                'Selected offer no longer applies to this quantity. Please add the product again.',
            ],
        ];
    }

    /**
     * @dataProvider invalidLockedShippingRanges
     */
    public function testLockedShippingRangeIsRejectedBeforePaymentResolution(
        $quantity,
        $fromQuantity,
        $toQuantity
    ) {
        $shipping = new Shipping();
        $shipping->from_quantity = $fromQuantity;
        $shipping->to_quantity = $toQuantity;

        $purchase = new LockedCheckoutSelectionProbe('btc', 'normal', $quantity);

        try {
            $purchase->validateShipping($shipping);
            $this->fail('Invalid locked shipping range must fail closed.');
        } catch (RequestException $exception) {
            $this->assertSame(
                'Selected shipping no longer applies to this quantity. Please add the product again.',
                $exception->getMessage()
            );
        }

        $this->assertFalse($purchase->paymentResolved);
    }

    public function invalidLockedShippingRanges()
    {
        return [
            'quantity below current shipping minimum' => [2, 3, 10],
            'quantity above current shipping maximum' => [11, 3, 10],
        ];
    }

    public function testPhysicalProductWithoutShippingIsRejectedBeforePaymentResolution()
    {
        $purchase = new LockedCheckoutSelectionProbe('btc', 'normal', 2);

        try {
            $purchase->validateShippingPresence(new LockedCheckoutProductProbe(true), null);
            $this->fail('Physical checkout without shipping must fail closed.');
        } catch (RequestException $exception) {
            $this->assertSame(
                'A shipping option is required for physical products. Please add the product again.',
                $exception->getMessage()
            );
        }

        $this->assertFalse($purchase->paymentResolved);
    }

    public function testDigitalProductWithoutShippingRemainsSupported()
    {
        $purchase = new LockedCheckoutSelectionProbe('btc', 'normal', 2);

        $purchase->validateShippingPresence(new LockedCheckoutProductProbe(false), null);

        $this->assertTrue($purchase->paymentResolved);
    }
}

class LockedCheckoutSelectionProbe
{
    use Purchasable;

    public $coin_name;
    public $type;
    public $quantity;
    public $paymentResolved = false;

    public function __construct($coin, $type, $quantity)
    {
        $this->coin_name = $coin;
        $this->type = $type;
        $this->quantity = $quantity;
    }

    public function validate(Offer $offer, Product $product)
    {
        $this->validateLockedCheckoutSelection($offer, $product);
        $this->paymentResolved = true;
    }

    public function validateShipping(Shipping $shipping)
    {
        $this->validateLockedCheckoutShipping($shipping);
        $this->paymentResolved = true;
    }

    public function validateShippingPresence(Product $product, $shippingId)
    {
        $this->validateLockedCheckoutShippingPresence($product, $shippingId);
        $this->paymentResolved = true;
    }
}

class LockedCheckoutProductProbe extends Product
{
    private $physical;

    public function __construct($physical = false)
    {
        parent::__construct();
        $this->physical = $physical;
    }

    public function isPhysical()
    {
        return $this->physical;
    }
}
