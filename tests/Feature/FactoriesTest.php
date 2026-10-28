<?php

use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Database\Seeders\DatabaseSeeder;

it('creates a valid model from every factory', function (string $model) {
    $instance = $model::factory()->create();

    expect($instance->exists)->toBeTrue();
})->with([
    User::class,
    Address::class,
    Category::class,
    Product::class,
    Variant::class,
    ProductImage::class,
]);

it('defaults to the customer role and can make an admin', function () {
    expect(User::factory()->create()->role)->toBe(UserRole::Customer)
        ->and(User::factory()->admin()->create()->role)->toBe(UserRole::Admin);
});

it('can make a draft product', function () {
    expect(Product::factory()->draft()->create()->status)->toBe(ProductStatus::Draft);
});

it('can make an out of stock variant', function () {
    expect(Variant::factory()->outOfStock()->create()->stock)->toBe(0);
});

it('does not allow mass assigning role or stock', function () {
    $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass-123', 'role' => 'admin']);
    $variant = Variant::factory()->create(['stock' => 7]);
    $variant->fill(['stock' => 999])->save();

    expect($user->fresh()->role)->toBe(UserRole::Customer)
        ->and($variant->fresh()->stock)->toBe(7);
});

it('seeds the catalog', function () {
    $this->seed();

    expect(User::where('role', UserRole::Admin)->count())->toBe(1)
        ->and(Category::count())->toBe(3)
        ->and(Product::count())->toBe(10)
        ->and(Variant::count())->toBeGreaterThanOrEqual(20);
});

it('refuses to seed in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class);
    expect(User::count())->toBe(0);
});
