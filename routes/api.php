<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\AIChatController;
use LaravelAIEngine\Http\Controllers\Api\RagChatApiController;
use LaravelAIEngine\Http\Controllers\Api\ModuleController;
use LaravelAIEngine\Http\Controllers\Api\ActionExecutionController;
use LaravelAIEngine\Http\Controllers\Api\ModelRecommendationController;
use LaravelAIEngine\Http\Controllers\DataCollectorController;

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
        
        // File analysis endpoint
        Route::post('/analyze-file', [RagChatApiController::class, 'analyzeFile'])
            ->name('analyze-file');
        
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

// Action Execution API Routes (v1)
Route::prefix('api/v1/actions')
    ->middleware(['api'])
    ->name('ai-engine.actions.api.')
    ->group(function () {
        
        // Execute any action (local or auto-detect remote)
        Route::post('/execute', [ActionExecutionController::class, 'execute'])
            ->name('execute');
        
        // Execute action on specific remote node
        Route::post('/execute-remote', [ActionExecutionController::class, 'executeRemote'])
            ->name('execute-remote');
        
        // Execute action on all nodes
        Route::post('/execute-all', [ActionExecutionController::class, 'executeOnAll'])
            ->name('execute-all');
        
        // Select a numbered option
        Route::post('/select-option', [ActionExecutionController::class, 'selectOption'])
            ->name('select-option');
        
        // Get available actions (local or include remote)
        Route::get('/available', [ActionExecutionController::class, 'available'])
            ->name('available');
    });

// Module Discovery Routes (v1)
Route::prefix('api/v1/modules')
    ->middleware(['api'])
    ->name('ai-engine.modules.')
    ->group(function () {
        Route::get('/discover', [ModuleController::class, 'discover'])
            ->name('discover');
    });

// Data Collector Chat Routes (v1)
Route::prefix('api/v1/data-collector')
    ->middleware(['api'])
    ->name('ai-engine.data-collector.')
    ->group(function () {
        
        // Start a new data collection session (with registered config)
        Route::post('/start', [DataCollectorController::class, 'start'])
            ->name('start');
        
        // Start a new data collection session (with inline config)
        Route::post('/start-custom', [DataCollectorController::class, 'startCustom'])
            ->name('start-custom');
        
        // Process a message in an active session
        Route::post('/message', [DataCollectorController::class, 'message'])
            ->name('message');
        
        // Get session status
        Route::get('/status/{sessionId}', [DataCollectorController::class, 'status'])
            ->name('status');
        
        // Cancel a session
        Route::post('/cancel', [DataCollectorController::class, 'cancel'])
            ->name('cancel');
        
        // Get collected data
        Route::get('/data/{sessionId}', [DataCollectorController::class, 'getData'])
            ->name('data');
        
        // Analyze uploaded file and extract data
        Route::post('/analyze-file', [DataCollectorController::class, 'analyzeFile'])
            ->name('analyze-file');
        
        // Apply extracted data to session
        Route::post('/apply-extracted', [DataCollectorController::class, 'applyExtracted'])
            ->name('apply-extracted');
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
        
        // Async Workflow Routes (SSE Support)
        Route::prefix('workflow')->group(function () {
            
            // Stream workflow status via SSE
            Route::get('/stream/{jobId}', [AIChatController::class, 'streamWorkflow'])
                ->name('workflow.stream');
            
            // Get workflow status (polling alternative)
            Route::get('/status/{jobId}', [AIChatController::class, 'getWorkflowStatus'])
                ->name('workflow.status');
        });
        
        // Model Recommendation Routes
        Route::prefix('models')->group(function () {
            
            // Get recommended model for task
            Route::get('/recommend', [ModelRecommendationController::class, 'recommend'])
                ->name('models.recommend');
            
            // Get all recommendations
            Route::get('/recommendations', [ModelRecommendationController::class, 'all'])
                ->name('models.recommendations');
            
            // Get cheapest model
            Route::get('/cheapest', [ModelRecommendationController::class, 'cheapest'])
                ->name('models.cheapest');
            
            // Check online/offline status
            Route::get('/status', [ModelRecommendationController::class, 'status'])
                ->name('models.status');
        });
    });
