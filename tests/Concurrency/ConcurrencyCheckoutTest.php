<?php

use App\Actions\PlaceOrder;
use App\Exceptions\InsufficientStockException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Concurrency;

/**
 * A variant with $stock units, and $buyers users who each have 1 of it in their cart.
 *
 * @return array{0: Variant, 1: list<array{0: int, 1: int}>} the variant and [userId, addressId] pairs
 */
function lastItemScenario(int $stock, int $buyers): array
{
    $variant = Variant::factory()->create([
        'product_id' => Product::factory()->create()->id,
        'price' => 1000,
        'stock' => $stock,
    ]);

    $pairs = [];
    foreach (range(1, $buyers) as $ignored) {
        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $pairs[] = [$user->id, $address->id];
    }

    return [$variant, $pairs];
}

/**
 * Runs PlaceOrder for every buyer in its own process, at the same time.
 * Only ids go into the closures; models are loaded inside the child process.
 *
 * @param  list<array{0: int, 1: int}>  $buyers
 * @return list<array<string, mixed>>
 */
function raceToCheckout(array $buyers): array
{
    $tasks = [];

    foreach ($buyers as [$userId, $addressId]) {
        $tasks[] = static function () use ($userId, $addressId): array {
            try {
                $address = Address::query()->findOrFail($addressId);
                $order = app(PlaceOrder::class)->handle(
                    User::query()->findOrFail($userId),
                    "race-{$userId}",
                    $address,
                    $address,
                );

                return ['user_id' => $userId, 'outcome' => 'placed', 'number' => $order->number];
            } catch (InsufficientStockException $e) {
                return ['user_id' => $userId, 'outcome' => 'insufficient', 'variant_ids' => $e->errors()['variant_ids']];
            }
        };
    }

    return array_values(Concurrency::driver('process')->run($tasks));
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return list<array<string, mixed>>
 */
function outcomes(array $results, string $outcome): array
{
    return array_values(array_filter($results, fn (array $r) => $r['outcome'] === $outcome));
}

it('scenario A: stock 1, two buyers, exactly one wins (round :dataset)', function (int $round) {
    [$variant, $buyers] = lastItemScenario(stock: 1, buyers: 2);

    $results = raceToCheckout($buyers);

    $placed = outcomes($results, 'placed');
    $failed = outcomes($results, 'insufficient');

    expect($results)->toHaveCount(2)
        ->and($placed)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['variant_ids'])->toBe([$variant->id])
        ->and($variant->fresh()->stock)->toBe(0)
        ->and(Order::count())->toBe(1)
        ->and(StockMovement::count())->toBe(1);

    $loserId = $failed[0]['user_id'];
    expect(Order::where('user_id', $loserId)->count())->toBe(0)
        ->and(CartItem::whereHas('cart', fn ($q) => $q->where('user_id', $loserId))->count())->toBe(1)
        ->and(Order::where('user_id', $placed[0]['user_id'])->value('number'))->toBe($placed[0]['number']);
})->with(range(1, 10));

it('scenario B: stock 2, three buyers, exactly two win (round :dataset)', function (int $round) {
    [$variant, $buyers] = lastItemScenario(stock: 2, buyers: 3);

    $results = raceToCheckout($buyers);

    $placed = outcomes($results, 'placed');
    $failed = outcomes($results, 'insufficient');

    expect($results)->toHaveCount(3)
        ->and($placed)->toHaveCount(2)
        ->and($failed)->toHaveCount(1)
        ->and($variant->fresh()->stock)->toBe(0)
        ->and(Order::count())->toBe(2)
        ->and(StockMovement::count())->toBe(2);

    $loserId = $failed[0]['user_id'];
    expect(Order::where('user_id', $loserId)->count())->toBe(0)
        ->and(CartItem::whereHas('cart', fn ($q) => $q->where('user_id', $loserId))->count())->toBe(1);
})->with(range(1, 10));
