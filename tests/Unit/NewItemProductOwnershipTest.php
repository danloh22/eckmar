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
}
