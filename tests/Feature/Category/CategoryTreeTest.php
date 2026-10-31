<?php

use App\Models\Category;
use Illuminate\Support\Facades\DB;

it('lets a guest read the tree', function () {
    $this->getJson('/api/v1/categories')->assertOk();
});

it('returns an empty data array when there are no categories', function () {
    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('nests children and grandchildren under their parents', function () {
    $root = Category::factory()->create(['name' => 'Home']);
    $kitchen = Category::factory()->create(['name' => 'Kitchen', 'parent_id' => $root->id]);
    $garden = Category::factory()->create(['name' => 'Garden', 'parent_id' => $root->id]);
    $mugs = Category::factory()->create(['name' => 'Mugs', 'parent_id' => $kitchen->id]);

    $response = $this->getJson('/api/v1/categories')->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $root->id)
        ->assertJsonCount(2, 'data.0.children')
        ->assertJsonPath('data.0.children.0.id', $garden->id)
        ->assertJsonPath('data.0.children.0.children', [])
        ->assertJsonPath('data.0.children.1.id', $kitchen->id)
        ->assertJsonCount(1, 'data.0.children.1.children')
        ->assertJsonPath('data.0.children.1.children.0.id', $mugs->id)
        ->assertJsonPath('data.0.children.1.children.0.children', []);
});

it('exposes only id, name, slug and children', function () {
    Category::factory()->create(['name' => 'Home', 'slug' => 'home']);

    $this->getJson('/api/v1/categories')
        ->assertExactJson(['data' => [
            ['id' => 1, 'name' => 'Home', 'slug' => 'home', 'children' => []],
        ]]);
});

it('sorts roots and siblings by name', function () {
    Category::factory()->create(['name' => 'Toys']);
    $books = Category::factory()->create(['name' => 'Books']);
    Category::factory()->create(['name' => 'Novels', 'parent_id' => $books->id]);
    Category::factory()->create(['name' => 'Comics', 'parent_id' => $books->id]);

    $this->getJson('/api/v1/categories')
        ->assertJsonPath('data.*.name', ['Books', 'Toys'])
        ->assertJsonPath('data.0.children.*.name', ['Comics', 'Novels']);
});

it('runs exactly one query whatever the number of categories', function (int $roots) {
    foreach (range(1, $roots) as $i) {
        $root = Category::factory()->create(['name' => "Root {$i}"]);
        $child = Category::factory()->create(['parent_id' => $root->id]);
        Category::factory()->create(['parent_id' => $child->id]);
    }

    DB::enableQueryLog();
    $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount($roots, 'data');

    expect(DB::getQueryLog())->toHaveCount(1);
})->with([1, 10]);
