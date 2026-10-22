<?php

namespace Tests\Feature;

use App\Dispute;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ProductController;
use App\Purchase;
use App\User;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminPurchaseAuthorizationTest extends TestCase
{
    private function signInStaff($admin, array $permissions)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'purchase-staff-test';
        $user->shouldReceive('isAdmin')->andReturn($admin);
        $user->shouldReceive('hasPermission')->andReturnUsing(function ($permission) use ($permissions) {
            return in_array($permission, $permissions, true);
        });
        $this->actingAs($user);
    }

    public function forbiddenReads()
    {
        return [
            ['purchase', ['categories'], true],
            ['purchase', ['disputes'], false],
            ['purchases', ['categories'], false],
            ['purchases', ['disputes'], false],
            ['vendorPurchases', ['categories'], false],
            ['vendorPurchases', ['purchases'], false],
        ];
    }

    /** @dataProvider forbiddenReads */
    public function testModeratorCannotReadOutsideGrantedScope($action, $permissions, $hasDispute)
    {
        $this->signInStaff(false, $permissions);
        $purchase = new Purchase();
        $purchase->setRelation('dispute', $hasDispute ? new Dispute() : null);
        $this->expectException(HttpException::class);

        try {
            if ($action === 'purchases') {
                (new ProductController())->purchases();
            } elseif ($action === 'vendorPurchases') {
                (new AdminController())->vendorPurchases();
            } else {
                (new AdminController())->purchase($purchase);
            }
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            throw $exception;
        }
    }

    public function permittedDetails()
    {
        return [
            [true, [], false],
            [false, ['purchases'], false],
            [false, ['disputes'], true],
        ];
    }

    /** @dataProvider permittedDetails */
    public function testPurchaseReadersAndDisputeModeratorsRetainTheirDetailAccess($admin, $permissions, $hasDispute)
    {
        $this->signInStaff($admin, $permissions);
        $purchase = new Purchase();
        $purchase->setRelation('dispute', $hasDispute ? new Dispute() : null);

        $view = (new AdminController())->purchase($purchase);

        $this->assertSame('admin.purchase', $view->name());
        $this->assertSame($purchase, $view->getData()['purchase']);
    }
}
