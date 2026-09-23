<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteIntegrityTest extends TestCase
{
    public function testCriticalRedirectTargetsHaveCanonicalNamedRoutes()
    {
        $this->assertSame(url('/'), route('home'));
        $this->assertSame(url('/forgotpassword'), route('auth.forgotpassword'));
        $this->assertSame(url('/forgotpassword/mnemonic'), route('auth.forgotpassword.mnemonic'));
        $this->assertSame(url('/forgotpassword/pgp'), route('auth.forgotpassword.pgp'));
    }

    public function testPurchaseCreationIsPostOnly()
    {
        $route = Route::getRoutes()->getByName('profile.cart.make.purchases');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
    }

    public function testEveryNamedRouteNameIsUnique()
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->getName()) {
                $names[] = $route->getName();
            }
        }

        $this->assertSame($names, array_values(array_unique($names)));
    }
}
