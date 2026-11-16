<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\CouponQuoteController;
use App\Http\Controllers\Api\V1\CouponRedemptionController;
use App\Http\Controllers\Api\V1\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Frontend\BrandController as FrontendBrandController;
use App\Http\Controllers\Api\V1\Frontend\CollectionController as FrontendCollectionController;
use App\Http\Controllers\Api\V1\Frontend\ProductController as FrontendProductController;
use App\Http\Controllers\Api\V1\Frontend\ShippingOptionController as FrontendShippingOptionController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\ShippingOptionController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\VariantImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('frontend')->middleware('throttle:public-api')->group(function () {
    Route::get('/brands', [FrontendBrandController::class, 'index']);
    Route::get('/brands/{slug}', [FrontendBrandController::class, 'show']);

    Route::get('/brands/{brandId}/collections', [FrontendCollectionController::class, 'index'])->whereNumber('brandId');
    Route::get('/collections/{collectionId}', [FrontendCollectionController::class, 'show'])->whereNumber('collectionId');

    Route::get('/products', [FrontendProductController::class, 'index']);
    Route::get('/products/{slug}', [FrontendProductController::class, 'show']);

    // inside the "frontend" group:
    Route::get('/shipping-options', [FrontendShippingOptionController::class, 'index']);

});

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:5,1');
Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Catalog management: admin and staff only
    Route::middleware('can:manage-catalog')->group(function () {
        Route::apiResource('brands', BrandController::class);
        Route::apiResource('brands.collections', CollectionController::class)
            ->shallow()
            ->only(['index', 'store']);

        Route::apiResource('collections', CollectionController::class)
            ->only(['index', 'show', 'update', 'destroy']);

        Route::apiResource('products', ProductController::class);
        Route::apiResource('products.variants', ProductVariantController::class)->shallow();

        Route::post('/products/{product}/featured-image', [ProductImageController::class, 'store'])->middleware('throttle:uploads');
        Route::delete('/products/{product}/featured-image', [ProductImageController::class, 'destroy']);
        Route::post('/variants/{variant}/images', [VariantImageController::class, 'store'])->middleware('throttle:uploads');
        Route::delete('/variants/{variant}/images/{mediaId}', [VariantImageController::class, 'destroy'])->whereNumber('mediaId');
    });

    // Inventory: separate group, NOT inside the catalog group
    Route::middleware(['can:manage-inventory', 'throttle:inventory'])->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index']);

        Route::get('/variants/{variant}/stock-movements', [StockMovementController::class, 'index'])->withTrashed();

        Route::post('/variants/{variant}/stock/adjust', [StockController::class, 'adjust']);
        Route::post('/variants/{variant}/stock/count', [StockController::class, 'count']);
    });

    Route::post('/coupons/quote', CouponQuoteController::class)->middleware('throttle:coupon-quote');

    Route::middleware(['can:manage-coupons', 'throttle:coupon-admin'])->group(function () {
        Route::apiResource('coupons', CouponController::class);
        Route::get('/coupons/{coupon}/redemptions', [CouponRedemptionController::class, 'index'])->withTrashed();
    });

    Route::middleware(['can:manage-shipping', 'throttle:shipping-admin'])->group(function () {
        Route::apiResource('shipping-options', ShippingOptionController::class)
            ->parameters(['shipping-options' => 'shippingOption']);
    });

    Route::middleware('throttle:cart')->prefix('cart')->group(function () {
        Route::get('/', [CartController::class, 'show']);
        Route::delete('/', [CartController::class, 'clear']);
        Route::post('/items', [CartController::class, 'addItem']);
        Route::patch('/items/{variantId}', [CartController::class, 'updateItem'])->whereNumber('variantId');
        Route::delete('/items/{variantId}', [CartController::class, 'removeItem'])->whereNumber('variantId');
        Route::put('/coupon', [CartController::class, 'applyCoupon']);
        Route::delete('/coupon', [CartController::class, 'removeCoupon']);
        Route::post('/prepare', [CartController::class, 'prepare']);
        Route::put('/shipping', [CartController::class, 'selectShipping']);
    });
});
