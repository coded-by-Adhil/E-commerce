<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderIndexRequest;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use Illuminate\Http\Request;

/**
 * Customers only ever reach orders through $request->user()->orders(), so another
 * user's order is simply "not found" (404), never "forbidden".
 */
class OrderController extends Controller
{
    public function index(OrderIndexRequest $request): OrderCollection
    {
        $orders = $request->user()->orders()
            ->withSum('items as item_count', 'quantity')
            ->when($request->status(), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return new OrderCollection($orders);
    }

    public function show(Request $request, string $number): OrderResource
    {
        $order = $request->user()->orders()
            ->where('number', $number)
            ->with([
                'items' => fn ($query) => $query->orderBy('id'),
                'items.variant.product' => fn ($query) => $query->withTrashed(),
                'items.variant.product.images',
                'statusHistory' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'payments' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            ])
            ->firstOrFail();

        return new OrderResource($order);
    }
}
