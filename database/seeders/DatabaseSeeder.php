<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Seeding is disabled in production.');
        }

        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        User::factory()->create([
            'name' => 'Customer',
            'email' => 'customer@example.com',
        ]);

        $categories = collect(['Clothing', 'Home', 'Electronics'])->map(
            fn (string $name) => Category::factory()->create([
                'name' => $name,
                'slug' => Str::slug($name),
            ])
        );

        foreach (range(1, 10) as $i) {
            $product = Product::factory()->create([
                'category_id' => $categories[$i % 3]->id,
            ]);

            Variant::factory()->count(fake()->numberBetween(2, 3))->create([
                'product_id' => $product->id,
            ]);

            ProductImage::factory()->create(['product_id' => $product->id]);
        }
    }
}
