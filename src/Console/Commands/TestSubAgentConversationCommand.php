<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\SubAgents\ConversationalSubAgentHandler;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentConversationService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;

class TestSubAgentConversationCommand extends Command
{
    protected $signature = 'ai:test-sub-agent-chat
                            {--target=Plan, challenge, and finalize a safe application workflow : Conversation target}
                            {--agent=* : Explicit configured sub-agent IDs, repeatable}
                            {--rounds=3 : Number of conversation rounds}
                            {--session= : Session ID}
                            {--user= : User ID}
                            {--engine=openai : AI engine for live conversational handlers}
                            {--model=gpt-4o-mini : AI model for live conversational handlers}
                            {--live : Use real ConversationalSubAgentHandler instead of deterministic demo handlers}
                            {--json : Output JSON}';

    protected $description = 'Run a two-sub-agent long-life conversation test and persist the agent context';

    public function handle(ContextManager $contexts, ConversationContextCompactor $compactor): int
    {
        $target = trim((string) $this->option('target'));
        $sessionId = trim((string) ($this->option('session') ?: 'sub-agent-chat-' . uniqid()));
        $userId = $this->option('user');
        $participants = $this->participants();
        $live = (bool) $this->option('live');
        $agents = $this->agentsFor($participants, $live);
        $originalExtractOnCompaction = config('ai-agent.conversation_memory.extract_on_compaction');

        if (!$live) {
            Config::set('ai-agent.conversation_memory.extract_on_compaction', false);
        }

        try {
            $context = $contexts->getOrCreate($sessionId, $userId);
            $context->metadata['sub_agent_chat_test'] = [
                'live' => $live,
                'engine' => $this->option('engine'),
                'model' => $this->option('model'),
            ];

            $service = new SubAgentConversationService(
                new SubAgentRegistry($this->laravel, $agents),
                $compactor
            );

            $result = $service->run($target, $context, $participants, [
                'rounds' => (int) $this->option('rounds'),
                'engine' => $this->option('engine'),
                'model' => $this->option('model'),
                'sub_agent_mode' => 'conversational',
            ]);

            $contexts->save($context);
            $restored = $contexts->getOrCreate($sessionId, $userId);
        } finally {
            if (!$live) {
                Config::set('ai-agent.conversation_memory.extract_on_compaction', $originalExtractOnCompaction);
            }
        }

        $payload = [
            'success' => $result->success,
            'summary' => [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'target' => $target,
                'participants' => array_column($result->participants, 'agent_id'),
                'turn_count' => count($result->transcript),
                'rounds_completed' => $result->roundsCompleted,
                'stopped_reason' => $result->stoppedReason,
                'context_saved' => !empty($restored->metadata['last_sub_agent_conversation'] ?? null),
                'context_messages' => count($restored->conversationHistory),
                'live' => $live,
            ],
            'transcript' => $result->transcript,
        ];

        if (!$result->success) {
            $payload['error'] = $result->error;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->displayText($payload);
        }

        return $result->success ? self::SUCCESS : self::FAILURE;
    }

    private function participants(): array
    {
        $agents = array_values(array_filter(
            array_map(static fn (mixed $agent): string => trim((string) $agent), (array) $this->option('agent')),
            static fn (string $agent): bool => $agent !== ''
        ));

        if (count($agents) >= 2) {
            return array_slice($agents, 0, 2);
        }

        return ['planner', 'reviewer'];
    }

    private function agentsFor(array $participants, bool $live): array
    {
        if ($live) {
            return [
                $participants[0] => [
                    'name' => 'Planner sub-agent',
                    'description' => 'Proposes the next best step, constraints, and expected outcome.',
                    'handler' => ConversationalSubAgentHandler::class,
                ],
                $participants[1] => [
                    'name' => 'Reviewer sub-agent',
                    'description' => 'Challenges the prior message, checks risk, and improves the plan.',
                    'handler' => ConversationalSubAgentHandler::class,
                ],
            ];
        }

        return [
            $participants[0] => [
                'name' => 'Planner sub-agent',
                'handler' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::success(
                    $task->id,
                    $task->agentId,
                    'Plan round ' . $task->input['round'] . ': define the next step for "' . $task->input['target'] . '" after "' . $task->input['last_message'] . '".'
                ),
            ],
            $participants[1] => [
                'name' => 'Reviewer sub-agent',
                'handler' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::success(
                    $task->id,
                    $task->agentId,
                    'Review round ' . $task->input['round'] . ': verify and refine the previous step: "' . $task->input['last_message'] . '".'
                ),
            ],
        ];
    }

    private function displayText(array $payload): void
    {
        $this->info($payload['success'] ? 'Sub-agent conversation completed.' : 'Sub-agent conversation failed.');
        $this->line('Session: ' . $payload['summary']['session_id']);
        $this->line('Turns: ' . $payload['summary']['turn_count']);
        $this->line('Rounds: ' . $payload['summary']['rounds_completed']);

        foreach ($payload['transcript'] as $turn) {
            $this->line(sprintf(
                '[%d.%d] %s: %s',
                $turn['round'] ?? 0,
                $turn['turn'] ?? 0,
                $turn['agent_name'] ?? $turn['agent_id'] ?? 'sub-agent',
                $turn['message'] ?? ''
            ));
        }
    }
}
