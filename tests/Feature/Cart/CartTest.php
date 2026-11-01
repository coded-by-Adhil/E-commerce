<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Stateful (SPA) requests, as in AuthTest.
    $this->withHeaders(['Referer' => 'http://localhost:5173']);
});

function cartVariant(int $price = 1000, int $stock = 10, ProductStatus $status = ProductStatus::Active): Variant
{
    $product = Product::factory()->create(['status' => $status]);

    return Variant::factory()->create(['product_id' => $product->id, 'price' => $price, 'stock' => $stock]);
}

it('returns 401 to a guest on both endpoints', function (string $method, string $uri) {
    $this->json($method, $uri, ['variant_id' => 1, 'quantity' => 1])->assertUnauthorized();
})->with([
    ['GET', '/api/v1/cart'],
    ['POST', '/api/v1/cart/items'],
]);

it('creates an empty cart on the first get', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/cart')
        ->assertOk()
        ->assertExactJson(['data' => ['items' => [], 'subtotal' => 0, 'currency' => 'USD', 'item_count' => 0]]);

    expect(Cart::where('user_id', $user->id)->count())->toBe(1);

    $this->actingAs($user)->getJson('/api/v1/cart')->assertOk();
    expect(Cart::where('user_id', $user->id)->count())->toBe(1);
});

it('adds an item and returns the full cart', function () {
    $user = User::factory()->create();
    $variant = cartVariant(price: 1500);
    ProductImage::factory()->create(['product_id' => $variant->product_id, 'path' => 'p/a.jpg', 'position' => 1]);
    ProductImage::factory()->create(['product_id' => $variant->product_id, 'path' => 'p/b.jpg', 'position' => 2]);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertOk()
        ->assertJsonPath('data.subtotal', 3000)
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.item_count', 2)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.product', ['name' => $variant->product->name, 'slug' => $variant->product->slug])
        ->assertJsonPath('data.items.0.variant.id', $variant->id)
        ->assertJsonPath('data.items.0.variant.price', 1500)
        ->assertJsonPath('data.items.0.unit_price', 1500)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.line_total', 3000)
        ->assertJsonPath('data.items.0.image.position', 1);

    $this->actingAs($user)->getJson('/api/v1/cart')->assertJsonPath('data.item_count', 2);
});

it('ignores a client-sent cart_id, user_id and price', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherCart = Cart::factory()->create(['user_id' => $other->id]);
    $variant = cartVariant(price: 1000);

    $this->actingAs($user)->postJson('/api/v1/cart/items', [
        'variant_id' => $variant->id,
        'quantity' => 1,
        'cart_id' => $otherCart->id,
        'user_id' => $other->id,
        'price' => 1,
        'unit_price' => 1,
    ])->assertOk()->assertJsonPath('data.subtotal', 1000);

    expect($otherCart->items()->count())->toBe(0)
        ->and(Cart::where('user_id', $user->id)->first()->items()->count())->toBe(1);
});

it('sums the quantity when the same variant is added twice', function () {
    $user = User::factory()->create();
    $variant = cartVariant(price: 500);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quantity', 5)
        ->assertJsonPath('data.subtotal', 2500);

    expect(CartItem::count())->toBe(1);
});

it('rejects a quantity above stock, including what is already in the cart', function () {
    $user = User::factory()->create();
    $variant = cartVariant(stock: 5);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 6])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('quantity', 'errors');

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 4])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity', 'errors');

    expect(CartItem::first()->quantity)->toBe(4)
        ->and($variant->fresh()->stock)->toBe(5);
});

it('rejects adding an out-of-stock variant and leaves stock unchanged', function () {
    $user = User::factory()->create();
    $variant = cartVariant(stock: 0);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity', 'errors');

    expect($variant->fresh()->stock)->toBe(0)->and(CartItem::count())->toBe(0);
});

it('does not change stock on a successful add', function () {
    $user = User::factory()->create();
    $variant = cartVariant(stock: 8);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])->assertOk();

    expect($variant->fresh()->stock)->toBe(8);
});

it('rejects a variant of a draft or archived product with 422', function (ProductStatus $status) {
    $user = User::factory()->create();
    $variant = cartVariant(status: $status);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('variant_id', 'errors');
})->with([ProductStatus::Draft, ProductStatus::Archived]);

it('rejects a soft-deleted product and an unknown variant with 422', function () {
    $user = User::factory()->create();
    $variant = cartVariant();
    $variant->product->delete();

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])
        ->assertStatus(422)->assertJsonValidationErrors('variant_id', 'errors');
    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => 9999, 'quantity' => 1])
        ->assertStatus(422)->assertJsonValidationErrors('variant_id', 'errors');
});

it('validates the body', function (array $body, string $field) {
    $user = User::factory()->create();
    $variant = cartVariant();

    $this->actingAs($user)->postJson('/api/v1/cart/items', array_merge(['variant_id' => $variant->id, 'quantity' => 1], $body))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field, 'errors');
})->with([
    'missing variant' => [['variant_id' => null], 'variant_id'],
    'quantity zero' => [['quantity' => 0], 'quantity'],
    'quantity 100' => [['quantity' => 100], 'quantity'],
    'quantity text' => [['quantity' => 'two'], 'quantity'],
    'quantity missing' => [['quantity' => null], 'quantity'],
]);

it('computes line totals and the subtotal for two lines from live prices', function () {
    $user = User::factory()->create();
    $a = cartVariant(price: 1999);
    $b = cartVariant(price: 500);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $a->id, 'quantity' => 2])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $b->id, 'quantity' => 3])
        ->assertOk()
        ->assertJsonPath('data.items.0.line_total', 3998)
        ->assertJsonPath('data.items.1.line_total', 1500)
        ->assertJsonPath('data.subtotal', 5498)
        ->assertJsonPath('data.item_count', 5);

    $a->update(['price' => 2000]);

    $this->actingAs($user)->getJson('/api/v1/cart')
        ->assertJsonPath('data.items.0.unit_price', 2000)
        ->assertJsonPath('data.subtotal', 5500);
});

it('still shows a line whose product was soft-deleted after it was added', function () {
    $user = User::factory()->create();
    $variant = cartVariant(price: 700);

    $this->actingAs($user)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
    $variant->product->delete();

    $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.subtotal', 700);
});

it('runs the same number of queries for 1 line and for 10', function () {
    $user = User::factory()->create();
    $cart = Cart::factory()->create(['user_id' => $user->id]);

    $addLines = function (int $n) use ($cart): void {
        foreach (range(1, $n) as $i) {
            $variant = cartVariant();
            ProductImage::factory()->count(2)->create(['product_id' => $variant->product_id]);
            CartItem::factory()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id]);
        }
    };

    $countQueries = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($user)->getJson('/api/v1/cart')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addLines(1);
    $small = $countQueries();
    $addLines(9);
    $large = $countQueries();

    expect($large)->toBe($small);
});
