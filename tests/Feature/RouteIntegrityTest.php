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
        $this->assertSame(url('/rates'), route('market.rates'));
    }

    public function testPurchaseCreationIsPostOnly()
    {
        $route = Route::getRoutes()->getByName('profile.cart.make.purchases');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.cart.clear')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.cart.remove')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.sales.sent')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.purchases.delivered')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.purchases.canceled')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.2fa.change')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.wishlist.add')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.vendor.become')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.vendor.address.remove')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.vendor.product.offers.remove')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.vendor.product.delivery.remove')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.vendor.product.images.remove')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.vendor.product.images.default')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.vendor.product.remove')->methods());
    }

    public function testWalletExchangeAndFeeAddressUpdatesArePostOnly()
    {
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.wallet.exchange')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('profile.wallet.pin.support')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.wallet.fee-addresses.update')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.wallet.liquidity.adjust')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.wallets.status')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.wallets.adjust')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.wallet.withdrawals.resolve-failed')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.wallet.fee-sweeps.resolve-failed')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.categories.delete')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.ban.remove')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.tickets.solve')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.product.markfeatured')->methods());
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
