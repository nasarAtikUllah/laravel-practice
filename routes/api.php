<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Frontend\BrandController as FrontendBrandController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('frontend')->middleware('throttle:60,1')->group(function () {
    Route::get('/brands', [FrontendBrandController::class, 'index']);
    Route::get('/brands/{slug}', [FrontendBrandController::class, 'show']);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:5,1');
Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('brands', BrandController::class);
    Route::apiResource('brands.collections', CollectionController::class)->shallow();

    Route::apiResource('products', ProductController::class);
});
