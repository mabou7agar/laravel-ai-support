<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\SubAgents;

use LaravelAIEngine\DTOs\SubAgentConversationResult;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;

class SubAgentConversationService
{
    public function __construct(
        protected SubAgentRegistry $registry,
        protected ConversationContextCompactor $compactor
    ) {
    }

    /**
     * @param array<int, string|array<string, mixed>> $participants
     */
    public function run(
        string $target,
        UnifiedActionContext $context,
        array $participants,
        array $options = []
    ): SubAgentConversationResult {
        $target = trim($target);
        $participants = $this->normalizeParticipants($participants);

        if ($target === '') {
            return SubAgentConversationResult::failure($target, 'A target is required.');
        }

        if (count($participants) < 2) {
            return SubAgentConversationResult::failure($target, 'At least two sub-agents are required.', $participants);
        }

        $rounds = $this->rounds($options);
        $stopOnFailure = array_key_exists('stop_on_failure', $options)
            ? (bool) $options['stop_on_failure']
            : true;
        $conversationId = (string) ($options['conversation_id'] ?? 'sub-agent-' . str_replace('.', '', uniqid('', true)));
        $lastMessage = trim((string) ($options['seed_message'] ?? $target));
        $transcript = [];
        $results = [];
        $hasFailures = false;
        $turn = 0;
        $roundsCompleted = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($participants as $index => $participant) {
                $agentId = (string) $participant['agent_id'];
                $handler = $this->registry->resolveHandler($agentId);

                if (!$handler) {
                    return SubAgentConversationResult::failure(
                        $target,
                        "No handler registered for sub-agent '{$agentId}'.",
                        $participants,
                        $transcript,
                        $this->serializedResults($results),
                        'missing_handler',
                        $roundsCompleted,
                        ['conversation_id' => $conversationId]
                    );
                }

                $turn++;
                $task = $this->taskFor(
                    participant: $participant,
                    target: $target,
                    round: $round,
                    turn: $turn,
                    participantIndex: $index,
                    lastMessage: $lastMessage,
                    transcript: $transcript,
                    conversationId: $conversationId
                );

                $result = $handler->handle($task, $context, $results, $options);
                $results[$task->id] = $result;

                $row = $this->transcriptRow($result, $participant, $round, $turn);
                $transcript[] = $row;
                $lastMessage = $this->messageForNextTurn($result);
                $hasFailures = $hasFailures || !$result->success;

                $context->addAssistantMessage(
                    $this->contextMessage($row),
                    [
                        'agent_strategy' => 'sub_agent_conversation',
                        'sub_agent_id' => $agentId,
                        'sub_agent_conversation_id' => $conversationId,
                        'round' => $round,
                        'turn' => $turn,
                    ]
                );
                $this->storeContextMetadata($context, $conversationId, $target, $participants, $transcript, $round);
                $this->compactor->compact($context, $options);

                if ($result->needsUserInput) {
                    return new SubAgentConversationResult(
                        success: true,
                        target: $target,
                        participants: $participants,
                        transcript: $transcript,
                        results: $this->serializedResults($results),
                        stoppedReason: 'needs_user_input',
                        roundsCompleted: max(0, $round - 1),
                        metadata: ['conversation_id' => $conversationId]
                    );
                }

                if (!$result->success && $stopOnFailure) {
                    return SubAgentConversationResult::failure(
                        $target,
                        $result->error ?? $result->message ?? "Sub-agent '{$agentId}' failed.",
                        $participants,
                        $transcript,
                        $this->serializedResults($results),
                        'failed',
                        max(0, $round - 1),
                        ['conversation_id' => $conversationId]
                    );
                }
            }

            $roundsCompleted = $round;
        }

