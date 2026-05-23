<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativePromptBuilder
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AgentSkillRegistry $skills,
        private readonly ?AgentContextSnapshotBuilder $snapshots = null
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function build(string $message, UnifiedActionContext $context, array $state, array $options = []): string
    {
        return implode("\n\n", [
            'AI_NATIVE_AGENT_RUNTIME',
            'You are controlling a Laravel application through declared tools and skills.',
            'Decide the next step yourself. Do not rely on package-coded business workflows.',
            'Use tools when application data or actions are needed. Ask the user when required fields, choices, or confirmations are missing.',
            'Use the context snapshot to continue active tasks, pending confirmations, resolved entities, and already-completed writes across turns.',
            'Treat the context snapshot and current runtime state as authoritative when they conflict with older conversation text.',
            'For multi-turn tasks, treat context_snapshot.current_payload as the working draft. Preserve values already present there unless the user changes or removes them.',
            'For follow-up questions that refer to prior or current work, inspect context_snapshot.recent_outcomes, context_snapshot.already_completed, and context_snapshot.current_payload before asking the user to identify the record again.',
            'If the latest user message only shares background facts, preferences, requirements, or domain information without asking you to perform an action, do not start a tool or skill flow. Reply conversationally and let suggestions expose possible next actions.',
            'Only begin a write/action flow when the user asks for an outcome, asks you to do/use/create/update/delete/send/generate/search/inspect something, approves a pending action, or clearly refers to a previous actionable task.',
            'When asking for more input or summarizing a draft, include the complete updated draft in data.current_payload so Laravel can carry it into the next turn.',
            'When the current payload is ready for a skill final tool, call that final tool with the complete current_payload instead of returning a final answer.',
            'For an active skill, prefer the tools listed in that skill. Use the skill relation lookup/create tools for related records before considering unrelated helper tools.',
            'When a skill has multiple relations, resolve root/scalar relations before child/list relations unless the user clearly asks for the child/list relation first.',
            'Never reuse a value extracted for one relation as another relation create payload. If relation ownership is ambiguous, ask or run the matching lookup instead of creating a different relation.',
            'Use generic field helper tools only when the user explicitly asks to validate, explain, or browse field options.',
            'Never ask the user whether you should run a non-confirming lookup/search/find tool. Run the lookup/search/find tool.',
            'When a skill relation has lookup_fields and the current payload contains one of those values, call that relation lookup_tool with the value.',
            'If a value can be resolved by an available lookup/search/find tool, call that tool before asking the user for the value.',
            'If the context snapshot says an equivalent write was already completed, answer from that state instead of creating it again.',
            'Do not ask the user in free text whether you should create, update, delete, send, generate, run, or execute something when a confirming tool is available. Call the confirming tool with the collected payload; Laravel will show the structured confirmation prompt.',
            'When a skill has a confirming final tool and the payload is ready, call that final tool. Laravel will pause for confirmation before executing it.',
            'Never invent database IDs, prices, file IDs, or foreign keys. Use IDs only when a previous tool result returned them.',
            'If the user asks to create, update, delete, send, generate, search, or inspect application data, do not return final until a relevant tool result supports the answer or you ask for missing input.',
            'Return JSON only. No markdown.',
            'Allowed JSON shapes:',
            '{"action":"tool_call","tool":"tool_name","arguments":{},"message":"short progress text"}',
            '{"action":"ask_user","message":"question to user","required_inputs":[],"data":{}}',
            '{"action":"final","message":"answer to user","data":{}}',
            'Available skills JSON:',
            json_encode($this->skillDocuments(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Available tools JSON:',
            json_encode($this->toolDocuments(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Recent conversation JSON:',
            json_encode($this->conversationDocuments($context->conversationHistory, $state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Context snapshot JSON:',
            json_encode($this->snapshotBuilder()->build($context, $state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Current runtime state JSON:',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Latest user message:',
            $message,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toolDocuments(): array
    {
        $excluded = array_flip(array_map(
            static fn (mixed $tool): string => (string) $tool,
            (array) config('ai-agent.ai_native.excluded_tools', ['run_skill'])
        ));

        $tools = array_filter(
            $this->tools->all(),
            static fn ($tool): bool => !isset($excluded[$tool->getName()])
        );

        return array_values(array_map(
            static fn ($tool): array => $tool->toArray(),
            $tools
        ));
    }

    private function snapshotBuilder(): AgentContextSnapshotBuilder
    {
        return $this->snapshots ?? new AgentContextSnapshotBuilder();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function skillDocuments(): array
    {
        return array_map(
            static fn ($skill): array => $skill->toArray(),
            $this->skills->skills()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    private function conversationDocuments(array $messages, array $state): array
    {
        if ($this->hasAuthoritativeRuntimeState($state)) {
            $messages = array_values(array_filter(
                $messages,
                static fn (array $message): bool => ($message['role'] ?? null) !== 'assistant'
            ));
        }

        return array_values(array_map(
            static function (array $message): array {
                $document = [
                    'role' => (string) ($message['role'] ?? ''),
                    'content' => (string) ($message['content'] ?? ''),
                ];

                if (isset($message['timestamp'])) {
                    $document['timestamp'] = (string) $message['timestamp'];
                }

                if (isset($message['name'])) {
                    $document['name'] = (string) $message['name'];
                }

                return $document;
            },
            array_slice($messages, -12)
        ));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasAuthoritativeRuntimeState(array $state): bool
    {
        return (array) data_get($state, 'task_frame.current_payload', []) !== []
            || (array) data_get($state, 'task_frame.completed_writes', []) !== []
            || (array) ($state['recent_outcomes'] ?? []) !== []
            || is_array($state['pending_tool'] ?? null);
    }
}
