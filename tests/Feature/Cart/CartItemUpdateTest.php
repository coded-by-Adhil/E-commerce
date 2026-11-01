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

/**
 * A cart line for $user on a fresh variant.
 */
function lineFor(User $user, int $quantity = 2, int $price = 1000, int $stock = 10, ProductStatus $status = ProductStatus::Active): CartItem
{
    $product = Product::factory()->create(['status' => $status]);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'price' => $price, 'stock' => $stock]);
    $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

    return CartItem::factory()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => $quantity]);
}

it('returns 401 to a guest on both endpoints', function (string $method) {
    $this->json($method, '/api/v1/cart/items/1', ['quantity' => 1])->assertUnauthorized();
})->with(['PATCH', 'DELETE']);

it('sets the quantity instead of adding to it and recalculates totals', function () {
    $user = User::factory()->create();
    $line = lineFor($user, quantity: 2, price: 1500);
    $other = lineFor($user, quantity: 1, price: 500);

    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 5])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 5)
        ->assertJsonPath('data.items.0.line_total', 7500)
        ->assertJsonPath('data.items.1.line_total', 500)
        ->assertJsonPath('data.subtotal', 8000)
        ->assertJsonPath('data.item_count', 6);

    expect($line->fresh()->quantity)->toBe(5)->and($other->fresh()->quantity)->toBe(1);
});

it('can lower the quantity and set it to exactly the stock', function () {
    $user = User::factory()->create();
    $line = lineFor($user, quantity: 4, stock: 6);

    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 6])->assertOk();
    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 1])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 1);
});

it('rejects a quantity above stock and leaves the line unchanged', function () {
    $user = User::factory()->create();
    $line = lineFor($user, quantity: 2, stock: 5);

    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 6])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('quantity', 'errors');

    expect($line->fresh()->quantity)->toBe(2);
});

it('rejects an invalid quantity with 422', function (mixed $quantity) {
    $user = User::factory()->create();
    $line = lineFor($user);

    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => $quantity])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity', 'errors');

    expect($line->fresh()->quantity)->toBe(2);
})->with([0, 100, 'abc', null, -1]);

it('rejects an update for a draft or archived product with 422', function (ProductStatus $status) {
    $user = User::factory()->create();
    $line = lineFor($user, status: $status);

    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quantity', 'errors');

    expect($line->fresh()->quantity)->toBe(2);
})->with([ProductStatus::Draft, ProductStatus::Archived]);

it('removes a line and returns the cart', function () {
    $user = User::factory()->create();
    $keep = lineFor($user, quantity: 1, price: 300);
    $gone = lineFor($user, quantity: 2, price: 100);

    $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$gone->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $keep->id)
        ->assertJsonPath('data.subtotal', 300);

    expect(CartItem::find($gone->id))->toBeNull();
});

it('returns an empty cart after the last line is deleted', function () {
    $user = User::factory()->create();
    $line = lineFor($user);

    $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$line->id}")
        ->assertOk()
        ->assertExactJson(['data' => ['items' => [], 'subtotal' => 0, 'currency' => 'USD', 'item_count' => 0]]);
});

it('deletes a line whose product is draft or whose variant is out of stock', function () {
    $user = User::factory()->create();
    $draft = lineFor($user, status: ProductStatus::Draft);
    $soldOut = lineFor($user, quantity: 3, stock: 0);

    $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$draft->id}")->assertOk();
    $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$soldOut->id}")
        ->assertOk()
        ->assertJsonPath('data.items', []);
});

it('returns the conventions 404 for an unknown line', function (string $method) {
    $user = User::factory()->create();

    $this->actingAs($user)->json($method, '/api/v1/cart/items/9999', ['quantity' => 1])
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertJsonStructure(['message', 'code', 'errors']);
})->with(['PATCH', 'DELETE']);

it('returns 404 and leaves the line alone when it belongs to another user', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $line = lineFor($b, quantity: 2);
    $aCart = Cart::factory()->create(['user_id' => $a->id]);

    $this->actingAs($a)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 5])->assertNotFound();
    $this->actingAs($a)->deleteJson("/api/v1/cart/items/{$line->id}")->assertNotFound();

    // A client-sent cart_id (B's or A's) changes nothing.
    $this->actingAs($a)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 5, 'cart_id' => $line->cart_id])->assertNotFound();
    $this->actingAs($a)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 5, 'cart_id' => $aCart->id])->assertNotFound();

    expect($line->fresh()->quantity)->toBe(2)->and(CartItem::find($line->id))->not->toBeNull();
});

it('never changes stock', function () {
    $user = User::factory()->create();
    $line = lineFor($user, quantity: 2, stock: 9);
    $variantId = $line->variant_id;

    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 4])->assertOk();
    $this->actingAs($user)->patchJson("/api/v1/cart/items/{$line->id}", ['quantity' => 99])->assertStatus(422);
    $this->actingAs($user)->deleteJson("/api/v1/cart/items/{$line->id}")->assertOk();

    expect(Variant::find($variantId)->stock)->toBe(9);
});

it('runs the same number of queries for a small and a large cart', function (string $method) {
    $user = User::factory()->create();
    lineFor($user);

    // Each measurement targets a fresh line, so the update or delete always happens.
    $countQueries = function () use ($user, $method): int {
        $target = lineFor($user, quantity: 1, stock: 50);

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($user)->json($method, "/api/v1/cart/items/{$target->id}", ['quantity' => 2])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $small = $countQueries();

    foreach (range(1, 9) as $i) {
        $line = lineFor($user);
        ProductImage::factory()->count(2)->create(['product_id' => $line->variant->product_id]);
    }
    $large = $countQueries();

    expect($large)->toBe($small);
})->with(['PATCH', 'DELETE']);
