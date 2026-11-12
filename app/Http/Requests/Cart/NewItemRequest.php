<?php

namespace App\Http\Requests\Cart;

use App\Exceptions\RequestException;
use App\Marketplace\Cart;
use App\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NewItemRequest extends FormRequest
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
            'delivery' => 'nullable|exists:shippings,id',
            // Product stock, offer thresholds and shipping ranges use signed
            // MySQL INT columns. Reject fractional, non-positive or oversized
            // quantities before PHP coerces the value into Cart::addToCart().
            'amount' => 'required|integer|min:1|max:2147483647',
            'message' => 'nullable|string',
            'coin' => ['required' , Rule::in(array_keys(config('coins.coin_list')))],
            'type' => ['required', Rule::in(array_keys(\App\Purchase::$types))],
        ];
    }

    public function persist(Product $product)
    {
        $shipping = null;
        $ownerId = $product->user_id;
        $buyerId = auth()->id();
        $isOwner = is_scalar($ownerId) && is_scalar($buyerId)
            && (string) $ownerId !== '' && (string) $buyerId !== ''
            && (string) $ownerId === (string) $buyerId;

        throw_if($isOwner, new RequestException('You can\'t put your products in cart!'));
        // select shipping
        if($product -> isPhysical()) {
            if (!$this->delivery) {
                throw new RequestException('A shipping option is required for physical products!');
            }
            $shipping = $product -> specificProduct() -> shippings()
                -> where('id', $this -> delivery)
                -> where('deleted', '=', 0) // is not deleted
                -> first();
            if (!$shipping) {
                throw new RequestException('Selected shipping is not available for this product!');
            }
        }
        Cart::getCart() -> addToCart($product, $this -> amount, $this -> coin, $shipping, $this -> message, $this -> type);
    }
}
