<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MemoryOptimizationService
{
    /**
     * Get optimized conversation history with caching
     * Uses smart windowing based on token limits
     */
    public function getOptimizedHistory(
        string $conversationId,
        int $messageLimit = 20,
        int $maxTokens = 4000
    ): array {
        $cacheKey = "conversation_history:{$conversationId}";
        
        // Try cache first (5 minute TTL)
        return Cache::remember($cacheKey, 300, function () use ($conversationId, $messageLimit, $maxTokens) {
            $conversationService = app(ConversationService::class);
            $allMessages = $conversationService->loadConversationHistory($conversationId, 1000);
            
            // Apply smart windowing
            return $this->applySmartWindowing($allMessages, $messageLimit, $maxTokens);
        });
    }
    
    /**
     * Apply smart windowing to keep most relevant messages
     */
    protected function applySmartWindowing(
        array $messages,
        int $messageLimit,
        int $maxTokens
    ): array {
        if (empty($messages)) {
            return [];
        }
        
        // Strategy 1: Keep recent messages (most relevant)
        $recentMessages = array_slice($messages, -$messageLimit);
        
        // Strategy 2: Check token count
        $tokenCount = $this->estimateTokens($recentMessages);
        
        // If still too many tokens, reduce further
        while ($tokenCount > $maxTokens && count($recentMessages) > 5) {
            array_shift($recentMessages); // Remove oldest from recent set
            $tokenCount = $this->estimateTokens($recentMessages);
        }
        
        // Strategy 3: If conversation is very long, add summary of older messages
        $olderMessagesCount = count($messages) - count($recentMessages);
        if ($olderMessagesCount > 10) {
            $summary = $this->createSummary(array_slice($messages, 0, -count($recentMessages)));
            array_unshift($recentMessages, [
                'role' => 'system',
                'content' => "Earlier in conversation: {$summary}"
            ]);
        }
        
        return $recentMessages;
    }
    
    /**
     * Estimate token count (rough approximation)
     */
    protected function estimateTokens(array $messages): int
    {
        $totalChars = array_reduce($messages, function ($carry, $msg) {
            return $carry + strlen($msg['content'] ?? '');
        }, 0);
        
        // Rough estimate: 1 token ≈ 4 characters for English
        return (int) ($totalChars / 4);
    }
    
    /**
     * Invalidate conversation cache when new message is added
     */
    public function invalidateCache(string $conversationId): void
    {
        Cache::forget("conversation_history:{$conversationId}");
    }
    
    /**
     * Summarize old conversation for context compression
     */
    public function summarizeOldMessages(
        string $conversationId,
        int $keepRecentCount = 10
    ): array {
        $conversationService = app(ConversationService::class);
        $allMessages = $conversationService->loadConversationHistory($conversationId, 1000);
        
        if (count($allMessages) <= $keepRecentCount) {
            return $allMessages;
        }
        
        // Keep recent messages as-is
        $recentMessages = array_slice($allMessages, -$keepRecentCount);
        
        // Summarize older messages
        $oldMessages = array_slice($allMessages, 0, -$keepRecentCount);
        $summary = $this->createSummary($oldMessages);
        
        // Return summary + recent messages
        return array_merge([
            [
                'role' => 'system',
                'content' => "Previous conversation summary: {$summary}"
            ]
        ], $recentMessages);
    }
    
    /**
     * Create a summary of messages
     */
    protected function createSummary(array $messages): string
    {
        $userMessages = array_filter($messages, fn($m) => $m['role'] === 'user');
        $topics = array_map(fn($m) => substr($m['content'], 0, 100), $userMessages);
        
        return "User discussed: " . implode(', ', array_slice($topics, 0, 5));
    }
    
    /**
     * Get memory statistics
     */
    public function getMemoryStats(string $conversationId): array
    {
        $conversationService = app(ConversationService::class);
        $messages = $conversationService->loadConversationHistory($conversationId, 1000);
        
        $tokenEstimate = array_reduce($messages, function ($carry, $msg) {
            return $carry + (strlen($msg['content']) / 4); // Rough token estimate
        }, 0);
        
        return [
            'total_messages' => count($messages),
            'estimated_tokens' => (int) $tokenEstimate,
            'user_messages' => count(array_filter($messages, fn($m) => $m['role'] === 'user')),
            'assistant_messages' => count(array_filter($messages, fn($m) => $m['role'] === 'assistant')),
            'should_summarize' => $tokenEstimate > 3000, // Recommend summarization
        ];
    }
}
