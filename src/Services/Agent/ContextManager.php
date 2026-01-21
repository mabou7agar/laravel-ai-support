<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ContextManager
{
    public function getOrCreate(string $sessionId, $userId): UnifiedActionContext
    {
        $context = UnifiedActionContext::load($sessionId, $userId);
        
        if (!$context) {
            $context = new UnifiedActionContext(
                sessionId: $sessionId,
                userId: $userId
            );
            
            Log::channel('ai-engine')->info('New agent context created', [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);
        } else {
            Log::channel('ai-engine')->info('Agent context loaded from cache', [
                'session_id' => $sessionId,
                'current_strategy' => $context->currentStrategy,
                'current_workflow' => $context->currentWorkflow,
                'current_step' => $context->currentStep,
                'workflow_state_keys' => array_keys($context->workflowState),
            ]);
        }
        
        return $context;
    }

    public function save(UnifiedActionContext $context): void
    {
        $context->persist();
        
        Log::channel('ai-engine')->info('Agent context saved to cache', [
            'session_id' => $context->sessionId,
            'strategy' => $context->currentStrategy,
            'current_workflow' => $context->currentWorkflow,
            'current_step' => $context->currentStep,
        ]);
    }

    public function clear(string $sessionId): void
    {
        Cache::forget("agent_context:{$sessionId}");
        
        Log::channel('ai-engine')->info('Agent context cleared', [
            'session_id' => $sessionId,
        ]);
    }

    public function exists(string $sessionId): bool
    {
        return Cache::has("agent_context:{$sessionId}");
    }
    
    public function load(string $sessionId): ?UnifiedActionContext
    {
        return UnifiedActionContext::load($sessionId, null);
    }
}
