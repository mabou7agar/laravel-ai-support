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
            
            // Enhanced Chat Demo (Main)
            Route::get('/chat', [AIChatController::class, 'index'])
                ->name('chat.index');
            
            // RAG Chat Demo (Alternative)
            Route::get('/chat/rag', [AIChatController::class, 'rag'])
                ->name('chat.rag');
    });
