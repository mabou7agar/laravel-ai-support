<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\AIChatController;
use LaravelAIEngine\Http\Controllers\Api\RagChatApiController;
use LaravelAIEngine\Http\Controllers\Api\GenerateApiController;
use LaravelAIEngine\Http\Controllers\Api\EngineCatalogController;
use LaravelAIEngine\Http\Controllers\Api\ModuleController;
use LaravelAIEngine\Http\Controllers\Api\ActionExecutionController;
use LaravelAIEngine\Http\Controllers\Api\AgentRunController;
use LaravelAIEngine\Http\Controllers\Api\ModelRecommendationController;
use LaravelAIEngine\Http\Controllers\DataCollectorController;
use LaravelAIEngine\Http\Controllers\Api\ProviderToolController;
use LaravelAIEngine\Http\Controllers\Api\PricingController;
use LaravelAIEngine\Http\Controllers\AutonomousCollectorController;
use LaravelAIEngine\Http\Middleware\SetRequestLocaleMiddleware;
use LaravelAIEngine\Http\Middleware\StandardizeApiResponseMiddleware;

/*
|--------------------------------------------------------------------------
| AI Engine API Routes
|--------------------------------------------------------------------------
|
| These are API routes for the AI Engine.
| They use 'api' middleware and return JSON responses.
|
*/

$normalizeMiddleware = static function (array $middleware): array {
    $items = array_map(static function ($item): string {
        return is_string($item) ? trim($item) : '';
    }, $middleware);

    return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
};

$resolveApiMiddleware = static function (string $group) use ($normalizeMiddleware): array {
    $defaultStack = ['api', SetRequestLocaleMiddleware::class, StandardizeApiResponseMiddleware::class];

    $replace = config("ai-engine.api.middleware.replace.{$group}", []);
    $base = (is_array($replace) && $replace !== []) ? $replace : $defaultStack;

    $globalAppend = config('ai-engine.api.middleware.append', []);
    $groupAppend = config("ai-engine.api.middleware.groups.{$group}", []);

    $stack = array_merge(
        is_array($base) ? $base : [],
        is_array($globalAppend) ? $globalAppend : [],
        is_array($groupAppend) ? $groupAppend : []
    );

    return array_values(array_unique($normalizeMiddleware($stack)));
};

// Agent Chat API Routes (v1)
Route::prefix('api/v1/agent')
    ->middleware($resolveApiMiddleware('agent'))
    ->name('ai-engine.agent.api.')
    ->group(function () {
        Route::post('/chat', [RagChatApiController::class, 'sendMessage'])
            ->name('chat.send');
    });

