<?php

namespace Tests\Unit;

use App\Policies\ProductPolicy;
use App\Product;
use App\User;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProductPolicyTest extends TestCase
{
    protected function tearDown()
    {
        Mockery::close();
        parent::tearDown();
    }

    private function user($id, $admin = false, $moderator = false)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getKey')->andReturn($id);
        $user->shouldReceive('isAdmin')->andReturn($admin);
        $user->shouldReceive('hasPermission')->with('products')->andReturn($moderator);
        return $user;
    }

    private function product($ownerId, $active = true)
    {
        $product = Mockery::mock(Product::class)->makePartial();
        $product->user_id = $ownerId;
        $product->active = $active;
        // Checking ownership must never lazy-load the owner from the database.
        $product->shouldReceive('user')->never();
        return $product;
    }

    public function testOwnerIdentityDoesNotDependOnLoadedModelAttributes()
    {
        $actor = $this->user('owner-id');
        $actor->name = 'Current profile';
        $loadedOwner = new User();
        $loadedOwner->id = 'owner-id';
        $loadedOwner->name = 'Older profile';
        $product = $this->product('owner-id');
        $product->setRelation('user', $loadedOwner);

        $this->assertTrue((new ProductPolicy())->update($actor, $product));
    }

    public function identityCases()
    {
        return [
            ['owner-id', 'owner-id', true],
            [123, '123', true],
            ['owner-id', 'other-id', false],
            [null, null, false],
            [null, 'owner-id', false],
            ['owner-id', null, false],
            ['', '', false],
            [[], [], false],
        ];
    }

    /** @dataProvider identityCases */
    public function testOwnershipUsesPresentScalarIdentifiersWithoutLoadingRelations($ownerId, $actorId, $allowed)
    {
        $product = $this->product($ownerId);
        $this->assertSame($allowed, (new ProductPolicy())->update($this->user($actorId), $product));
        $this->assertFalse($product->relationLoaded('user'));
    }

    public function testAdministratorAndProductModeratorKeepTheirExistingPermissions()
    {
        $policy = new ProductPolicy();
        foreach ([$this->user('admin', true), $this->user('moderator', false, true)] as $actor) {
            $this->assertTrue($policy->update($actor, $this->product('owner-id')));
            $this->assertFalse($policy->update($actor, $this->product('owner-id', false)));
        }
        $this->assertFalse($policy->update($this->user('owner-id'), $this->product('owner-id', false)));
    }
}
