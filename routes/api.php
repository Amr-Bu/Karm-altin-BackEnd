<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])->middleware('admin');
    Route::patch('/orders/{order}', [OrderController::class, 'update']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('admin');
    Route::post('/orders/{order}/pay', [OrderController::class, 'pay'])->middleware('admin');
    Route::post('/orders/{order}/print-receipt', [OrderController::class, 'printReceipt']);
});
