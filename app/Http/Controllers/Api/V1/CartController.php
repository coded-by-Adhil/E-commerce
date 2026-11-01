<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->carts->forUser($request->user());

        // A cart created by this request would otherwise answer 201 (wasRecentlyCreated).
        return (new CartResource($this->carts->load($cart)))->response()->setStatusCode(200);
    }
}
