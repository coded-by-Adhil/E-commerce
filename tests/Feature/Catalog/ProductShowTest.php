<?php

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

it('lets a guest read an active product with variants and images', function () {
    $category = Category::factory()->create(['name' => 'Mugs', 'slug' => 'mugs']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Blue Mug',
        'slug' => 'blue-mug',
        'description' => 'A mug.',
    ]);
    $first = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'B-1', 'name' => 'Small', 'price' => 1500, 'stock' => 7]);
    $second = Variant::factory()->create(['product_id' => $product->id, 'sku' => 'B-2', 'name' => 'Large', 'price' => 1800, 'stock' => 0]);
    ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/b.jpg', 'alt_text' => 'Back', 'position' => 2]);
    ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/a.jpg', 'alt_text' => 'Front', 'position' => 1]);

    $response = $this->getJson('/api/v1/products/blue-mug')
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.name', 'Blue Mug')
        ->assertJsonPath('data.slug', 'blue-mug')
        ->assertJsonPath('data.description', 'A mug.')
        ->assertJsonPath('data.category', ['name' => 'Mugs', 'slug' => 'mugs'])
        ->assertJsonPath('data.variants', [
            ['id' => $first->id, 'sku' => 'B-1', 'name' => 'Small', 'price' => 1500, 'currency' => 'USD', 'in_stock' => true],
            ['id' => $second->id, 'sku' => 'B-2', 'name' => 'Large', 'price' => 1800, 'currency' => 'USD', 'in_stock' => false],
        ])
        ->assertJsonCount(2, 'data.images')
        ->assertJsonPath('data.images.*.position', [1, 2])
        ->assertJsonPath('data.images.*.alt_text', ['Front', 'Back']);

    expect($response->json('data.images.0.url'))->toEndWith('/storage/products/a.jpg');
});

it('returns empty variants and images for a product without any', function () {
    Product::factory()->create(['slug' => 'bare']);

    $this->getJson('/api/v1/products/bare')
        ->assertOk()
        ->assertJsonPath('data.variants', [])
        ->assertJsonPath('data.images', []);
});

it('never returns the stock number or the storage path', function () {
    $product = Product::factory()->create(['slug' => 'secret']);
    Variant::factory()->create(['product_id' => $product->id, 'stock' => 4242]);
    ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/private-name.jpg']);

    $json = $this->getJson('/api/v1/products/secret')->assertOk()->json('data');

    expect(array_keys($json['variants'][0]))->not->toContain('stock')
        ->and(array_keys($json['images'][0]))->toBe(['url', 'alt_text', 'position'])
        ->and(json_encode($json))->not->toContain('4242');
});

it('returns the conventions 404 for draft, archived, soft-deleted and unknown slugs', function (string $case) {
    match ($case) {
        'draft' => Product::factory()->create(['slug' => 'p', 'status' => ProductStatus::Draft]),
        'archived' => Product::factory()->create(['slug' => 'p', 'status' => ProductStatus::Archived]),
        'soft-deleted' => Product::factory()->create(['slug' => 'p'])->delete(),
        default => null,
    };

    $this->getJson($case === 'unknown' ? '/api/v1/products/does-not-exist' : '/api/v1/products/p')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertJsonStructure(['message', 'code', 'errors']);
})->with(['draft', 'archived', 'soft-deleted', 'unknown']);

it('runs the same number of queries for 1 and for 10 variants', function () {
    $product = Product::factory()->create(['slug' => 'many']);

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/api/v1/products/many')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Variant::factory()->create(['product_id' => $product->id]);
    ProductImage::factory()->create(['product_id' => $product->id]);
    $small = $countQueries();

    Variant::factory()->count(9)->create(['product_id' => $product->id]);
    ProductImage::factory()->count(4)->create(['product_id' => $product->id]);
    $large = $countQueries();

    expect($large)->toBe($small);
});
