<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\Node\NodeApiController;
use LaravelAIEngine\Http\Middleware\NodeAuthMiddleware;
use LaravelAIEngine\Http\Middleware\NodeRateLimitMiddleware;
use LaravelAIEngine\Http\Middleware\SetRequestLocaleMiddleware;
use LaravelAIEngine\Http\Middleware\StandardizeApiResponseMiddleware;

Route::prefix('api/ai-engine')->middleware([SetRequestLocaleMiddleware::class, StandardizeApiResponseMiddleware::class])->group(function () {
    // Public contract endpoints
    Route::get('health', [NodeApiController::class, 'health']);
    Route::get('manifest', [NodeApiController::class, 'manifest']);

    // Protected federation endpoints
    Route::middleware([
        NodeAuthMiddleware::class,
        NodeRateLimitMiddleware::class . ':60,1'
    ])->group(function () {
        Route::post('search', [NodeApiController::class, 'search']);
        Route::post('chat', [NodeApiController::class, 'chat']);
        Route::post('tools/execute', [NodeApiController::class, 'executeTool']);
    });
});
