<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PlaceOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __invoke(CheckoutRequest $request, PlaceOrder $placeOrder): JsonResponse
    {
        $order = $placeOrder->handle(
            $request->user(),
            $request->idempotencyKey(),
            $request->shippingAddress(),
            $request->billingAddress(),
        );

        // 201 for a new order, 200 when the same Idempotency-Key replays an existing one.
        return (new OrderResource($order))->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }
}
