<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CartItemController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

// Served under /api/v1 (prefix set in bootstrap/app.php).
Route::get('/ping', PingController::class);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

Route::middleware('stateful')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('addresses', AddressController::class);

        Route::post('/checkout', CheckoutController::class);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{number}', [OrderController::class, 'show']);

        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartItemController::class, 'store']);
        Route::patch('/cart/items/{item}', [CartItemController::class, 'update']);
        Route::delete('/cart/items/{item}', [CartItemController::class, 'destroy']);
    });
});
