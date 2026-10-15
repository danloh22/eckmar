<?php

namespace Tests\Unit;

use App\Http\Requests\Product\NewOfferRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NewOfferValidationTest extends TestCase
{
    /** @dataProvider validOffers */
    public function testValidOffersPassTheRequestRules($quantity, $price)
    {
        $validator = Validator::make(
            ['min_quantity' => $quantity, 'price' => $price],
            (new NewOfferRequest())->rules()
        );

        $this->assertTrue($validator->passes());
    }

    public function validOffers()
    {
        return [[1, 0.01], ['1', '0.01'], ['5', '12.50'], [100, '1000.00'], [2147483647, '1.00'], ['2147483647', '1.00']];
    }

    /** @dataProvider invalidOffers */
    public function testInvalidOffersFailOnTheExpectedField($field, $value)
    {
        $input = ['min_quantity' => '1', 'price' => '2.00'];
        $input[$field] = $value;
        $validator = Validator::make($input, (new NewOfferRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($field));
    }

    public function invalidOffers()
    {
        return [
            ['min_quantity', '1.5'],
            ['min_quantity', '2147483648'],
            ['min_quantity', '999999999999999999999'],
            ['min_quantity', '0'],
            ['min_quantity', '-1'],
            ['min_quantity', 'abc'],
            ['min_quantity', null],
            ['min_quantity', ['1']],
            ['price', '0'],
            ['price', '-0.01'],
            ['price', '0.009'],
            ['price', 'abc'],
            ['price', null],
            ['price', ['2.00']],
        ];
    }
}
