<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\AIChatController;

/*
|--------------------------------------------------------------------------
| AI Engine API Routes
|--------------------------------------------------------------------------
|
| These are API routes for the AI Engine.
| They use 'api' middleware and return JSON responses.
|
*/

Route::prefix('ai-demo')
    ->middleware(['api'])
    ->name('ai-engine.api.')
    ->group(function () {
        
        // Chat API Routes
        Route::prefix('chat')->group(function () {
            
            // Send message
            Route::post('/send', [AIChatController::class, 'sendMessage'])
                ->name('chat.send');
            
            // Get chat history
            Route::get('/history/{sessionId}', [AIChatController::class, 'getHistory'])
                ->name('chat.history');
            
            // Clear chat history
            Route::post('/clear', [AIChatController::class, 'clearHistory'])
                ->name('chat.clear');
            
            // Upload file
            Route::post('/upload', [AIChatController::class, 'uploadFile'])
                ->name('chat.upload');
            
            // Execute action
            Route::post('/action', [AIChatController::class, 'executeAction'])
                ->name('chat.action');
            
            // Get available engines
            Route::get('/engines', [AIChatController::class, 'getEngines'])
                ->name('chat.engines');
            
            // Get available actions
            Route::get('/actions', [AIChatController::class, 'getAvailableActions'])
                ->name('chat.actions');
            
            // Execute dynamic action
            Route::post('/execute-action', [AIChatController::class, 'executeDynamicAction'])
                ->name('chat.execute-action');
            
            // Get memory stats
            Route::get('/memory-stats/{sessionId}', [AIChatController::class, 'getMemoryStats'])
                ->name('chat.memory-stats');
            
            // Get context summary
            Route::get('/context-summary/{sessionId}', [AIChatController::class, 'getContextSummary'])
                ->name('chat.context-summary');
        });
    });
