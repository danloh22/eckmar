<?php

namespace Tests\Unit;

use App\Purchase;
use App\User;
use Mockery;
use Tests\TestCase;

class PurchaseIdentityAuthorizationTest extends TestCase
{
    private function participant($id, $admin = false)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = $id;
        $user->shouldReceive('isAdmin')->andReturn($admin)->byDefault();

        return $user;
    }

    public function testBuyerAuthorizationUsesThePersistedIdentifierInsteadOfLoadedModelEquality()
    {
        $actor = $this->participant('buyer-id');
        $actor->username = 'current-buyer';

        $loadedBuyer = new User();
        $loadedBuyer->id = 'buyer-id';
        $loadedBuyer->username = 'stale-buyer';

        $purchase = new Purchase();
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';
        $purchase->setRelation('buyer', $loadedBuyer);

        $this->actingAs($actor);

        $this->assertTrue($purchase->isBuyer());
        $this->assertTrue($purchase->isAllowed());
    }

    public function testVendorAuthorizationDoesNotLazyLoadPurchaseRelations()
    {
        $actor = $this->participant('vendor-id');
        $purchase = new Purchase();
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';

        $this->actingAs($actor);

        $this->assertTrue($purchase->isVendor());
        $this->assertTrue($purchase->isAllowed());
        $this->assertFalse($purchase->relationLoaded('buyer'));
        $this->assertFalse($purchase->relationLoaded('vendor'));
    }

    public function testExplicitParticipantChecksAcceptEquivalentScalarIdsAndRejectMissingIds()
    {
        $buyer = $this->participant('123');
        $vendor = $this->participant('456');

        $purchase = new Purchase();
        $purchase->buyer_id = 123;
        $purchase->vendor_id = 456;

        $this->assertTrue($purchase->isBuyer($buyer));
        $this->assertTrue($purchase->isVendor($vendor));

        $purchase->buyer_id = '';
        $buyer->id = '';

        $this->assertFalse($purchase->isBuyer($buyer));
    }

    public function testUnrelatedUsersRemainDeniedAndAdminsRetainAccess()
    {
        $purchase = new Purchase();
        $purchase->buyer_id = 'buyer-id';
        $purchase->vendor_id = 'vendor-id';

        $unrelated = $this->participant('other-id');
        $this->actingAs($unrelated);

        $this->assertFalse($purchase->isBuyer());
        $this->assertFalse($purchase->isVendor());
        $this->assertFalse($purchase->isAllowed());

        $admin = $this->participant('admin-id', true);
        $this->actingAs($admin);

        $this->assertTrue($purchase->isAllowed());
    }
}
