<?php

namespace App\Http\Requests\Product;

use App\Exceptions\RequestException;
use App\Marketplace\Utility\CurrencyConverter;
use App\Offer;
use App\Product;
use Illuminate\Foundation\Http\FormRequest;

class NewOfferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            // Match the signed INT column in the offers migration.
            'min_quantity' => 'required|integer|min:1|max:2147483647',
            'price' => 'required|numeric|min:0.01'
        ];
    }

    /**
     * Validate the value that will actually be stored after currency rounding.
     */
    protected function validatedUsdPrice()
    {
        $price = CurrencyConverter::convertToUsd($this->price);

        if (!is_numeric($price) || !is_finite((float) $price) || $price <= 0 || $price >= 100000000000000) {
            throw new RequestException(
                'The converted offer price must be a finite amount greater than zero and below 100000000000000 USD.'
            );
        }

        return $price;
    }

    public function persist(Product $editingProduct = null)
    {
        // offers for editing product
        if ($editingProduct && $editingProduct->exists) {
            $minQuantity = $this->min_quantity;
            if ($editingProduct->offers()->where('min_quantity', $minQuantity)->exists()) {
                throw new RequestException('There is already offer like that! Please remove old offer to add new one.');
            }

            $newOffer = new Offer();
            $newOffer -> min_quantity = $minQuantity;
            $newOffer -> price = $this->validatedUsdPrice();
            $newOffer -> product_id = $editingProduct -> id;
            $newOffer -> save();

        }
        // offer for new product
        else{

            $offers = session('product_offers') ?? collect(); // return collection of offers or empty collection

            // check if there is same offer
            $minQuantity = $this -> min_quantity;
            if($offers -> search(function($offer, $key) use($minQuantity){ return $offer -> min_quantity == $minQuantity; }) !== false)
                throw new RequestException('There is already offer like that! Please remove old offer to add new one.');

            $newOffer = new Offer();
            $newOffer -> id = \Uuid::generate() -> string;
            $newOffer -> min_quantity = $this -> min_quantity;
            $newOffer -> price = $this->validatedUsdPrice();

            $offers -> push($newOffer); // put new offer
            $offers = $offers -> sortBy(function($offer){ return $offer -> min_quantity; });
            session(['product_offers' => $offers]); // save to session

        }
    }
}

