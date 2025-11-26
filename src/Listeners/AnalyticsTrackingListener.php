<?php

namespace LaravelAIEngine\Listeners;

use LaravelAIEngine\Events\AIResponseChunk;
use LaravelAIEngine\Events\AIResponseComplete;
use LaravelAIEngine\Events\AIActionTriggered;
use LaravelAIEngine\Events\AIStreamingError;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Events\AISessionEnded;
use LaravelAIEngine\Services\Analytics\AnalyticsManager;
use Illuminate\Support\Facades\Log;

/**
 * Analytics Tracking Listener
 * 
 * Tracks AI events for analytics and monitoring
 */
class AnalyticsTrackingListener
{
    public function __construct(
        protected AnalyticsManager $analyticsManager
    ) {}

    /**
     * Handle response chunk event
     */
    public function handleResponseChunk(AIResponseChunk $event): void
    {
        try {
            $this->analyticsManager->trackStreaming([
                'session_id' => $event->sessionId,
                'chunk_index' => $event->chunkIndex,
                'chunk_size' => strlen($event->chunk),
                'metadata' => $event->metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to track response chunk', [
                'session_id' => $event->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle response complete event
     */
    public function handleResponseComplete(AIResponseComplete $event): void
    {
        try {
            $this->analyticsManager->trackRequest([
                'request_id' => $event->requestId,
                'user_id' => $event->userId,
                'engine' => $event->engine,
                'model' => $event->model,
                'total_tokens' => $event->tokensUsed,
                'response_time' => $event->latency,
                'success' => $event->success,
                'metadata' => $event->metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to track response complete', [
                'request_id' => $event->requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle action triggered event
     */
    public function handleActionTriggered(AIActionTriggered $event): void
    {
        try {
            $this->analyticsManager->trackAction([
                'action_id' => $event->actionId,
                'action_type' => $event->actionType,
                'user_id' => $event->userId,
                'success' => $event->success,
                'metadata' => $event->metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to track action triggered', [
                'action_id' => $event->actionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle streaming error event
     */
    public function handleStreamingError(AIStreamingError $event): void
    {
        try {
            $this->analyticsManager->trackStreaming([
                'session_id' => $event->sessionId,
                'engine' => $event->engine,
                'error_message' => $event->errorMessage,
                'error_code' => $event->errorCode,
                'success' => false,
                'metadata' => $event->metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to track streaming error', [
                'session_id' => $event->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle session started event
     */
    public function handleSessionStarted(AISessionStarted $event): void
    {
        try {
            $this->analyticsManager->trackRequest([
                'request_id' => $event->sessionId,
                'user_id' => $event->userId,
                'engine' => $event->engine,
                'model' => $event->model,
                'status' => 'started',
                'metadata' => $event->metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to track session started', [
                'session_id' => $event->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle session ended event
     */
    public function handleSessionEnded(AISessionEnded $event): void
    {
        try {
            $this->analyticsManager->trackRequest([
                'request_id' => $event->sessionId,
                'user_id' => $event->userId,
                'total_tokens' => $event->totalTokens,
                'response_time' => $event->duration,
                'status' => 'completed',
                'metadata' => array_merge($event->metadata, [
                    'total_messages' => $event->totalMessages,
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to track session ended', [
                'session_id' => $event->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
