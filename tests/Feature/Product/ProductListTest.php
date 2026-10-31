<?php

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $attrs
 */
function productWith(array $attrs = [], int $price = 1000, int $stock = 5): Product
{
    $product = Product::factory()->create($attrs);
    Variant::factory()->create(['product_id' => $product->id, 'price' => $price, 'stock' => $stock]);

    return $product;
}

it('lets a guest list active products with the list fields', function () {
    $category = Category::factory()->create(['name' => 'Mugs', 'slug' => 'mugs']);
    $product = productWith(['category_id' => $category->id, 'name' => 'Blue Mug', 'slug' => 'blue-mug'], 1500);
    Variant::factory()->create(['product_id' => $product->id, 'price' => 900, 'stock' => 0]);
    ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'p/second.jpg', 'position' => 2]);
    ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'p/first.jpg', 'alt_text' => 'Front', 'position' => 1]);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => $product->id,
            'name' => 'Blue Mug',
            'slug' => 'blue-mug',
            'price_from' => 900,
            'currency' => 'USD',
            'in_stock' => true,
            'image' => ['path' => 'p/first.jpg', 'alt_text' => 'Front'],
            'category' => ['name' => 'Mugs', 'slug' => 'mugs'],
        ])
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonCount(4, 'meta');
});

it('reports in_stock false and a null image when there is no stock or image', function () {
    productWith(stock: 0);

    $this->getJson('/api/v1/products')
        ->assertJsonPath('data.0.in_stock', false)
        ->assertJsonPath('data.0.image', null);
});

it('hides draft, archived and soft-deleted products', function () {
    $visible = productWith();
    productWith(['status' => ProductStatus::Draft]);
    productWith(['status' => ProductStatus::Archived]);
    productWith()->delete();

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $visible->id);
});

it('filters by category slug including descendants', function () {
    $men = Category::factory()->create(['slug' => 'men']);
    $shoes = Category::factory()->create(['slug' => 'shoes', 'parent_id' => $men->id]);
    $boots = Category::factory()->create(['slug' => 'boots', 'parent_id' => $shoes->id]);
    $women = Category::factory()->create(['slug' => 'women']);
    $own = productWith(['category_id' => $men->id]);
    $child = productWith(['category_id' => $shoes->id]);
    $grandchild = productWith(['category_id' => $boots->id]);
    productWith(['category_id' => $women->id]);

    $ids = collect($this->getJson('/api/v1/products?category=men')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$own->id, $child->id, $grandchild->id])->sort()->values()->all());
});

it('returns an empty list for an unknown category slug', function () {
    productWith();

    $this->getJson('/api/v1/products?category=nope')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('searches by name and escapes LIKE wildcards', function () {
    productWith(['name' => 'Red Mug']);
    productWith(['name' => 'Blue Plate']);
    productWith(['name' => '100% Cotton']);
    productWith(['name' => 'Snake_case Tee']);

    $names = fn (string $q) => collect($this->getJson('/api/v1/products?q='.urlencode($q))->assertOk()->json('data'))->pluck('name')->all();

    expect($names('mug'))->toBe(['Red Mug'])
        ->and($names('%'))->toBe(['100% Cotton'])
        ->and($names('_'))->toBe(['Snake_case Tee'])
        ->and($names('zzz'))->toBe([]);
});

it('sorts by price, -price, name and newest', function () {
    $b = productWith(['name' => 'Bravo', 'created_at' => now()->subDays(2)], 3000);
    $a = productWith(['name' => 'Alpha', 'created_at' => now()->subDays(3)], 2000);
    $c = productWith(['name' => 'Charlie', 'created_at' => now()->subDay()], 1000);

    $order = fn (string $sort) => collect($this->getJson("/api/v1/products?sort={$sort}")->assertOk()->json('data'))->pluck('id')->all();

    expect($order('price'))->toBe([$c->id, $a->id, $b->id])
        ->and($order('-price'))->toBe([$b->id, $a->id, $c->id])
        ->and($order('name'))->toBe([$a->id, $b->id, $c->id])
        ->and($order('newest'))->toBe([$c->id, $b->id, $a->id])
        ->and(collect($this->getJson('/api/v1/products')->json('data'))->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
});

it('rejects bad query params with 422', function (string $query, string $field) {
    $this->getJson("/api/v1/products?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors($field, 'errors');
})->with([
    'unknown sort' => ['sort=popularity', 'sort'],
    'sort injection' => ['sort=price;drop', 'sort'],
    'page zero' => ['page=0', 'page'],
    'per_page text' => ['per_page=abc', 'per_page'],
    'per_page zero' => ['per_page=0', 'per_page'],
]);

it('paginates 15 per page by default and caps per_page at 50', function () {
    Product::factory()->count(60)->create();

    $this->getJson('/api/v1/products')
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 60)
        ->assertJsonPath('meta.last_page', 4);

    $this->getJson('/api/v1/products?per_page=1000')
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.per_page', 50);

    $this->getJson('/api/v1/products?per_page=1&page=2')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2);
});

it('runs the same number of queries for 5 and for 50 products', function () {
    $root = Category::factory()->create(['slug' => 'root']);

    $makeProducts = function (int $n) use ($root): void {
        foreach (range(1, $n) as $i) {
            $child = Category::factory()->create(['parent_id' => $root->id]);
            $product = productWith(['category_id' => $child->id, 'name' => 'Apple '.uniqid()]);
            ProductImage::factory()->count(2)->create(['product_id' => $product->id]);
        }
    };

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/api/v1/products?category=root&q=apple&per_page=50')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $makeProducts(5);
    $small = $countQueries();
    $makeProducts(45);
    $large = $countQueries();

    expect($large)->toBe($small);
});
