<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\Selectors\AllToolSelector;
use LaravelAIEngine\Services\Agent\Tools\Selectors\KeywordToolSelector;
use LaravelAIEngine\Services\Agent\Tools\Selectors\SemanticToolSelector;
use LaravelAIEngine\Services\Agent\Tools\Selectors\SkillScopedToolSelector;
use LaravelAIEngine\Services\Agent\Tools\Selectors\ToolSelectorContract;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AiNativePromptBuilder
{
    private ?ToolSelectorContract $resolvedSelector = null;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AgentSkillRegistry $skills,
        private readonly ?AgentContextSnapshotBuilder $snapshots = null,
        private readonly ?ToolSelectorContract $toolSelector = null
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function build(string $message, UnifiedActionContext $context, array $state, array $options = []): string
    {
        $lines = [
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
            $this->progressiveDisclosure()
                ? 'Available tools JSON (name + summary only; call find_tools to load a tool\'s full parameters before using it):'
                : 'Available tools JSON:',
            json_encode($this->toolDocuments($message, $state, $options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Recent conversation JSON:',
            json_encode($this->conversationDocuments($context->conversationHistory, $state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Context snapshot JSON:',
            json_encode($this->snapshotBuilder()->build($context, $state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Current runtime state JSON:',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Latest user message:',
            $message,
        ];

        if ($this->exposeReasoning($options)) {
            // Insert the reasoning instruction right before the "Return JSON only."
            // line so it sits alongside the JSON-shape guidance. Fall back to an
            // append if that anchor line is ever renamed.
            $anchor = array_search('Return JSON only. No markdown.', $lines, true);
            $instruction = 'Always include a "reasoning":"<one short sentence>" field in your JSON plan explaining your next step, in plain user-facing language.';
            if ($anchor === false) {
                $lines[] = $instruction;
            } else {
                array_splice($lines, $anchor, 0, [$instruction]);
            }
        }

        if ($this->planTimelineEnabled($options)) {
            // Insert the plan-steps instruction right before the "Return JSON only."
            // line, falling back to an append if that anchor is ever renamed.
            $anchor = array_search('Return JSON only. No markdown.', $lines, true);
            $instruction = 'Always include a "steps":["<short step>","<short step>"] array in your JSON plan listing the remaining steps you intend to take (use a single entry if only one step remains).';
            if ($anchor === false) {
                $lines[] = $instruction;
            } else {
                array_splice($lines, $anchor, 0, [$instruction]);
            }
        }

        if (!empty($options['force_rag'])) {
            // The caller demanded a knowledge-base-grounded answer (force_rag). AiNative
            // is model-driven, so we make retrieval mandatory via an imperative
            // instruction anchored next to the JSON-shape guidance; search_knowledge is
            // the tool that reaches the RAG store. Falls back to an append if the anchor
            // line is ever renamed.
            $anchor = array_search('Return JSON only. No markdown.', $lines, true);
            $instruction = 'This turn requires a knowledge-base-grounded answer: you MUST call the search_knowledge tool before returning a final answer, unless a search_knowledge result for this turn is already present in the runtime state.';
            if ($anchor === false) {
                $lines[] = $instruction;
            } else {
                array_splice($lines, $anchor, 0, [$instruction]);
            }
        }

        if ($this->respondInUserLanguage($options)) {
            // Most agent output is free text the model writes (the "message" field, questions,
            // summaries), so without an explicit instruction the reply language is left to chance.
            // Mirror the user's message language — this covers languages we ship no static
            // translations for — and fall back to the resolved app/request locale when ambiguous.
            $anchor = array_search('Return JSON only. No markdown.', $lines, true);
            $instruction = sprintf(
                'Write every user-facing string (the "message" field, "reasoning", questions, '
                . 'and summaries) in the same language as the latest user message. If the message '
                . 'language is ambiguous (e.g. only numbers or an ID), use %s. Do not translate tool '
                . 'names, JSON keys, IDs, emails, or code.',
                $this->responseLanguageName($context, $options)
            );
            if ($anchor === false) {
                $lines[] = $instruction;
            } else {
                array_splice($lines, $anchor, 0, [$instruction]);
            }
        }

        // A concrete worked example carries far more weight than prose for a
        // low-temperature planner: it mirrors the "Allowed JSON shapes" examples
        // (which omit these fields) so the model actually emits them every turn.
        $exampleFields = [];
        if ($this->exposeReasoning($options)) {
            $exampleFields[] = '"reasoning":"I need to look up the customer before creating the invoice"';
        }
        if ($this->planTimelineEnabled($options)) {
            $exampleFields[] = '"steps":["Find the customer","Find the products","Create the invoice"]';
        }
        if ($exampleFields !== []) {
            $example = 'Every JSON plan you return MUST also carry these fields. Example: '
                . '{"action":"tool_call","tool":"find_customer","arguments":{"query":"Acme"},"message":"Looking up Acme",'
                . implode(',', $exampleFields) . '}';
            $anchor = array_search('{"action":"final","message":"answer to user","data":{}}', $lines, true);
            if ($anchor === false) {
                $lines[] = $example;
            } else {
                array_splice($lines, $anchor + 1, 0, [$example]);
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function planTimelineEnabled(array $options = []): bool
    {
        if (array_key_exists('plan_timeline', $options) && $options['plan_timeline'] !== null) {
            return (bool) $options['plan_timeline'];
        }

        return (bool) config('ai-agent.ai_native.plan_timeline', false);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function exposeReasoning(array $options = []): bool
    {
        if (array_key_exists('expose_reasoning', $options) && $options['expose_reasoning'] !== null) {
            return (bool) $options['expose_reasoning'];
        }

        return (bool) config('ai-agent.ai_native.expose_reasoning', false);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function respondInUserLanguage(array $options = []): bool
    {
        if (array_key_exists('respond_in_user_language', $options) && $options['respond_in_user_language'] !== null) {
            return (bool) $options['respond_in_user_language'];
        }

        return (bool) config('ai-agent.ai_native.respond_in_user_language', true);
    }

    /**
     * Human-readable name of the locale to fall back to when the user's message language is
     * ambiguous. Honors an explicit options/context locale, else the resolved app/request locale.
     *
     * @param array<string, mixed> $options
     */
    private function responseLanguageName(UnifiedActionContext $context, array $options = []): string
    {
        $locale = $options['locale']
            ?? $options['language']
            ?? ($context->metadata['locale'] ?? null)
            ?? null;

        return app(LocaleResourceService::class)->languageName(is_string($locale) ? $locale : null);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function toolDocuments(string $message, array $state, array $options): array
    {
        $excluded = array_flip(array_map(
            static fn (mixed $tool): string => (string) $tool,
            (array) config('ai-agent.ai_native.excluded_tools', ['run_skill'])
        ));

        $tools = [];
        foreach ($this->tools->all() as $tool) {
            $name = $tool->getName();
            if (!isset($excluded[$name])) {
                $tools[$name] = $tool;
            }
        }

        // A selector trims the exposed set per turn (e.g. to the active skill's tools) so
        // a large registry does not bloat the prompt. Defaults to "all" (no trimming).
        $tools = $this->toolSelector()->select($tools, $message, $state, $options);

        // Progressive disclosure: list tools by name + summary only, and always expose
        // find_tools (full) so the planner can load a tool's full parameter schema on
        // demand. Keeps the base prompt small even with a large registry.
        if ($this->progressiveDisclosure()) {
            if ($this->tools->has('find_tools') && !isset($tools['find_tools'])) {
                $tools['find_tools'] = $this->tools->get('find_tools');
            }

            return array_values(array_map(
                static fn ($tool): array => $tool->getName() === 'find_tools'
                    ? $tool->toArray()
                    : ['name' => $tool->getName(), 'description' => $tool->getDescription()],
                $tools
            ));
        }

        return array_values(array_map(
            static fn ($tool): array => $tool->toArray(),
            $tools
        ));
    }

    private function progressiveDisclosure(): bool
    {
        return (string) config('ai-agent.ai_native.tool_selection.disclosure', 'full') === 'progressive';
    }

    private function toolSelector(): ToolSelectorContract
    {
        if ($this->toolSelector !== null) {
            return $this->toolSelector;
        }

        return $this->resolvedSelector ??= match ((string) config('ai-agent.ai_native.tool_selection.strategy', 'all')) {
            'skill_scoped' => app(SkillScopedToolSelector::class),
            'keyword' => app(KeywordToolSelector::class),
            'semantic' => app(SemanticToolSelector::class),
            default => app(AllToolSelector::class),
        };
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
