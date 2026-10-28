<?php

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\QueryException;

it('stores a 26 char ULID as the public order number', function () {
    expect(strlen(Order::factory()->create()->number))->toBe(26);
});

it('rejects a duplicate idempotency key for the same user', function () {
    $user = User::factory()->create();
    Order::factory()->for($user)->create(['idempotency_key' => 'key-1']);

    expect(fn () => Order::factory()->for($user)->create(['idempotency_key' => 'key-1']))
        ->toThrow(QueryException::class);

    Order::factory()->create(['idempotency_key' => 'key-1']); // another user: allowed
});

it('rejects a second cart for the same user and a repeated variant in a cart', function () {
    $cart = Cart::factory()->create();
    $variant = Variant::factory()->create();
    CartItem::factory()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id]);

    expect(fn () => Cart::factory()->create(['user_id' => $cart->user_id]))->toThrow(QueryException::class)
        ->and(fn () => CartItem::factory()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id]))
        ->toThrow(QueryException::class);
});

it('keeps the order line when its variant is deleted (snapshot)', function () {
    $item = OrderItem::factory()->create();
    $sku = $item->sku;

    $item->variant?->delete();

    expect($item->fresh()->variant_id)->toBeNull()
        ->and($item->fresh()->sku)->toBe($sku);
});

it('does not delete a user who has orders', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->user?->delete())->toThrow(QueryException::class);
});

it('defaults status to pending and does not allow mass assigning it', function () {
    $order = Order::factory()->create();
    $order->fill(['status' => 'delivered'])->save();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});
