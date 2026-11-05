<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductController;
use App\Http\Requests\Admin\RemoveProductFromFeaturedReuqest;
use App\Product;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminFeaturedProductsAuthorizationTest extends TestCase
{
    private function signInStaff($admin, array $permissions)
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'featured-staff-test';
        $user->shouldReceive('isAdmin')->andReturn($admin);
        $user->shouldReceive('hasPermissions')->andReturn($admin || !empty($permissions));
        $user->shouldReceive('hasPermission')->andReturnUsing(function ($permission) use ($permissions) {
            return in_array($permission, $permissions, true);
        });
        $this->actingAs($user);
    }

    public function testUnrelatedModeratorCannotReadFeaturedManagementList()
    {
        $this->signInStaff(false, ['categories']);
        // No products table exists: authorization must fail before querying it.
        try {
            (new ProductController())->featuredProductsShow();
            $this->fail('A category moderator must not access product management.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function testUnrelatedModeratorCannotResolveProductBeforeFeaturedMarkAuthorization()
    {
        $this->signInStaff(false, ['categories']);
        // No products table exists: authorization must fail before route input is resolved.
        try {
            (new ProductController())->markAsFeatured('missing-product');
            $this->fail('A category moderator must not mark products as featured.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function testUnrelatedModeratorCannotQueryProductWhenRemovingFeaturedStatus()
    {
        $this->signInStaff(false, ['categories']);
        // No products table exists: FormRequest authorization must fail before exists validation.
        $request = RemoveProductFromFeaturedReuqest::create(
            route('admin.featuredproducts.remove'),
            'POST',
            ['product_id' => 'missing-product']
        );
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(function () {
            return auth()->user();
        });

        try {
            $request->validateResolved();
            $this->fail('A category moderator must be rejected before product validation.');
        } catch (AuthorizationException $exception) {
            $this->assertFalse($exception->getMessage() === '');
        }
    }

    /** @dataProvider authorizedStaff */
    public function testProductModeratorAndAdministratorCanRemoveFeaturedStatus($admin, $permissions)
    {
        $this->signInStaff($admin, $permissions);
        Event::fake();
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->boolean('featured');
            $table->timestamps();
        });

        try {
            $connection->table('products')->insert([
                'id' => 'featured-product',
                'featured' => true,
                'created_at' => null,
                'updated_at' => null,
            ]);

            $response = $this->post(route('admin.featuredproducts.remove'), [
                'product_id' => 'featured-product',
            ]);

            $response->assertStatus(302);
            $this->assertFalse((bool) $connection->table('products')
                ->where('id', 'featured-product')->value('featured'));
        } finally {
            $schema->drop('products');
        }
    }

    public function authorizedStaff()
    {
        return [[false, ['products']], [true, []]];
    }

    /** @dataProvider authorizedStaff */
    public function testProductModeratorAndAdministratorCanReadFeaturedProducts($admin, $permissions)
    {
        $this->signInStaff($admin, $permissions);
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getConfig('driver'));
        $this->assertSame(':memory:', $connection->getConfig('database'));
        $this->assertSame($connection, (new Product())->getConnection());
        $schema = $connection->getSchemaBuilder();
        $schema->create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->boolean('featured');
        });

        try {
            $connection->table('products')->insert([
                ['id' => 'featured-product', 'featured' => true],
                ['id' => 'ordinary-product', 'featured' => false],
            ]);
            $view = (new ProductController())->featuredProductsShow();
            $products = $view->getData()['products'];

            $this->assertSame('admin.featuredproducts', $view->name());
            $this->assertSame(1, $products->total());
            $this->assertSame('featured-product', $products->first()->id);
        } finally {
            $schema->drop('products');
        }
    }
}
