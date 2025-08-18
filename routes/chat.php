<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\AiChatController;

/*
|--------------------------------------------------------------------------
| AI Chat Routes
|--------------------------------------------------------------------------
|
| These routes handle AI chat functionality including sending messages,
| executing actions, managing chat history, and WebSocket connections.
|
*/

Route::prefix('api/ai-chat')->middleware(['web'])->group(function () {
    // Send message to AI
    Route::post('/send', [AiChatController::class, 'sendMessage'])->name('ai-chat.send');
    
    // Execute interactive action
    Route::post('/action', [AiChatController::class, 'executeAction'])->name('ai-chat.action');
    
    // Get chat history
    Route::get('/history/{session_id}', [AiChatController::class, 'getHistory'])->name('ai-chat.history');
    
    // Clear chat history
    Route::delete('/history/{session_id}', [AiChatController::class, 'clearHistory'])->name('ai-chat.clear');
    
    // Get available engines and models
    Route::get('/engines', [AiChatController::class, 'getEngines'])->name('ai-chat.engines');
});

// Optional: Add authentication middleware for protected routes
Route::prefix('api/ai-chat')->middleware(['web', 'auth'])->group(function () {
    // Protected routes that require authentication
    // Route::post('/send', [AiChatController::class, 'sendMessage'])->name('ai-chat.send.auth');
});
