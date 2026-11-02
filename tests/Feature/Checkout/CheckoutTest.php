<?php

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\StockReason;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // Stateful (SPA) requests, as in AuthTest.
    $this->withHeaders(['Referer' => 'http://localhost:5173']);
});

/**
 * Puts a line for a fresh variant into $user's cart.
 */
function cartLine(User $user, int $quantity = 2, int $price = 1000, int $stock = 10, ProductStatus $status = ProductStatus::Active, ?Variant $variant = null): Variant
{
    $variant ??= Variant::factory()->create([
        'product_id' => Product::factory()->create(['status' => $status])->id,
        'price' => $price,
        'stock' => $stock,
    ]);
    $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
    CartItem::factory()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => $quantity]);

    return $variant;
}

function checkout(mixed $test, User $user, array $body = [], ?string $key = null): TestResponse
{
    $address = $body['shipping_address_id'] ?? Address::factory()->create(['user_id' => $user->id])->id;

    return $test->actingAs($user)->postJson(
        '/api/v1/checkout',
        array_merge(['shipping_address_id' => $address], $body),
        ['Idempotency-Key' => $key ?? (string) Str::uuid()],
    );
}

it('returns 401 to a guest', function () {
    $this->postJson('/api/v1/checkout', ['shipping_address_id' => 1], ['Idempotency-Key' => 'k'])->assertUnauthorized();
});

