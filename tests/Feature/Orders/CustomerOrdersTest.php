<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Stateful (SPA) requests, as in AuthTest.
    $this->withHeaders(['Referer' => 'http://localhost:5173']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function placedOrder(User $user, array $attributes = []): Order
{
    return Order::factory()->create(['user_id' => $user->id, ...$attributes]);
}

/**
 * Adds a line backed by a real variant (and product) to the order.
 *
 * @param  array<string, mixed>  $attributes
 */
function lineOn(Order $order, array $attributes = []): OrderItem
{
    $variant = Variant::factory()->create();

    return OrderItem::factory()->create([
        'order_id' => $order->id,
        'variant_id' => $variant->id,
        ...$attributes,
    ]);
}

it('returns 401 to a guest on both endpoints', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized();
})->with(['/api/v1/orders', '/api/v1/orders/01ARZ3NDEKTSV4RRFFQ69G5FAV']);

it('lists only the user\'s own orders, newest first', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $old = placedOrder($user, ['placed_at' => now()->subDays(3)]);
    $new = placedOrder($user, ['placed_at' => now()->subDay()]);
    $theirs = placedOrder($other, ['placed_at' => now()]);

    $numbers = collect($this->actingAs($user)->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('number')->all();

    expect($numbers)->toBe([$new->number, $old->number])->and($numbers)->not->toContain($theirs->number);
});

it('breaks placed_at ties by newest id', function () {
    $user = User::factory()->create();
    $at = now()->subDay();
    $first = placedOrder($user, ['placed_at' => $at]);
    $second = placedOrder($user, ['placed_at' => $at]);

    $this->actingAs($user)->getJson('/api/v1/orders')
        ->assertJsonPath('data.*.number', [$second->number, $first->number]);
});

it('returns the summary fields, with the quantity total as item_count', function () {
    $user = User::factory()->create();
    $order = placedOrder($user, ['status' => OrderStatus::Paid, 'currency' => 'USD', 'grand_total' => 5500]);
    lineOn($order, ['quantity' => 2]);
    lineOn($order, ['quantity' => 3]);

    $this->actingAs($user)->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'number' => $order->number,
            'status' => 'paid',
            'placed_at' => $order->placed_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'currency' => 'USD',
            'grand_total' => 5500,
            'item_count' => 5,
        ])
        ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total', 'last_page']])
        ->assertJsonCount(4, 'meta');
});

it('lists an order with no lines with item_count 0', function () {
    $user = User::factory()->create();
    placedOrder($user);

    $this->actingAs($user)->getJson('/api/v1/orders')->assertJsonPath('data.0.item_count', 0);
});

it('includes cancelled orders and filters by status', function () {
    $user = User::factory()->create();
    $paid = placedOrder($user, ['status' => OrderStatus::Paid]);
    $cancelled = placedOrder($user, ['status' => OrderStatus::Cancelled]);
    placedOrder($user, ['status' => OrderStatus::Pending]);

    $all = $this->actingAs($user)->getJson('/api/v1/orders')->assertOk();
    expect($all->json('data.*.number'))->toContain($cancelled->number)->and($all->json('meta.total'))->toBe(3);

    $this->actingAs($user)->getJson('/api/v1/orders?status=paid')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.number', $paid->number);
});

it('rejects an invalid status or bad paging with 422', function (string $query, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson("/api/v1/orders?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors($field, 'errors');
})->with([
    'unknown status' => ['status=shipping', 'status'],
    'page zero' => ['page=0', 'page'],
    'per_page text' => ['per_page=abc', 'per_page'],
]);

it('paginates 15 per page by default and caps per_page at 50', function () {
    $user = User::factory()->create();
    Order::factory()->count(60)->create(['user_id' => $user->id]);

    $this->actingAs($user)->getJson('/api/v1/orders')
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 60);

    $this->actingAs($user)->getJson('/api/v1/orders?per_page=1000')
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.per_page', 50);
});

it('shows the full order with snapshot items, history in order and the latest payment', function () {
    $user = User::factory()->create();
    $order = placedOrder($user, ['status' => OrderStatus::Paid]);
    $line = lineOn($order, ['sku' => 'SKU-1', 'product_name' => 'Blue Mug', 'variant_name' => 'Large', 'unit_price' => 1500, 'quantity' => 2, 'line_total' => 3000]);
    ProductImage::factory()->create(['product_id' => $line->variant->product_id, 'path' => 'p/b.jpg', 'alt_text' => 'Back', 'position' => 2]);
    ProductImage::factory()->create(['product_id' => $line->variant->product_id, 'path' => 'p/a.jpg', 'alt_text' => 'Front', 'position' => 1]);

    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Pending, 'created_at' => now()->subHours(2)]);
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Paid, 'reason' => 'secret reason', 'actor_id' => User::factory()->create()->id, 'created_at' => now()->subHour()]);
    Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Failed, 'created_at' => now()->subHours(2)]);
    Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Succeeded, 'provider_ref' => 'pi_SECRETREF123', 'created_at' => now()->subHour()]);

    $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->number}")
        ->assertOk()
        ->assertJsonPath('data.number', $order->number)
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.items.0.sku', 'SKU-1')
        ->assertJsonPath('data.items.0.product_name', 'Blue Mug')
        ->assertJsonPath('data.items.0.variant_name', 'Large')
        ->assertJsonPath('data.items.0.unit_price', 1500)
        ->assertJsonPath('data.items.0.line_total', 3000)
        ->assertJsonPath('data.items.0.image.alt_text', 'Front')
        ->assertJsonPath('data.status_history.*.status', ['pending', 'paid'])
        ->assertJsonPath('data.payment', ['status' => 'succeeded'])
        ->assertJsonPath('data.shipping_address.country', 'US');

    expect($response->json('data.items.0.image.url'))->toEndWith('/storage/p/a.jpg')
        ->and(array_keys($response->json('data.items.0.image')))->toBe(['url', 'alt_text'])
        ->and(array_keys($response->json('data.status_history.0')))->toBe(['status', 'at']);

    $raw = $response->getContent();
    expect($raw)->not->toContain('secret reason')
        ->and($raw)->not->toContain('pi_SECRETREF123')
        ->and($raw)->not->toContain('actor_id')
        ->and($raw)->not->toContain('provider_ref')
        ->and($raw)->not->toContain('"id"')
        ->and($raw)->not->toContain('"reason"');
});

