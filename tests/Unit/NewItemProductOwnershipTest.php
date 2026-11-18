<?php

namespace Tests\Unit;

use App\Exceptions\RequestException;
use App\Http\Requests\Cart\NewItemRequest;
use App\Product;
use App\User;
use Tests\TestCase;

class NewItemProductOwnershipTest extends TestCase
{
    public function testSelfPurchaseCheckUsesPersistedOwnerIdInsteadOfLoadedRelation()
    {
        $owner = new User();
        $owner->id = 'owner-id';

        $staleLoadedOwner = new User();
        $staleLoadedOwner->id = 'previous-owner-id';

        $product = new Product();
        $product->user_id = 'owner-id';
        $product->setRelation('user', $staleLoadedOwner);

        $this->actingAs($owner);

        $this->expectException(RequestException::class);
        (new NewItemRequest())->persist($product);
    }

    public function testPhysicalProductRequiresShippingBeforeCartMutation()
    {
        $buyer = new User();
        $buyer->id = 'buyer-id';

        $product = new NewItemPhysicalProductProbe();
        $product->user_id = 'vendor-id';

        $this->actingAs($buyer);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('A shipping option is required for physical products!');
        (new NewItemRequest())->persist($product);
    }
}

class NewItemPhysicalProductProbe extends Product
{
    public function isPhysical()
    {
        return true;
    }
}
