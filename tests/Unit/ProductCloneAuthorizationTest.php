<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductController;
use App\Product;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProductCloneAuthorizationTest extends TestCase
{
    private function regularUser($id)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = $id;
        $user->shouldReceive('isAdmin')->andReturn(false);
        $user->shouldReceive('hasPermission')->with('products')->andReturn(false);

        return $user;
    }

    public function cloneActions()
    {
        return [['cloneProductShow'], ['cloneProductPost']];
    }

    /** @dataProvider cloneActions */
    public function testAnotherUserCannotViewOrCloneTheProduct($action)
    {
        $owner = $this->regularUser('owner');
        $this->actingAs($this->regularUser('other-user'));
        $product = new Product();
        $product->active = true;
        $product->user_id = $owner->getKey();
        $product->setRelation('user', $owner);

        // Authorization must reject the request before any cloning transaction.
        DB::shouldReceive('beginTransaction')->never();
        $this->expectException(AuthorizationException::class);

        (new ProductController())->{$action}($product);
    }

    /** @dataProvider cloneActions */
    public function testInactiveProductCannotBeClonedEvenByItsOwner($action)
    {
        $owner = $this->regularUser('owner');
        $this->actingAs($owner);
        $product = new Product();
        $product->active = false;
        $product->user_id = $owner->getKey();
        $product->setRelation('user', $owner);

        DB::shouldReceive('beginTransaction')->never();
        $this->expectException(AuthorizationException::class);

        (new ProductController())->{$action}($product);
    }

    public function testOwnerCanOpenTheCloneConfirmationForAnActiveProduct()
    {
        $owner = $this->regularUser('owner');
        $this->actingAs($owner);
        $product = new Product();
        $product->active = true;
        $product->user_id = $owner->getKey();
        $product->setRelation('user', $owner);

        $view = (new ProductController())->cloneProductShow($product);

        $this->assertSame('profile.product.confirmclone', $view->name());
        $this->assertSame($product, $view->getData()['product']);
    }
}
