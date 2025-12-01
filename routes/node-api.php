<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\Node\NodeApiController;
use LaravelAIEngine\Http\Middleware\NodeAuthMiddleware;
use LaravelAIEngine\Http\Middleware\NodeRateLimitMiddleware;

Route::prefix('api/ai-engine')->group(function () {
    // Public endpoints
    Route::get('health', [NodeApiController::class, 'health']);
    Route::post('register', [NodeApiController::class, 'register']);
    
    // Protected endpoints (require authentication)
    Route::middleware([
        NodeAuthMiddleware::class,
        NodeRateLimitMiddleware::class . ':60,1'
    ])->group(function () {
        Route::post('search', [NodeApiController::class, 'search']);
        Route::post('actions', [NodeApiController::class, 'executeAction']);
        Route::get('status', [NodeApiController::class, 'status']);
        Route::post('refresh-token', [NodeApiController::class, 'refreshToken']);
    });
});
