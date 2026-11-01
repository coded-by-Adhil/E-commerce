<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cart only checks stock; it never changes it (D4). Checkout decrements it.
 */
class CartService
{
    public function forUser(User $user): Cart
    {
        return Cart::query()->createOrFirst(['user_id' => $user->id]);
    }

    /**
     * Adds to the cart, or raises the quantity of an existing line.
     * The cart row is locked so two concurrent adds for one user run one after the other.
     */
    public function add(User $user, int $variantId, int $quantity): Cart
    {
        $cart = $this->forUser($user);

        DB::transaction(function () use ($cart, $variantId, $quantity) {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

            $variant = Variant::query()->findOrFail($variantId);
            $current = (int) $cart->items()->where('variant_id', $variantId)->value('quantity');
            $wanted = $current + $quantity;

            $this->assertInStock($variant, $wanted);

            $cart->items()->updateOrCreate(['variant_id' => $variantId], ['quantity' => $wanted]);
        });

        return $cart;
    }

    /**
     * SETS the quantity of one of the user's own lines (it does not add).
     * 404 if the line is not in this user's cart; 422 if the product is no longer active
     * or the quantity is above stock.
     */
    public function setQuantity(User $user, int|string $itemId, int $quantity): Cart
    {
        $cart = $this->forUser($user);

        DB::transaction(function () use ($cart, $itemId, $quantity) {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

            $item = $cart->items()->findOrFail($itemId);
            $variant = Variant::query()->findOrFail($item->variant_id);

            if (! Product::query()->active()->whereKey($variant->product_id)->exists()) {
                throw ValidationException::withMessages(['quantity' => 'This item is no longer available.']);
            }

            $this->assertInStock($variant, $quantity);

            $item->update(['quantity' => $quantity]);
        });

        return $cart;
    }

    /**
     * Never blocked by availability: a line for a draft or sold-out item can always be removed.
     */
    public function remove(User $user, int|string $itemId): Cart
    {
        $cart = $this->forUser($user);

        $cart->items()->findOrFail($itemId)->delete();

        return $cart;
    }

    /**
     * The one stock rule for add and update. Reads stock only (D4).
     */
    private function assertInStock(Variant $variant, int $wanted): void
    {
        if ($wanted > $variant->stock) {
            throw ValidationException::withMessages([
                'quantity' => $variant->stock > 0
                    ? "Only {$variant->stock} in stock."
                    : 'This item is out of stock.',
            ]);
        }
    }

    /**
     * The cart with everything the resources need, in a fixed number of queries.
     */
    public function load(Cart $cart): Cart
    {
        return $cart->load([
            'items' => fn ($query) => $query->orderBy('id'),
            'items.variant.product' => fn ($query) => $query->withTrashed(),
            'items.variant.product.images',
        ]);
    }
}
