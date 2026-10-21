<?php

namespace App\Http\Requests\Admin;

use App\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RemoveProductFromFeaturedReuqest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('has-access', 'products');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'product_id' => 'required|exists:products,id'
        ];
    }
    public function messages(){
        return [
            'product_id.required' => 'Product ID is required',
            'product_id.exists' => 'Product with that ID does not exist'
        ];
    }
    public function persist(){
        $product = Product::where('id',$this->product_id)->first();
        $product->featured = false;
        $product->save();
        session() -> flash('success', 'You have successfully removed product from list of featured products');

    }
}
