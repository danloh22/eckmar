<?php

namespace Tests\Unit;

use App\Exceptions\RequestException;
use App\Http\Controllers\VendorController;
use App\Http\Requests\Product\NewOfferRequest;
use App\Offer;
use App\Product;
use Mockery;
use Tests\TestCase;

class DraftOfferSessionTest extends TestCase
{
    public function testRemoveOfferDeletesOnlyTheRequestedMinimumQuantity()
    {
        $one = $this->offer(1);
        $five = $this->offer(5);
        $ten = $this->offer(10);
        session()->put('product_offers', collect([0 => $one, 3 => $five, 8 => $ten]));
        $controller = $this->controllerWithCreatePermission();

        // Route parameters are strings while stored model attributes may be numeric.
        $controller->removeOffer('5');

        $remaining = session('product_offers');
        $this->assertSame([0, 8], $remaining->keys()->all());
        $this->assertSame($one, $remaining->get(0));
        $this->assertSame($ten, $remaining->get(8));
    }

    public function testRemovingAnUnknownQuantityKeepsAllDraftOffers()
    {
        $one = $this->offer(1);
        $five = $this->offer(5);
        session()->put('product_offers', collect([$one, $five]));

        $this->controllerWithCreatePermission()->removeOffer('99');

        $this->assertSame([$one, $five], session('product_offers')->all());
    }

    /** @dataProvider duplicateKeys */
    public function testPersistRejectsDuplicateQuantitiesAtZeroAndSparseCollectionKeys($key)
    {
        $existing = $this->offer(5);
        $offers = collect([$key => $existing]);
        session()->put('product_offers', $offers);
        $request = new NewOfferRequest();
        $request->replace(['min_quantity' => '5', 'price' => '2.00']);
        $exception = null;

        try {
            $request->persist();
        } catch (RequestException $error) {
            $exception = $error;
        }

        $this->assertInstanceOf(RequestException::class, $exception);
        $this->assertSame($offers, session('product_offers'));
        $this->assertSame([$key => $existing], session('product_offers')->all());
    }

    public function duplicateKeys()
    {
        return [[0], [7]];
    }

    private function offer($quantity)
    {
        $offer = new Offer();
        $offer->min_quantity = $quantity;
        $offer->price = 2;
        return $offer;
    }

    private function controllerWithCreatePermission()
    {
        $controller = Mockery::mock(VendorController::class)->makePartial();
        $controller->shouldReceive('authorize')->once()->with('create', Product::class);
        return $controller;
    }
}