        return new SubAgentConversationResult(
            success: !$hasFailures,
            target: $target,
            participants: $participants,
            transcript: $transcript,
            results: $this->serializedResults($results),
            stoppedReason: $hasFailures ? 'completed_with_failures' : 'completed',
            roundsCompleted: $roundsCompleted,
            error: $hasFailures ? 'One or more sub-agents failed.' : null,
            metadata: ['conversation_id' => $conversationId]
        );
    }

    private function rounds(array $options): int
    {
        $requested = (int) ($options['rounds'] ?? config('ai-agent.sub_agent_conversations.default_rounds', 3));
        $max = max(1, (int) config('ai-agent.sub_agent_conversations.max_rounds', 8));

        return max(1, min($requested, $max));
    }

    private function normalizeParticipants(array $participants): array
    {
        return collect($participants)
            ->map(function (string|array $participant, int|string $key): array {
                if (is_string($participant)) {
                    $participant = ['agent_id' => $participant];
                }

                if (!isset($participant['agent_id'], $participant['agent'], $participant['id']) && is_string($key)) {
                    $participant['agent_id'] = $key;
                }

                $agentId = trim((string) ($participant['agent_id'] ?? $participant['agent'] ?? $participant['id'] ?? ''));
                $definition = $agentId !== '' ? ($this->registry->get($agentId) ?? []) : [];

                return array_filter([
                    'agent_id' => $agentId,
                    'name' => (string) ($participant['name'] ?? $definition['name'] ?? $agentId),
                    'objective' => trim((string) ($participant['objective'] ?? $participant['target'] ?? $definition['description'] ?? '')),
                    'input' => is_array($participant['input'] ?? null) ? $participant['input'] : [],
                    'metadata' => is_array($participant['metadata'] ?? null) ? $participant['metadata'] : [],
                ], static fn ($value): bool => $value !== '' && $value !== []);
            })
            ->filter(static fn (array $participant): bool => ($participant['agent_id'] ?? '') !== '')
            ->values()
            ->all();
    }

    private function taskFor(
        array $participant,
        string $target,
        int $round,
        int $turn,
        int $participantIndex,
        string $lastMessage,
        array $transcript,
        string $conversationId
    ): SubAgentTask {
        $agentId = (string) $participant['agent_id'];
        $name = (string) ($participant['name'] ?? $agentId);
        $objective = trim((string) ($participant['objective'] ?? ''));
        $objective = $objective !== ''
            ? $objective . "\nConversation target: {$target}"
            : "Conversation target: {$target}";

        return new SubAgentTask(
            id: "conversation_{$round}_{$turn}_{$agentId}",
            agentId: $agentId,
            name: $name,
            objective: $objective,
            input: array_merge((array) ($participant['input'] ?? []), [
                'target' => $target,
                'round' => $round,
                'turn' => $turn,
                'participant_index' => $participantIndex,
                'last_message' => $lastMessage,
                'transcript' => $transcript,
                'conversation_id' => $conversationId,
            ]),
            order: $turn,
            metadata: array_merge((array) ($participant['metadata'] ?? []), [
                'mode' => 'sub_agent_conversation',
                'conversation_id' => $conversationId,
            ])
        );
    }

    private function transcriptRow(SubAgentResult $result, array $participant, int $round, int $turn): array
    {
        return array_filter([
            'round' => $round,
            'turn' => $turn,
            'agent_id' => $result->agentId,
            'agent_name' => $participant['name'] ?? $result->agentId,
            'success' => $result->success,
            'needs_user_input' => $result->needsUserInput,
            'message' => $result->message ?? $result->error,
            'data' => $result->data,
            'metadata' => $result->metadata,
            'created_at' => now()->toIso8601String(),
        ], static fn ($value): bool => $value !== null && $value !== []);
    }

    private function messageForNextTurn(SubAgentResult $result): string
    {
        if (is_string($result->message) && trim($result->message) !== '') {
            return $result->message;
        }

        if (is_string($result->error) && trim($result->error) !== '') {
            return $result->error;
        }

        if (is_scalar($result->data)) {
            return (string) $result->data;
        }

        $encoded = json_encode($result->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    private function contextMessage(array $row): string
    {
        $agentName = (string) ($row['agent_name'] ?? $row['agent_id'] ?? 'sub-agent');
        $message = trim((string) ($row['message'] ?? ''));

        return "[{$agentName}] {$message}";
    }

    private function storeContextMetadata(
        UnifiedActionContext $context,
        string $conversationId,
        string $target,
        array $participants,
        array $transcript,
        int $round
    ): void {
        $context->metadata['last_sub_agent_conversation'] = [
            'conversation_id' => $conversationId,
            'target' => $target,
            'participants' => array_column($participants, 'agent_id'),
            'turn_count' => count($transcript),
            'last_round' => $round,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string, SubAgentResult> $results
     */
    private function serializedResults(array $results): array
    {
        return array_map(static fn (SubAgentResult $result): array => $result->toArray(), $results);
    }
}
