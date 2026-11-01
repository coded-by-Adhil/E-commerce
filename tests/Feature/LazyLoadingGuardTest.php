<?php

use App\Models\Product;
use Illuminate\Database\LazyLoadingViolationException;

it('throws on lazy loading outside production', function () {
    Product::factory()->count(2)->create();

    // Laravel guards models loaded as a collection, where N+1 can happen.
    $product = Product::query()->get()->first();

    expect(fn () => $product->category)->toThrow(LazyLoadingViolationException::class);
});

it('allows relations that were eager loaded', function () {
    Product::factory()->create();

    $product = Product::query()->with('category')->first();

    expect($product->category)->not->toBeNull();
});
