<?php

use App\Enums\OrderStatus;
use App\Enums\StockReason;
use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\IllegalOrderTransition;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * An order in $status with one line per quantity given, each on its own variant.
 *
 * @param  list<int>  $quantities
 * @return array{0: Order, 1: list<Variant>}
 */
function orderWithLines(OrderStatus $status = OrderStatus::Pending, array $quantities = [2], int $stock = 5): array
{
    $order = Order::factory()->create(['status' => $status]);
    $variants = [];

    foreach ($quantities as $quantity) {
        $variant = Variant::factory()->create(['stock' => $stock]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);
        $variants[] = $variant;
    }

    return [$order, $variants];
}

function stateMachine(): OrderStateMachine
{
    return app(OrderStateMachine::class);
}

$everyPair = [];
foreach (OrderStatus::cases() as $from) {
    foreach (OrderStatus::cases() as $to) {
        $everyPair["{$from->value} -> {$to->value}"] = [$from, $to];
    }
}

it('allows exactly the mapped transitions and rejects every other pair', function (OrderStatus $from, OrderStatus $to) {
    [$order] = orderWithLines($from);

    // Written out independently of the enum map, so a wrong map cannot pass.
    $expected = [
        'pending->paid', 'pending->cancelled',
        'paid->processing', 'paid->cancelled',
        'processing->shipped', 'processing->cancelled',
        'shipped->delivered',
    ];
    $allowed = in_array("{$from->value}->{$to->value}", $expected, true);

    if ($allowed) {
        $result = stateMachine()->transition($order, $to, reason: 'because');

        expect($result->status)->toBe($to)
            ->and($order->fresh()->status)->toBe($to)
            ->and(OrderStatusHistory::count())->toBe(1);
    } else {
        expect(fn () => stateMachine()->transition($order, $to, reason: 'because'))
            ->toThrow(IllegalOrderTransition::class);

        expect($order->fresh()->status)->toBe($from)
            ->and(OrderStatusHistory::count())->toBe(0);
    }

    expect($from->canTransitionTo($to))->toBe($allowed);
})->with($everyPair);

it('knows which statuses are final', function () {
    expect(OrderStatus::Delivered->isFinal())->toBeTrue()
        ->and(OrderStatus::Cancelled->isFinal())->toBeTrue()
        ->and(OrderStatus::Pending->isFinal())->toBeFalse()
        ->and(OrderStatus::Shipped->isFinal())->toBeFalse();
});

it('records a history row with from, to, actor and reason', function () {
    [$order] = orderWithLines(OrderStatus::Paid);
    $admin = User::factory()->admin()->create();

    stateMachine()->transition($order, OrderStatus::Cancelled, $admin, 'Customer asked');

    $history = OrderStatusHistory::sole();
    expect($history->order_id)->toBe($order->id)
        ->and($history->from_status)->toBe(OrderStatus::Paid)
        ->and($history->to_status)->toBe(OrderStatus::Cancelled)
        ->and($history->actor_id)->toBe($admin->id)
        ->and($history->reason)->toBe('Customer asked');
});

it('records a null actor and reason for a system transition', function () {
    [$order] = orderWithLines(OrderStatus::Pending);

    stateMachine()->transition($order, OrderStatus::Paid);

    $history = OrderStatusHistory::sole();
    expect($history->actor_id)->toBeNull()
        ->and($history->reason)->toBeNull()
        ->and($history->from_status)->toBe(OrderStatus::Pending)
        ->and($history->to_status)->toBe(OrderStatus::Paid);
});

it('restocks every line with a positive movement when cancelling', function (OrderStatus $from) {
    [$order, [$a, $b]] = orderWithLines($from, quantities: [2, 3], stock: 5);
    $admin = User::factory()->admin()->create();

    stateMachine()->transition($order, OrderStatus::Cancelled, $admin, 'Out of time');

    $movements = StockMovement::orderBy('variant_id')->get();
    expect($a->fresh()->stock)->toBe(7)
        ->and($b->fresh()->stock)->toBe(8)
        ->and($movements)->toHaveCount(2)
        ->and($movements->pluck('delta')->all())->toBe([2, 3])
        ->and($movements->every(fn ($m) => $m->reason === StockReason::Cancellation
            && $m->order_id === $order->id
            && $m->actor_id === $admin->id))->toBeTrue()
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
})->with([OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Processing]);

