<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockReason;
use App\Exceptions\IllegalOrderTransition;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place an order's status changes (D8): validates the move, records history and,
 * when cancelling, returns the stock (D4) - all in one transaction.
 */
class OrderStateMachine
{
    public function transition(Order $order, OrderStatus $to, ?User $actor = null, ?string $reason = null): Order
    {
        DB::transaction(function () use ($order, $to, $actor, $reason) {
            // Lock and re-read: the instance we were given may be stale (D8).
            $current = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if (! $from->canTransitionTo($to)) {
                throw new IllegalOrderTransition($from, $to);
            }

            $reason = $reason === null ? null : trim($reason);

            if ($to === OrderStatus::Cancelled && ($reason === null || $reason === '')) {
                throw ValidationException::withMessages(['reason' => 'A reason is required to cancel an order.']);
            }

            $current->status = $to;
            $current->save();

            OrderStatusHistory::query()->create([
                'order_id' => $current->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor?->id,
                'reason' => $reason === '' ? null : $reason,
            ]);

            if ($to === OrderStatus::Cancelled) {
                $this->restock($current, $actor);
            }
        });

        return $order->refresh();
    }

    /**
     * Atomic stock = stock + ? per line, in ascending variant order, with one movement row each.
     * Lines whose variant was deleted (variant_id null, D3) are skipped.
     */
    private function restock(Order $order, ?User $actor): void
    {
        $lines = $order->items()->whereNotNull('variant_id')->orderBy('variant_id')->get();

        foreach ($lines as $line) {
            DB::table('variants')->where('id', $line->variant_id)->increment('stock', $line->quantity);

            StockMovement::query()->create([
                'variant_id' => $line->variant_id,
                'delta' => $line->quantity,
                'reason' => StockReason::Cancellation,
                'order_id' => $order->id,
                'actor_id' => $actor?->id,
            ]);
        }
    }
}
