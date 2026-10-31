<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

// Served under /api/v1 (prefix set in bootstrap/app.php).
Route::get('/ping', PingController::class);
Route::get('/categories', [CategoryController::class, 'index']);

Route::middleware('stateful')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('addresses', AddressController::class);
    });
});
