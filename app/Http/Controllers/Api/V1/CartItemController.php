<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;

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
}
