<?php

namespace Tests\Unit;

use App\Http\Requests\Cart\NewItemRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NewItemValidationTest extends TestCase
{
    /** @dataProvider validAmounts */
    public function testCanonicalPositiveDatabaseAmountsPass($amount)
    {
        $validator = Validator::make(
            $this->inputWithAmount($amount),
            (new NewItemRequest())->rules()
        );

        $this->assertFalse($validator->errors()->has('amount'));
    }

    public function validAmounts()
    {
        return [
            'integer minimum' => [1],
            'string minimum' => ['1'],
            'integer maximum' => [2147483647],
            'string maximum' => ['2147483647'],
        ];
    }

    /** @dataProvider invalidAmounts */
    public function testNoncanonicalOrOutOfRangeAmountsFail($amount)
    {
        $validator = Validator::make(
            $this->inputWithAmount($amount),
            (new NewItemRequest())->rules()
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('amount'));
    }

    public function invalidAmounts()
    {
        return [
            'missing' => [null],
            'zero' => [0],
            'negative' => [-1],
            'fractional number' => [1.5],
            'fractional string' => ['1.5'],
            'numeric suffix' => ['1oops'],
            'immediate overflow' => [2147483648],
            'large overflow string' => ['999999999999999999999'],
            'array' => [['1']],
        ];
    }

    private function inputWithAmount($amount)
    {
        return [
            'amount' => $amount,
            'coin' => 'btc',
            'type' => 'normal',
        ];
    }
}
