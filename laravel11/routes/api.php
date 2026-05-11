<?php

use App\Http\Controllers\API\ImageDiagnosticsController;
use App\Http\Controllers\API\LegacyActionController;
use App\Http\Controllers\API\MappingsController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\SettingsController;
use App\Http\Controllers\API\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::post('/sync', [SyncController::class, 'run'])->middleware('sync.token');
    Route::post('/sync/products', [SyncController::class, 'runProducts'])->middleware('sync.token');

    Route::get('/diagnostics/images', [ImageDiagnosticsController::class, 'summary']);
    Route::get('/diagnostics/images/gallery', [ImageDiagnosticsController::class, 'gallery']);
    Route::post('/diagnostics/images/normalize', [ImageDiagnosticsController::class, 'normalize'])->middleware('sync.token');

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products/refetch', [ProductController::class, 'refetchFromPrestaShop'])->middleware('sync.token');
    Route::get('/products/{productId}', [ProductController::class, 'show']);
    Route::patch('/products/{productId}/draft', [ProductController::class, 'updateDraft'])->middleware('sync.token');
    Route::post('/products/{productId}/preview-payload', [ProductController::class, 'previewPayload']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/refetch', [OrderController::class, 'refetchFromAboutYou'])->middleware('sync.token');
    Route::get('/orders/{orderId}', [OrderController::class, 'show']);
    Route::patch('/orders/{orderId}', [OrderController::class, 'update'])->middleware('sync.token');
    Route::post('/orders/{orderId}/repush', [OrderController::class, 'repush'])->middleware('sync.token');

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings', [SettingsController::class, 'save'])->middleware('sync.token');

    Route::get('/mappings/overview', [MappingsController::class, 'overview']);
    Route::get('/mappings/categories', [MappingsController::class, 'categories']);
    Route::post('/mappings/categories', [MappingsController::class, 'saveCategories'])->middleware('sync.token');
    Route::get('/mappings/ay-categories/search', [MappingsController::class, 'searchAyCategories']);
});

Route::match(['GET', 'POST'], '/legacy', [LegacyActionController::class, 'handle'])
    ->middleware('throttle:api');