// RAG API Routes (v1 retrieval endpoints)
Route::prefix('api/v1/rag')
    ->middleware($resolveApiMiddleware('rag'))
    ->name('ai-engine.rag.api.')
    ->group(function () {
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
    ->middleware($resolveApiMiddleware('actions'))
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
    ->middleware($resolveApiMiddleware('modules'))
    ->name('ai-engine.modules.')
    ->group(function () {
        Route::get('/discover', [ModuleController::class, 'discover'])
            ->name('discover');
    });

// Direct Generation API Routes (v1)
if (config('ai-engine.api.generate.enabled', true)) {
    Route::post(
        trim(config('ai-engine.api.generate.prefix', 'api/v1/ai/generate'), '/') . '/video/fal/webhook',
        [GenerateApiController::class, 'falVideoWebhook']
    )
        ->middleware(['api', StandardizeApiResponseMiddleware::class])
        ->name('ai-engine.generate.api.video.webhook');

    Route::post(
        trim(config('ai-engine.api.generate.prefix', 'api/v1/ai/generate'), '/') . '/preview/fal/webhook',
        [GenerateApiController::class, 'falReferencePackWebhook']
    )
        ->middleware(['api', StandardizeApiResponseMiddleware::class])
        ->name('ai-engine.generate.api.preview.webhook');

    Route::post(
        trim(config('ai-engine.api.generate.prefix', 'api/v1/ai/generate'), '/') . '/reference-pack/fal/webhook',
        [GenerateApiController::class, 'falReferencePackWebhook']
    )
        ->middleware(['api', StandardizeApiResponseMiddleware::class])
        ->name('ai-engine.generate.api.reference-pack.webhook');

    Route::prefix(config('ai-engine.api.generate.prefix', 'api/v1/ai/generate'))
        ->middleware($resolveApiMiddleware('generate'))
        ->name('ai-engine.generate.api.')
        ->group(function () {
            Route::post('/text', [GenerateApiController::class, 'text'])
                ->name('text');

            Route::post('/image', [GenerateApiController::class, 'image'])
                ->name('image');

            Route::post('/preview', [GenerateApiController::class, 'preview'])
                ->name('preview');

            Route::get('/preview/jobs/{jobId}', [GenerateApiController::class, 'referencePackJobStatus'])
                ->name('preview.jobs.status');

            Route::post('/reference-pack', [GenerateApiController::class, 'referencePack'])
                ->name('reference-pack');

            Route::get('/reference-pack/jobs/{jobId}', [GenerateApiController::class, 'referencePackJobStatus'])
                ->name('reference-pack.jobs.status');

            Route::post('/video', [GenerateApiController::class, 'video'])
                ->name('video');

            Route::get('/video/jobs/{jobId}', [GenerateApiController::class, 'videoJobStatus'])
                ->name('video.jobs.status');

            Route::post('/transcribe', [GenerateApiController::class, 'transcribe'])
                ->name('transcribe');

            Route::post('/tts', [GenerateApiController::class, 'tts'])
                ->name('tts');
        });
}

Route::prefix('api/v1/ai')
    ->middleware($resolveApiMiddleware('generate'))
    ->name('ai-engine.catalog.api.')
    ->group(function () {
        Route::post('/pricing/preview', [PricingController::class, 'preview'])
            ->name('pricing.preview');

        Route::get('/models', [EngineCatalogController::class, 'models'])
            ->name('models');

        Route::get('/engines', [EngineCatalogController::class, 'engines'])
            ->name('engines');

        Route::get('/engines-with-models', [EngineCatalogController::class, 'enginesWithModels'])
            ->name('engines-with-models');
    });

Route::prefix('api/v1/ai/provider-tools')
    ->middleware($resolveApiMiddleware('generate'))
    ->name('ai-engine.provider-tools.api.')
    ->group(function () {
        Route::get('/runs', [ProviderToolController::class, 'runs'])
            ->name('runs.index');
        Route::get('/runs/{run}', [ProviderToolController::class, 'showRun'])
            ->name('runs.show');
        Route::post('/runs/{run}/continue', [ProviderToolController::class, 'continueRun'])
            ->name('runs.continue');
        Route::get('/approvals', [ProviderToolController::class, 'approvals'])
            ->name('approvals.index');
        Route::post('/approvals/{approvalKey}/approve', [ProviderToolController::class, 'approve'])
            ->name('approvals.approve');
        Route::post('/approvals/{approvalKey}/reject', [ProviderToolController::class, 'reject'])
            ->name('approvals.reject');
        Route::get('/artifacts', [ProviderToolController::class, 'artifacts'])
            ->name('artifacts.index');
        Route::get('/artifacts/{artifact}/download', [ProviderToolController::class, 'downloadArtifact'])
            ->name('artifacts.download');
        Route::post('/fal/catalog/execute', [ProviderToolController::class, 'executeFalCatalog'])
            ->name('fal.catalog.execute');
        Route::post('/fal/catalog/webhook', [ProviderToolController::class, 'falCatalogWebhook'])
            ->name('fal.catalog.webhook');
    });

Route::prefix('api/v1/ai/agent-runs')
    ->middleware($resolveApiMiddleware('generate'))
    ->name('ai-engine.agent-runs.api.')
    ->group(function () {
        Route::get('/', [AgentRunController::class, 'index'])
            ->name('runs.index');
        Route::get('/capabilities', [AgentRunController::class, 'capabilities'])
            ->name('capabilities');
        Route::get('/{run}', [AgentRunController::class, 'show'])
            ->name('runs.show');
        Route::get('/{run}/trace', [AgentRunController::class, 'trace'])
            ->name('runs.trace');
        Route::post('/{run}/resume', [AgentRunController::class, 'resume'])
            ->name('runs.resume');
        Route::post('/{run}/cancel', [AgentRunController::class, 'cancel'])
            ->name('runs.cancel');
    });

// Data Collector Chat Routes (v1)
Route::prefix('api/v1/data-collector')
    ->middleware($resolveApiMiddleware('data_collector'))
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

// Autonomous Collector Routes (v1)
Route::prefix('api/v1/autonomous-collector')
    ->middleware($resolveApiMiddleware('autonomous_collector'))
    ->name('ai-engine.autonomous-collector.')
    ->group(function () {

        Route::post('/start', [AutonomousCollectorController::class, 'start'])
            ->name('start');

        Route::post('/message', [AutonomousCollectorController::class, 'message'])
            ->name('message');

        Route::get('/status/{sessionId}', [AutonomousCollectorController::class, 'status'])
            ->name('status');

        Route::post('/confirm', [AutonomousCollectorController::class, 'confirm'])
            ->name('confirm');

        Route::post('/cancel', [AutonomousCollectorController::class, 'cancel'])
            ->name('cancel');

        Route::get('/data/{sessionId}', [AutonomousCollectorController::class, 'data'])
            ->name('data');
    });

if (filter_var(config('ai-engine.enable_demo_routes', false), FILTER_VALIDATE_BOOLEAN)) {
    Route::prefix('ai-demo')
        ->middleware($resolveApiMiddleware('demo'))
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
}