it('shows payment null and an empty history when there are none', function () {
    $user = User::factory()->create();
    $order = placedOrder($user);

    $this->actingAs($user)->getJson("/api/v1/orders/{$order->number}")
        ->assertOk()
        ->assertJsonPath('data.payment', null)
        ->assertJsonPath('data.status_history', []);
});

it('keeps the snapshot after the product is renamed, repriced and the variant deleted', function () {
    $user = User::factory()->create();
    $order = placedOrder($user);
    $line = lineOn($order, ['sku' => 'SKU-9', 'product_name' => 'Original', 'variant_name' => 'Original V', 'unit_price' => 1999, 'quantity' => 1, 'line_total' => 1999]);
    ProductImage::factory()->create(['product_id' => $line->variant->product_id]);

    $line->variant->product->update(['name' => 'Renamed']);
    $line->variant->update(['name' => 'Renamed V', 'price' => 5]);
    $line->variant->delete();

    expect(OrderItem::find($line->id)->variant_id)->toBeNull();

    $this->actingAs($user)->getJson("/api/v1/orders/{$order->number}")
        ->assertOk()
        ->assertJsonPath('data.items.0.sku', 'SKU-9')
        ->assertJsonPath('data.items.0.product_name', 'Original')
        ->assertJsonPath('data.items.0.variant_name', 'Original V')
        ->assertJsonPath('data.items.0.unit_price', 1999)
        ->assertJsonPath('data.items.0.line_total', 1999)
        ->assertJsonPath('data.items.0.image', null);
});

it('still shows the image when the product was soft-deleted but the variant remains', function () {
    $user = User::factory()->create();
    $order = placedOrder($user);
    $line = lineOn($order);
    ProductImage::factory()->create(['product_id' => $line->variant->product_id, 'path' => 'p/x.jpg']);
    $line->variant->product->delete();

    $this->actingAs($user)->getJson("/api/v1/orders/{$order->number}")
        ->assertOk()
        ->assertJsonPath('data.items.0.image.alt_text', ProductImage::first()->alt_text);
});

it('returns the conventions 404 for another user\'s order, an unknown number and a numeric id', function (string $case) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = placedOrder($other);
    placedOrder($user);

    $segment = match ($case) {
        'other user' => $theirs->number,
        'numeric id' => (string) $theirs->id,
        default => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    };

    $this->actingAs($user)->getJson("/api/v1/orders/{$segment}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertJsonStructure(['message', 'code', 'errors']);
})->with(['other user', 'unknown number', 'numeric id']);

it('does not change the checkout response shape', function () {
    $user = User::factory()->create();
    $order = placedOrder($user);
    lineOn($order);

    $data = (new OrderResource($order->load('items')))->resolve();

    expect(array_keys($data))->toBe(['number', 'status', 'currency', 'subtotal', 'shipping_total', 'grand_total', 'items', 'shipping_address', 'placed_at']);

    $item = (new OrderItemResource($order->items->first()))->resolve();
    expect(array_keys($item))->toBe(['sku', 'product_name', 'variant_name', 'unit_price', 'quantity', 'line_total']);
});

it('runs the same number of queries for 1 and for 10 orders in the list', function () {
    $user = User::factory()->create();

    $countQueries = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($user)->getJson('/api/v1/orders?per_page=50')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    lineOn(placedOrder($user));
    $small = $countQueries();

    foreach (range(1, 9) as $i) {
        lineOn(placedOrder($user));
    }
    $large = $countQueries();

    expect($large)->toBe($small);
});

it('runs the same number of queries for an order with 1 and with 10 items', function () {
    $user = User::factory()->create();
    $order = placedOrder($user);
    OrderStatusHistory::factory()->create(['order_id' => $order->id]);
    Payment::factory()->create(['order_id' => $order->id]);

    $countQueries = function () use ($user, $order): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($user)->getJson("/api/v1/orders/{$order->number}")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $first = lineOn($order);
    ProductImage::factory()->count(2)->create(['product_id' => $first->variant->product_id]);
    $small = $countQueries();

    foreach (range(1, 9) as $i) {
        $line = lineOn($order);
        ProductImage::factory()->count(2)->create(['product_id' => $line->variant->product_id]);
    }
    $large = $countQueries();

    expect($large)->toBe($small);
});
