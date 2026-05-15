<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\SubAgents;

use LaravelAIEngine\Contracts\SubAgentHandler;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;

class ConversationalSubAgentHandler implements SubAgentHandler
{
    public function __construct(
        protected AgentConversationService $conversationService
    ) {
    }

    public function handle(
        SubAgentTask $task,
        UnifiedActionContext $context,
        array $previousResults = [],
        array $options = []
    ): SubAgentResult {
        $prompt = $this->buildPrompt($task, $previousResults);
        $mode = (string) ($task->metadata['mode'] ?? $options['sub_agent_mode'] ?? 'conversational');

        $response = $mode === 'rag'
            ? $this->conversationService->executeSearchRAG(
                $prompt,
                $context,
                $options,
                fn () => \LaravelAIEngine\DTOs\AgentResponse::failure('Sub-agent reroute is not available.', context: $context)
            )
            : $this->conversationService->executeConversational($prompt, $context, $options);

        if ($response->needsUserInput) {
            return SubAgentResult::needsUserInput($task->id, $task->agentId, $response->message, $response->data, $response->metadata ?? []);
        }

        return $response->success
            ? SubAgentResult::success($task->id, $task->agentId, $response->message, $response->data, $response->metadata ?? [])
            : SubAgentResult::failure($task->id, $task->agentId, $response->message, $response->data, $response->metadata ?? []);
    }

    protected function buildPrompt(SubAgentTask $task, array $previousResults): string
    {
        $lines = [
            "Sub-agent: {$task->name}",
            "Objective: {$task->objective}",
        ];

        if ($task->input !== []) {
            $lines[] = 'Input: ' . json_encode($task->input, JSON_UNESCAPED_UNICODE);
        }

        if ($previousResults !== []) {
            $lines[] = 'Previous sub-agent results: ' . json_encode(
                array_map(static fn (SubAgentResult $result): array => $result->toArray(), $previousResults),
                JSON_UNESCAPED_UNICODE
            );
        }

        return implode("\n", $lines);
    }
}
