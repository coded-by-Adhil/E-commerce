<?php

namespace App\Services;

use App\Models\Cart;
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

            if ($wanted > $variant->stock) {
                throw ValidationException::withMessages([
                    'quantity' => $variant->stock > 0
                        ? "Only {$variant->stock} in stock."
                        : 'This item is out of stock.',
                ]);
            }

            $cart->items()->updateOrCreate(['variant_id' => $variantId], ['quantity' => $wanted]);
        });

        return $cart;
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
