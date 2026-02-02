<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\Node\NodeApiController;
use LaravelAIEngine\Http\Controllers\Node\NodeDashboardController;
use LaravelAIEngine\Http\Middleware\NodeAuthMiddleware;
use LaravelAIEngine\Http\Middleware\NodeRateLimitMiddleware;

Route::prefix('api/ai-engine')->group(function () {
    // Public endpoints
    Route::get('health', [NodeApiController::class, 'health']);
    Route::get('collections', [NodeApiController::class, 'collections']);
    Route::get('autonomous-collectors', [NodeApiController::class, 'autonomousCollectors']);
    Route::post('register', [NodeApiController::class, 'register']);
    
    // Dashboard endpoints (public for monitoring)
    Route::get('dashboard', [NodeDashboardController::class, 'index']);
    Route::get('dashboard/node/{slug}', [NodeDashboardController::class, 'node']);
    Route::get('dashboard/metrics', [NodeDashboardController::class, 'metrics']);
    
    // Protected endpoints (require authentication)
    Route::middleware([
        NodeAuthMiddleware::class,
        NodeRateLimitMiddleware::class . ':60,1'
    ])->group(function () {
        Route::post('search', [NodeApiController::class, 'search']);
        Route::post('aggregate', [NodeApiController::class, 'aggregate']);
        Route::post('chat', [NodeApiController::class, 'chat']); // Forward entire chat/workflow
        Route::post('actions', [NodeApiController::class, 'executeAction']);
        Route::post('execute', [NodeApiController::class, 'execute']);
        Route::get('status', [NodeApiController::class, 'status']);
        Route::post('refresh-token', [NodeApiController::class, 'refreshToken']);
    });
});