it('does not touch stock on a non-cancel transition', function () {
    [$order, [$variant]] = orderWithLines(OrderStatus::Pending, stock: 5);

    stateMachine()->transition($order, OrderStatus::Paid);

    expect($variant->fresh()->stock)->toBe(5)->and(StockMovement::count())->toBe(0);
});

it('requires a reason to cancel and changes nothing without one', function (?string $reason) {
    [$order, [$variant]] = orderWithLines(OrderStatus::Pending, stock: 5);

    expect(fn () => stateMachine()->transition($order, OrderStatus::Cancelled, reason: $reason))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(OrderStatusHistory::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($variant->fresh()->stock)->toBe(5);
})->with([null, '', '   ']);

it('reports the missing reason as a 422 on reason', function () {
    [$order] = orderWithLines();

    try {
        stateMachine()->transition($order, OrderStatus::Cancelled);
    } catch (ValidationException $e) {
        $response = (new ApiExceptionRenderer)->render($e, Request::create('/x'));

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true)['errors'])->toHaveKey('reason');

        return;
    }

    $this->fail('Expected a ValidationException.');
});

it('throws on a second cancel and restocks only once', function () {
    [$order, [$variant]] = orderWithLines(OrderStatus::Paid, quantities: [2], stock: 5);

    stateMachine()->transition($order, OrderStatus::Cancelled, reason: 'first');

    expect(fn () => stateMachine()->transition($order, OrderStatus::Cancelled, reason: 'second'))
        ->toThrow(IllegalOrderTransition::class);

    expect($variant->fresh()->stock)->toBe(7)
        ->and(StockMovement::count())->toBe(1)
        ->and(OrderStatusHistory::count())->toBe(1);
});

it('validates against the locked, fresh row and not a stale instance', function () {
    [$order, [$variant]] = orderWithLines(OrderStatus::Pending, stock: 5);
    $stale = Order::find($order->id);

    stateMachine()->transition($order, OrderStatus::Cancelled, reason: 'first');

    // $stale still says "pending", but the database says "cancelled".
    expect($stale->status)->toBe(OrderStatus::Pending)
        ->and(fn () => stateMachine()->transition($stale, OrderStatus::Paid))
        ->toThrow(IllegalOrderTransition::class);

    expect($variant->fresh()->stock)->toBe(7);
});

it('skips a line whose variant was deleted and restocks the others', function () {
    [$order, [$gone, $kept]] = orderWithLines(OrderStatus::Paid, quantities: [2, 3], stock: 5);
    $gone->delete(); // order_items.variant_id becomes null (D3)

    expect(OrderItem::whereNull('variant_id')->count())->toBe(1);

    stateMachine()->transition($order, OrderStatus::Cancelled, reason: 'gone');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($kept->fresh()->stock)->toBe(8)
        ->and(StockMovement::count())->toBe(1)
        ->and(StockMovement::sole()->variant_id)->toBe($kept->id);
});

it('rolls back status and history when restocking throws', function () {
    [$order, [$a, $b]] = orderWithLines(OrderStatus::Processing, quantities: [2, 3], stock: 5);

    // The first line is restocked, then recording the second movement fails.
    $calls = 0;
    StockMovement::creating(function () use (&$calls) {
        if (++$calls === 2) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => stateMachine()->transition($order, OrderStatus::Cancelled, reason: 'x'))
        ->toThrow(RuntimeException::class, 'boom');

    expect($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and(OrderStatusHistory::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($a->fresh()->stock)->toBe(5)
        ->and($b->fresh()->stock)->toBe(5);
});

it('leaves status, history and stock unchanged after an illegal transition', function () {
    [$order, [$variant]] = orderWithLines(OrderStatus::Shipped, stock: 5);

    expect(fn () => stateMachine()->transition($order, OrderStatus::Cancelled, reason: 'too late'))
        ->toThrow(IllegalOrderTransition::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and(OrderStatusHistory::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($variant->fresh()->stock)->toBe(5);
});

it('renders an illegal transition as 409 ILLEGAL_TRANSITION in the conventions shape', function () {
    $response = (new ApiExceptionRenderer)->render(
        new IllegalOrderTransition(OrderStatus::Delivered, OrderStatus::Paid),
        Request::create('/x'),
    );

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toMatchArray([
            'message' => 'An order cannot change from delivered to paid.',
            'code' => 'ILLEGAL_TRANSITION',
        ])
        ->and($response->getData(true))->toHaveKey('errors');
});
