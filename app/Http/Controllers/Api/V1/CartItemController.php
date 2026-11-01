<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartItemController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function store(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->carts->add(
            $request->user(),
            (int) $request->validated('variant_id'),
            (int) $request->validated('quantity'),
        );

        return (new CartResource($this->carts->load($cart)))->response()->setStatusCode(200);
    }

    public function update(UpdateCartItemRequest $request, string $item): JsonResponse
    {
        $cart = $this->carts->setQuantity($request->user(), $item, (int) $request->validated('quantity'));

        return (new CartResource($this->carts->load($cart)))->response()->setStatusCode(200);
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        $cart = $this->carts->remove($request->user(), $item);

        return (new CartResource($this->carts->load($cart)))->response()->setStatusCode(200);
    }
}
