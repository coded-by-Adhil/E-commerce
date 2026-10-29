<?php

use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

// Served under /api/v1 (prefix set in bootstrap/app.php).
Route::get('/ping', PingController::class);
