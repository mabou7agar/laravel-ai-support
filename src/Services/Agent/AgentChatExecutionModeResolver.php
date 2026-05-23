<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentChatExecutionDecision;
use LaravelAIEngine\DTOs\SendMessageDTO;

class AgentChatExecutionModeResolver
{
    public function __construct(
        private readonly ?AgentSkillMatcher $skills = null
    ) {
    }

    public function resolve(SendMessageDTO $dto, bool $useRag, array $ragCollections = []): AgentChatExecutionDecision
    {
        $requested = $dto->executionMode;

        if ($requested === 'sync') {
            return new AgentChatExecutionDecision('sync', 'explicit_sync');
        }

        if (!config('ai-agent.chat.async_enabled', true)) {
            return new AgentChatExecutionDecision('sync', 'async_disabled');
        }

        if ($requested === 'async' || $dto->async) {
            return new AgentChatExecutionDecision('async', 'explicit_async');
        }

        if ($requested !== 'auto' && (bool) config('ai-agent.chat.async_default', false)) {
            return new AgentChatExecutionDecision('async', 'config_async_default');
        }

        if ($requested !== 'auto') {
            return new AgentChatExecutionDecision('sync', 'default_sync');
        }

        if ($dto->agentGoal || $dto->subAgents !== null || $dto->goalAgent !== null) {
            return new AgentChatExecutionDecision('async', 'goal_or_sub_agent');
        }

        if ($dto->streaming) {
            return new AgentChatExecutionDecision('async', 'streaming');
        }

        if ($this->collectionNeedsDurability($dto->collection)) {
            return new AgentChatExecutionDecision('async', 'structured_collection');
        }

        if ($dto->forceRag && (bool) config('ai-agent.chat.auto_async.force_rag', false)) {
            return new AgentChatExecutionDecision('async', 'force_rag');
        }

        if ($useRag && $ragCollections !== [] && (bool) config('ai-agent.chat.auto_async.rag_collections', false)) {
            return new AgentChatExecutionDecision('async', 'rag_collections');
        }

        if ($dto->actions && $this->matchesSkill($dto->message)) {
            return new AgentChatExecutionDecision('async', 'matched_skill');
        }

        return new AgentChatExecutionDecision('sync', 'simple_chat');
    }

    private function collectionNeedsDurability(?array $collection): bool
    {
        if ($collection === null || $collection === [] || !($collection['enabled'] ?? true)) {
            return false;
        }

        $callbackType = $collection['callback']['type'] ?? 'none';

        return (bool) ($collection['close_on_complete'] ?? false)
            || in_array($callbackType, ['url', 'event'], true);
    }

    private function matchesSkill(string $message): bool
    {
        try {
            return $this->matcher()->match($message) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function matcher(): AgentSkillMatcher
    {
        return $this->skills ?? app(AgentSkillMatcher::class);
    }
}
