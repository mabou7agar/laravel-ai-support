<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\AIChatController;
use LaravelAIEngine\Http\Controllers\Api\RagChatApiController;
use LaravelAIEngine\Http\Controllers\Api\ModuleController;

/*
|--------------------------------------------------------------------------
| AI Engine API Routes
|--------------------------------------------------------------------------
|
| These are API routes for the AI Engine.
| They use 'api' middleware and return JSON responses.
|
*/

// RAG Chat API Routes (v1)
Route::prefix('api/v1/rag')
    ->middleware(['api'])
    ->name('ai-engine.rag.api.')
    ->group(function () {
        
        // Chat endpoints
        Route::post('/chat', [RagChatApiController::class, 'sendMessage'])
            ->name('chat.send');
        
        Route::get('/chat/history/{session_id}', [RagChatApiController::class, 'getHistory'])
            ->name('chat.history');
        
        Route::post('/chat/clear', [RagChatApiController::class, 'clearHistory'])
            ->name('chat.clear');
        
        // Action endpoints
        Route::post('/actions/execute', [RagChatApiController::class, 'executeAction'])
            ->name('actions.execute');
        
        // RAG endpoints
        Route::get('/collections', [RagChatApiController::class, 'getCollections'])
            ->name('collections');
        
        // Configuration endpoints
        Route::get('/engines', [RagChatApiController::class, 'getEngines'])
            ->name('engines');
        
        // System endpoints
        Route::get('/health', [RagChatApiController::class, 'health'])
            ->name('health');
        
        // Conversation management
        Route::get('/conversations', [RagChatApiController::class, 'getUserConversations'])
            ->name('conversations.list');
    });

// Module Discovery Routes (v1)
Route::prefix('api/v1/modules')
    ->middleware(['api'])
    ->name('ai-engine.modules.')
    ->group(function () {
        Route::get('/discover', [ModuleController::class, 'discover'])
            ->name('discover');
    });

// Legacy AI Demo Routes
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
            
            // Get user conversations
            Route::get('/conversations', [AIChatController::class, 'getUserConversations'])
                ->name('chat.conversations');
        });
    });
