<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\StockReason;
use App\Exceptions\InsufficientStockException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns the user's cart into an order in one transaction (D3, D4, D5).
 *
 * The returned order has wasRecentlyCreated = true for a new order and false for a replay
 * of the same Idempotency-Key.
 */
class PlaceOrder
{
    public function handle(User $user, string $idempotencyKey, Address $shipping, Address $billing): Order
    {
        if (($existing = $this->existing($user, $idempotencyKey)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->place($user, $idempotencyKey, $shipping, $billing));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key won the race (D5): return its order.
            $order = $this->existing($user, $idempotencyKey);

            if ($order === null) {
                throw $e;
            }

            return $order;
        }
    }

    private function place(User $user, string $key, Address $shipping, Address $billing): Order
    {
        // Lock the user's cart: a double submit waits here, then finds the first order below.
        $cart = Cart::query()->where('user_id', $user->id)->lockForUpdate()->first();

        if (($existing = $this->existing($user, $key, lock: true)) !== null) {
            return $existing;
        }

        $lines = $cart === null ? collect() : $cart->items()->orderBy('variant_id')->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        $activeProducts = Product::query()->active()
            ->whereIn('id', Variant::query()->whereIn('id', $lines->pluck('variant_id'))->select('product_id'))
            ->get()
            ->keyBy('id');

        $items = [];
        $outOfStock = [];

        foreach ($lines as $line) {
            // Atomic check + decrement (D4). The UPDATE also locks the variant row.
            $affected = DB::table('variants')
                ->where('id', $line->variant_id)
                ->where('stock', '>=', $line->quantity)
                ->decrement('stock', $line->quantity);

            if ($affected === 0) {
                $outOfStock[] = $line->variant_id;

                continue;
            }

            $variant = Variant::query()->findOrFail($line->variant_id);
            $product = $activeProducts->get($variant->product_id);

            if ($product === null) {
                throw ValidationException::withMessages(['cart' => 'An item in your cart is no longer available.']);
            }

            $items[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'product_name' => $product->name,
                'variant_name' => $variant->name,
                'unit_price' => $variant->price,
                'quantity' => $line->quantity,
                'line_total' => $variant->price * $line->quantity,
            ];
        }

        if ($outOfStock !== []) {
            throw new InsufficientStockException($outOfStock);
        }

        $subtotal = array_sum(array_column($items, 'line_total'));
        $shippingTotal = (int) config('shop.shipping_flat');

        $order = new Order([
            'number' => (string) Str::ulid(),
            'user_id' => $user->id,
            'currency' => config('shop.currency'),
            'subtotal' => $subtotal,
            'shipping_total' => $shippingTotal,
            'grand_total' => $subtotal + $shippingTotal,
            'shipping_address' => $this->snapshot($shipping),
            'billing_address' => $this->snapshot($billing),
            'idempotency_key' => $key,
            'placed_at' => now(),
        ]);
        $order->status = OrderStatus::Pending;
        $order->save();

        $order->items()->createMany($items);

        foreach ($items as $item) {
            StockMovement::query()->create([
                'variant_id' => $item['variant_id'],
                'delta' => -$item['quantity'],
                'reason' => StockReason::Sale,
                'order_id' => $order->id,
                'actor_id' => $user->id,
            ]);
        }

        $cart?->items()->delete();

        return $order->load('items');
    }

    /**
     * Reads the database, so two calls can differ (a concurrent request may commit in between).
     *
     * @phpstan-impure
     */
    private function existing(User $user, string $key, bool $lock = false): ?Order
    {
        $query = Order::query()->where('user_id', $user->id)->where('idempotency_key', $key);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->with('items')->first();
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(Address $address): array
    {
        return $address->only(['line1', 'line2', 'city', 'region', 'postal_code', 'country']);
    }
}
