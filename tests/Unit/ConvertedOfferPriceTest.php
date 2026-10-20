<?php

namespace Tests\Unit;

use App\Exceptions\RequestException;
use App\Http\Requests\Product\NewOfferRequest;
use App\Offer;
use App\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ConvertedOfferPriceTest extends TestCase
{
    /** @dataProvider invalidConversions */
    public function testInvalidConvertedPricesCannotChangeDraftsOrStoredOffers($rawUsd, $editing)
    {
        $connection = $this->createIsolatedOffersTable();
        $existing = new Offer();
        $existing->min_quantity = 9;
        $existing->price = 3;
        $offers = collect([$existing]);
        session()->put('product_offers', $offers);
        $this->mockConversion($rawUsd);

        try {
            try {
                $this->request()->persist($editing ? $this->editingProduct() : null);
                $this->fail('An invalid converted price must be rejected.');
            } catch (RequestException $error) {
                $this->assertSame(
                    'The converted offer price must be a finite amount greater than zero and below 100000000000000 USD.',
                    $error->getMessage()
                );
            }

            $this->assertSame(0, $connection->table('offers')->count());
            $this->assertSame($offers, session('product_offers'));
            $this->assertSame([$existing], session('product_offers')->all());
            $this->assertSame(3, $existing->price);
        } finally {
            $connection->getSchemaBuilder()->drop('offers');
        }
    }

    public function invalidConversions()
    {
        $cases = [];
        foreach ([false, true] as $editing) {
            foreach ([0.004, 0.005, 0.0, -1.0, 99999999999999.995, 100000000000000, 1000000000000000, INF, -INF, NAN] as $rawUsd) {
                $cases[] = [$rawUsd, $editing];
            }
        }
        return $cases;
    }

    /** @dataProvider positiveConversions */
    public function testPositiveConvertedPriceIsStoredInUsd($rawUsd, $expected, $editing)
    {
        $connection = $this->createIsolatedOffersTable();
        session()->put('product_offers', collect());
        $this->mockConversion($rawUsd);

        try {
            $this->request()->persist($editing ? $this->editingProduct() : null);

            if ($editing) {
                $row = $connection->table('offers')->first();
                $this->assertSame(1, $connection->table('offers')->count());
                $this->assertSame('conversion-product', $row->product_id);
                $this->assertSame($expected, (float) $row->price);
                $this->assertSame(0, session('product_offers')->count());
            } else {
                $this->assertSame(0, $connection->table('offers')->count());
                $this->assertSame(1, session('product_offers')->count());
                $this->assertSame($expected, (float) session('product_offers')->first()->price);
            }
        } finally {
            $connection->getSchemaBuilder()->drop('offers');
        }
    }

    public function positiveConversions()
    {
        return [
            [0.006, 0.01, false], [0.006, 0.01, true],
            [1.234, 1.23, false], [1.234, 1.23, true],
            [99999999999999, 99999999999999.0, false],
            [99999999999999, 99999999999999.0, true],
        ];
    }

    public function testUsdPriceIsUnchangedWhenCurrencyModuleIsDisabled()
    {
        \Module::shouldReceive('has')->once()->with('MultiCurrency')->andReturn(false);
        session()->put('product_offers', collect());

        $this->request()->persist();

        $this->assertSame('2.00', session('product_offers')->first()->price);
    }

    private function mockConversion($rawUsd)
    {
        // Exercise the real CurrencyConverter, including its half-even rounding.
        \Module::shouldReceive('has')->with('MultiCurrency')->andReturn(true);
        \Module::shouldReceive('enabled')->with('MultiCurrency')->andReturn(true);
        $converter = Mockery::mock();
        $converter->shouldReceive('convertFromLocal')->once()
            ->with('2.00', 'USD')->andReturn($rawUsd);
        $this->app->instance('MultiCurrencyModule\\Converter', $converter);
    }

    private function request()
    {
        $request = new NewOfferRequest();
        $request->replace(['min_quantity' => '1', 'price' => '2.00']);
        return $request;
    }

    private function editingProduct()
    {
        $product = Mockery::mock(Product::class)->makePartial();
        $product->id = 'conversion-product';
        $product->shouldReceive('exists')->once()->andReturn(true);
        return $product;
    }

    private function createIsolatedOffersTable()
    {
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getConfig('driver'));
        $this->assertSame(':memory:', $connection->getConfig('database'));
        $this->assertSame($connection, (new Offer())->getConnection());
        $connection->getSchemaBuilder()->create('offers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('product_id');
            $table->integer('min_quantity');
            $table->decimal('price', 16, 2);
            $table->timestamps();
        });
        return $connection;
    }
}
