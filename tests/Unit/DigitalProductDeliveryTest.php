<?php

namespace Tests\Unit;

use App\DigitalProduct;
use Tests\TestCase;

class DigitalProductDeliveryTest extends TestCase
{
    public function testUnlimitedDigitalDeliveryCanFulfilMultipleItemsWithoutConsumingTheLink()
    {
        $product = new DigitalProduct();
        $product->content = 'https://downloads.example/item';
        $product->unlimited = true;

        $delivered = $product->getProducts(3);

        $this->assertSame([
            'https://downloads.example/item',
            'https://downloads.example/item',
            'https://downloads.example/item',
        ], $delivered);
        $this->assertSame('https://downloads.example/item', $product->content);
    }
}
