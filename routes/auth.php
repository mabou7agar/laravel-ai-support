<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| These routes handle authentication for the AI demo.
| They provide token generation and validation endpoints.
|
*/

Route::prefix('api/auth')->group(function () {
    // Generate token (public)
    Route::post('/token', [AuthController::class, 'generateToken'])
        ->name('ai-engine.auth.token');

    // Validate token (requires auth if available)
    $guards = config('auth.guards', []);
    $authMiddleware = isset($guards['sanctum']) ? 'auth:sanctum' 
        : (isset($guards['jwt']) ? 'auth:jwt' 
        : (isset($guards['api']) ? 'auth:api' : null));

    if ($authMiddleware) {
        Route::middleware($authMiddleware)->group(function () {
            Route::get('/validate', [AuthController::class, 'validateToken'])
                ->name('ai-engine.auth.validate');
            
            Route::post('/logout', [AuthController::class, 'logout'])
                ->name('ai-engine.auth.logout');
        });
    }
});