it('requires the Idempotency-Key header', function () {
    $user = User::factory()->create();
    $address = Address::factory()->create(['user_id' => $user->id]);
    cartLine($user);

    $this->actingAs($user)->postJson('/api/v1/checkout', ['shipping_address_id' => $address->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('idempotency_key', 'errors');

    expect(Order::count())->toBe(0);
});

it('places an order, reduces stock, writes movements and clears the cart', function () {
    $user = User::factory()->create();
    $a = cartLine($user, quantity: 2, price: 1500, stock: 10);
    $b = cartLine($user, quantity: 3, price: 500, stock: 4);

    $response = checkout($this, $user)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.subtotal', 4500)
        ->assertJsonPath('data.shipping_total', 500)
        ->assertJsonPath('data.grand_total', 5000)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonMissingPath('data.id');

    expect($response->json('data.number'))->toHaveLength(26)
        ->and($response->json('data.placed_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $order = Order::with('items')->sole();
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($user->id)
        ->and($order->items)->toHaveCount(2)
        ->and($a->fresh()->stock)->toBe(8)
        ->and($b->fresh()->stock)->toBe(1)
        ->and(CartItem::count())->toBe(0);

    $movements = StockMovement::orderBy('variant_id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('delta')->all())->toBe([-2, -3])
        ->and($movements->every(fn ($m) => $m->reason === StockReason::Sale && $m->order_id === $order->id && $m->actor_id === $user->id))->toBeTrue();
});

it('snapshots items and addresses, and later changes do not alter the order', function () {
    $user = User::factory()->create();
    $variant = cartLine($user, quantity: 2, price: 1999);
    $shipping = Address::factory()->create(['user_id' => $user->id, 'line1' => '1 Ship St', 'country' => 'US']);
    $billing = Address::factory()->create(['user_id' => $user->id, 'line1' => '2 Bill Rd', 'country' => 'GB']);

    $response = checkout($this, $user, ['shipping_address_id' => $shipping->id, 'billing_address_id' => $billing->id])
        ->assertCreated()
        ->assertJsonPath('data.items.0.sku', $variant->sku)
        ->assertJsonPath('data.items.0.product_name', $variant->product->name)
        ->assertJsonPath('data.items.0.variant_name', $variant->name)
        ->assertJsonPath('data.items.0.unit_price', 1999)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.line_total', 3998)
        ->assertJsonPath('data.shipping_address.line1', '1 Ship St');

    $variant->update(['price' => 5, 'name' => 'Renamed']);
    $variant->product->update(['name' => 'Renamed Product']);
    $shipping->update(['line1' => 'Changed']);

    $order = Order::sole();
    expect($order->shipping_address['line1'])->toBe('1 Ship St')
        ->and($order->billing_address['line1'])->toBe('2 Bill Rd')
        ->and($order->billing_address['country'])->toBe('GB')
        ->and($order->grand_total)->toBe(4498)
        ->and(OrderItem::sole()->unit_price)->toBe(1999)
        ->and(OrderItem::sole()->product_name)->not->toBe('Renamed Product');
    expect($response->json('data.number'))->toBe($order->number);
});

it('defaults billing to the shipping address', function () {
    $user = User::factory()->create();
    cartLine($user);

    checkout($this, $user)->assertCreated();

    $order = Order::sole();
    expect($order->billing_address)->toBe($order->shipping_address);
});

it('ignores client-sent prices, items, totals and user_id', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    cartLine($user, quantity: 1, price: 1000);

    checkout($this, $user, [
        'user_id' => $other->id,
        'subtotal' => 1,
        'grand_total' => 1,
        'shipping_total' => 0,
        'price' => 1,
        'items' => [['variant_id' => 999, 'quantity' => 50, 'price' => 1]],
    ])->assertCreated()
        ->assertJsonPath('data.subtotal', 1000)
        ->assertJsonPath('data.grand_total', 1500)
        ->assertJsonCount(1, 'data.items');

    expect(Order::sole()->user_id)->toBe($user->id);
});

it('returns the same order with 200 and decrements nothing when the key is replayed', function () {
    $user = User::factory()->create();
    $variant = cartLine($user, quantity: 2, stock: 10);
    $address = Address::factory()->create(['user_id' => $user->id]);
    $body = ['shipping_address_id' => $address->id];

    $first = checkout($this, $user, $body, 'same-key')->assertCreated();

    // The cart is empty now; a replay must not fail with "cart is empty".
    $second = checkout($this, $user, $body, 'same-key')->assertOk();

    expect($second->json('data.number'))->toBe($first->json('data.number'))
        ->and($second->json('data'))->toBe($first->json('data'))
        ->and(Order::count())->toBe(1)
        ->and(StockMovement::count())->toBe(1)
        ->and($variant->fresh()->stock)->toBe(8);
});

it('treats the same key from two different users as two orders', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    cartLine($a);
    cartLine($b);

    checkout($this, $a, key: 'shared')->assertCreated();
    checkout($this, $b, key: 'shared')->assertCreated();

    expect(Order::count())->toBe(2);
});

it('rejects an empty cart with 422 and creates nothing', function () {
    $user = User::factory()->create();

    checkout($this, $user)->assertStatus(422)->assertJsonValidationErrors('cart', 'errors');

    expect(Order::count())->toBe(0);
});

it('rejects a draft or archived product in the cart with 422 and changes nothing', function (ProductStatus $status) {
    $user = User::factory()->create();
    $ok = cartLine($user, stock: 10);
    $bad = cartLine($user, status: $status, stock: 10);

    checkout($this, $user)->assertStatus(422)->assertJsonValidationErrors('cart', 'errors');

    expect(Order::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($ok->fresh()->stock)->toBe(10)
        ->and($bad->fresh()->stock)->toBe(10)
        ->and(CartItem::count())->toBe(2);
})->with([ProductStatus::Draft, ProductStatus::Archived]);

it('gives the last unit to the first buyer and 409 to the second', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $variant = cartLine($a, quantity: 1, stock: 1);
    cartLine($b, quantity: 1, variant: $variant);

    checkout($this, $a)->assertCreated();

    checkout($this, $b)
        ->assertStatus(409)
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('errors.variant_ids', [$variant->id]);

    expect($variant->fresh()->stock)->toBe(0)
        ->and(Order::where('user_id', $b->id)->count())->toBe(0)
        ->and(CartItem::whereHas('cart', fn ($q) => $q->where('user_id', $b->id))->count())->toBe(1);
});

it('rolls back the first line when the second line fails', function () {
    $user = User::factory()->create();
    $first = cartLine($user, quantity: 2, stock: 10);
    $second = cartLine($user, quantity: 5, stock: 1);

    // Lines are processed by ascending variant id, so $first is decremented before $second fails.
    expect($first->id)->toBeLessThan($second->id);

    checkout($this, $user)
        ->assertStatus(409)
        ->assertJsonPath('errors.variant_ids', [$second->id]);

    expect($first->fresh()->stock)->toBe(10)
        ->and($second->fresh()->stock)->toBe(1)
        ->and(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and(CartItem::count())->toBe(2);
});

it('rolls back the first line when a later line has an inactive product', function () {
    $user = User::factory()->create();
    $first = cartLine($user, quantity: 2, stock: 10);
    cartLine($user, quantity: 1, stock: 10, status: ProductStatus::Draft);

    checkout($this, $user)->assertStatus(422);

    expect($first->fresh()->stock)->toBe(10)
        ->and(Order::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('reports every variant that is out of stock', function () {
    $user = User::factory()->create();
    $a = cartLine($user, quantity: 5, stock: 1);
    $b = cartLine($user, quantity: 5, stock: 1);

    checkout($this, $user)
        ->assertStatus(409)
        ->assertJsonPath('errors.variant_ids', [$a->id, $b->id]);
});

it('rejects another user\'s address with 422', function (string $field) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $own = Address::factory()->create(['user_id' => $user->id]);
    $foreign = Address::factory()->create(['user_id' => $other->id]);
    cartLine($user);

    $body = $field === 'shipping_address_id'
        ? ['shipping_address_id' => $foreign->id]
        : ['shipping_address_id' => $own->id, 'billing_address_id' => $foreign->id];

    checkout($this, $user, $body)->assertStatus(422)->assertJsonValidationErrors($field, 'errors');

    expect(Order::count())->toBe(0);
})->with(['shipping_address_id', 'billing_address_id']);

it('requires a shipping address', function () {
    $user = User::factory()->create();
    cartLine($user);

    $this->actingAs($user)->postJson('/api/v1/checkout', [], ['Idempotency-Key' => 'k'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('shipping_address_id', 'errors');
});

it('uses the configured shipping amount', function () {
    config(['shop.shipping_flat' => 0]);
    $user = User::factory()->create();
    cartLine($user, quantity: 1, price: 1000);

    checkout($this, $user)->assertCreated()
        ->assertJsonPath('data.shipping_total', 0)
        ->assertJsonPath('data.grand_total', 1000);
});
