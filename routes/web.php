<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\AIChatController;

/*
|--------------------------------------------------------------------------
| AI Engine Web Routes
|--------------------------------------------------------------------------
|
| These routes are conditionally loaded based on environment configuration.
| They provide demo/testing interfaces for the AI Engine features.
| 
| Loading is controlled in AIEngineServiceProvider::boot()
|
*/

// AI Chat Demo Routes (Views Only)
Route::prefix(config('ai-engine.demo_route_prefix', 'ai-demo'))
    ->middleware(config('ai-engine.demo_route_middleware', ['web']))
    ->name('ai-engine.')
    ->group(function () {
            
            // Enhanced Chat Demo
            Route::get('/chat', [AIChatController::class, 'index'])
                ->name('chat.index');
            
            // Basic Chat Demo
            Route::get('/chat/basic', [AIChatController::class, 'basic'])
                ->name('chat.basic');
            
            // RAG Chat Demo
            Route::get('/chat/rag', [AIChatController::class, 'rag'])
                ->name('chat.rag');
            
            // Voice Chat Demo
            Route::get('/chat/voice', [AIChatController::class, 'voice'])
                ->name('chat.voice');
            
            // Multi-Modal Chat Demo
            Route::get('/chat/multimodal', [AIChatController::class, 'multimodal'])
                ->name('chat.multimodal');
            
            // Vector Search Demo
            Route::get('/vector-search', [AIChatController::class, 'vectorSearch'])
                ->name('vector-search');
    });
