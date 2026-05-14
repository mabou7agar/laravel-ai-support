<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class RunSkillTool extends AgentTool
{
    private ?ToolRegistry $tools;
    private ?AgentExecutionPolicyService $policy;

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly ConversationMemory $memory,
        private readonly AIEngineService $ai,
        mixed $policy = null,
        mixed $tools = null
    ) {
        $this->policy = $policy instanceof AgentExecutionPolicyService ? $policy : null;
        $this->tools = $tools instanceof ToolRegistry
            ? $tools
            : ($policy instanceof ToolRegistry ? $policy : null);
    }

    public function getName(): string
    {
        return 'run_skill';
    }

    public function getDescription(): string
    {
        return 'Run a matched skill using the tools declared by that skill. The AI chooses the next tool or asks for missing data.';
    }

    public function getParameters(): array
    {
        return [
            'skill_id' => ['type' => 'string', 'required' => true],
            'message' => ['type' => 'string', 'required' => false],
            'reset' => ['type' => 'boolean', 'required' => false],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $skillId = trim((string) ($parameters['skill_id'] ?? ''));
        $skill = $this->skill($skillId);
        if (!$skill instanceof AgentSkillDefinition) {
            return ActionResult::failure('Skill was not found.', ['skill_id' => $skillId]);
        }

        $message = trim((string) ($parameters['message'] ?? $context->metadata['latest_user_message'] ?? ''));
        $state = !empty($parameters['reset']) ? [] : $this->state($context, $skill->id);
        $state = $this->normalizeState($state, $skill);
        $trace = [];

        if (is_array($state['pending_tool'] ?? null)) {
            return $this->handlePendingTool($skill, $state, $message, $context);
        }

        $state['payload'] = $this->mergePayload(
            $state['payload'],
            $this->extractPayloadPatch($skill, $state, $message, $context)
        );

        for ($step = 0; $step < $this->maxSteps(); $step++) {
            $plan = $this->plan($skill, $state, $message, $context);
            if ($plan === null) {
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, 'I need a little more information to continue.', $trace);
            }
            if (($plan['_planner_error'] ?? null) === 'invalid_schema') {
                $trace[] = $this->plannerTraceEntry($skill, $step, $plan);
                $this->putState($context, $skill->id, $state);
                $result = $this->needsInput($skill, $state, $this->planMessage($plan), $trace);
                $result->metadata['skill_planner_error'] = 'invalid_schema';

                return $result;
            }
            $trace[] = $this->plannerTraceEntry($skill, $step, $plan);

            $state['payload'] = $this->mergePayload($state['payload'], (array) ($plan['payload_patch'] ?? []));

            $action = strtolower(trim((string) ($plan['action'] ?? 'ask_user')));
            if ($action === 'ask_user') {
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, $this->planMessage($plan), $trace);
            }

            if ($action === 'final_response') {
                if ($this->shouldUseFinalTool($skill, $state, $message)) {
                    $finalToolResult = $this->handleFinalToolPlan($skill, $state, $plan, $context);
                    if ($finalToolResult instanceof ActionResult) {
                        return $this->withSkillMetadata($finalToolResult, $trace);
                    }
                }

                if ($this->configuredFinalTool($skill) !== null) {
                    $this->putState($context, $skill->id, $state);

                    return $this->needsInput($skill, $state, $this->planMessage($plan), $trace);
                }

                $this->forgetState($context, $skill->id);

                return $this->withSkillMetadata(ActionResult::success($this->planMessage($plan), $this->flowData($skill, $state, 'completed'), [
                    'agent_strategy' => 'skill_tool',
                ]), $trace);
            }

            if ($action !== 'run_tool') {
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, $this->planMessage($plan), $trace);
            }

            $toolName = trim((string) ($plan['tool_name'] ?? ''));
            $tool = $this->toolForSkill($skill, $toolName);
            if (!$tool instanceof AgentTool) {
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, 'I cannot use that tool for this skill.', $trace);
            }

            $toolParams = (array) ($plan['tool_params'] ?? []);
            if ($toolName === $this->configuredFinalTool($skill) && !$this->shouldUseFinalTool($skill, $state, $message)) {
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, $this->planMessage($plan));
            }

            if ($tool->requiresConfirmation()) {
                $validation = $tool->validate($this->withConfirmationForValidation($tool, $toolParams));
                if ($validation !== []) {
                    $this->putState($context, $skill->id, $state);

                    return $this->needsInput($skill, $state, implode("\n", $validation), $trace);
                }

                if ($this->looksLikeExplicitApproval($message)) {
                    $result = $this->executeTool($tool, $this->withConfirmationForValidation($tool, $toolParams), $context);
                    $state['tool_results'][] = [
                        'tool' => $toolName,
                        'params' => $this->withConfirmationForValidation($tool, $toolParams),
                        'result' => $result->toArray(),
                    ];
                    $state['payload'] = $this->mergePayload($state['payload'], $this->resultPayloadPatch($toolName, $result));

                    if ($result->success && $toolName === $this->finalTool($skill)) {
                        $this->forgetState($context, $skill->id);

                        return $this->withSkillMetadata(ActionResult::success($result->message ?? 'Done.', $this->flowData($skill, $state, 'completed') + [
                            'tool_result' => $result->toArray(),
                        ], ['agent_strategy' => 'skill_tool']), $trace);
                    }

                    $this->putState($context, $skill->id, $state);

                    if (($result->metadata['policy_blocked'] ?? false) === true) {
                        return $this->withSkillMetadata($result, $trace, ['agent_strategy' => 'skill_tool']);
                    }

                    return $result->success
                        ? $this->withSkillMetadata(ActionResult::success($result->message ?? 'Done.', $this->flowData($skill, $state, 'collecting'), ['agent_strategy' => 'skill_tool']), $trace)
                        : $this->needsInput($skill, $state, $result->message ?? $result->error ?? 'More information is required.', $trace);
                }

                $state['pending_tool'] = [
                    'name' => $toolName,
                    'params' => $toolParams,
                    'message' => $this->planMessage($plan),
                ];
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, $this->planMessage($plan), $trace);
            }

            $result = $this->executeTool($tool, $toolParams, $context);
            $state['tool_results'][] = [
                'tool' => $toolName,
                'params' => $toolParams,
                'result' => $result->toArray(),
            ];
            $state['payload'] = $this->mergePayload($state['payload'], $this->resultPayloadPatch($toolName, $result));

            if ($result->requiresUserInput()) {
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, $result->message ?? $result->error ?? 'More information is required.', $trace);
            }

            if (($result->metadata['policy_blocked'] ?? false) === true) {
                $this->putState($context, $skill->id, $state);

                return $this->withSkillMetadata($result, $trace, ['agent_strategy' => 'skill_tool']);
            }

            if (!$result->success) {
                continue;
            }
        }

        $this->putState($context, $skill->id, $state);

        return $this->withSkillMetadata(ActionResult::success('Skill draft updated.', $this->flowData($skill, $state, 'collecting'), [
            'agent_strategy' => 'skill_tool',
        ]), $trace);
    }

    private function handlePendingTool(AgentSkillDefinition $skill, array $state, string $message, UnifiedActionContext $context): ActionResult
    {
        $pending = (array) ($state['pending_tool'] ?? []);
        $toolName = (string) ($pending['name'] ?? '');
        $tool = $this->toolForSkill($skill, $toolName);
        if (!$tool instanceof AgentTool) {
            unset($state['pending_tool']);
            $this->putState($context, $skill->id, $state);

            return $this->needsInput($skill, $state, 'The pending tool is no longer available.');
        }

        if (!$this->looksLikeApproval($message) || $this->approvalTargetsConfiguredFinalTool($skill, $toolName, $message)) {
            unset($state['pending_tool']);
            $this->putState($context, $skill->id, $state);

            return $this->execute([
                'skill_id' => $skill->id,
                'message' => $message,
                'reset' => false,
            ], $context);
        }

        $params = $this->withConfirmationForValidation($tool, (array) ($pending['params'] ?? []));
        $result = $this->executeTool($tool, $params, $context);
        unset($state['pending_tool']);
        $state['tool_results'][] = [
            'tool' => $toolName,
            'params' => $params,
            'result' => $result->toArray(),
        ];
        $state['payload'] = $this->mergePayload($state['payload'], $this->resultPayloadPatch($toolName, $result));

        if ($result->success && $toolName === $this->finalTool($skill)) {
            $this->forgetState($context, $skill->id);

            return ActionResult::success($result->message ?? 'Done.', $this->flowData($skill, $state, 'completed') + [
                'tool_result' => $result->toArray(),
            ], ['agent_strategy' => 'skill_tool']);
        }

        $this->putState($context, $skill->id, $state);

        return $result->success
            ? ActionResult::success($result->message ?? 'Done.', $this->flowData($skill, $state, 'collecting'), ['agent_strategy' => 'skill_tool'])
            : $this->needsInput($skill, $state, $result->message ?? $result->error ?? 'More information is required.');
    }

    private function handleFinalToolPlan(AgentSkillDefinition $skill, array &$state, array $plan, UnifiedActionContext $context): ?ActionResult
    {
        $finalToolName = $this->configuredFinalTool($skill);
        if ($finalToolName === null) {
            return null;
        }

        $tool = $this->toolForSkill($skill, $finalToolName);
        if (!$tool instanceof AgentTool) {
            return null;
        }

        $toolParams = (array) ($plan['tool_params'] ?? []);
        if ($toolParams === []) {
            $toolParams = $this->payloadParamsForTool($tool, $state['payload'] ?? []);
        }

        if ($tool->requiresConfirmation()) {
            $validation = $tool->validate($this->withConfirmationForValidation($tool, $toolParams));
            if ($validation !== []) {
                $this->putState($context, $skill->id, $state);

                return $this->needsInput($skill, $state, implode("\n", $validation));
            }

            $state['pending_tool'] = [
                'name' => $finalToolName,
                'params' => $toolParams,
                'message' => $this->planMessage($plan),
            ];
            $this->putState($context, $skill->id, $state);

            return $this->needsInput($skill, $state, $this->planMessage($plan));
        }

        $result = $this->executeTool($tool, $toolParams, $context);
        $state['tool_results'][] = [
            'tool' => $finalToolName,
            'params' => $toolParams,
            'result' => $result->toArray(),
        ];
        $state['payload'] = $this->mergePayload($state['payload'], $this->resultPayloadPatch($finalToolName, $result));

        if ($result->success) {
            $this->forgetState($context, $skill->id);

            return ActionResult::success($result->message ?? $this->planMessage($plan), $this->flowData($skill, $state, 'completed') + [
                'tool_result' => $result->toArray(),
            ], ['agent_strategy' => 'skill_tool']);
        }

        $this->putState($context, $skill->id, $state);

        return $this->needsInput($skill, $state, $result->message ?? $result->error ?? 'More information is required.');
    }

    private function shouldUseFinalTool(AgentSkillDefinition $skill, array $state, string $message): bool
    {
        $finalTool = $this->configuredFinalTool($skill);
        if ($finalTool === null) {
            return false;
        }

        $entity = $this->entityNameFromToolName($finalTool);
        $normalized = mb_strtolower(trim($message));

        return $this->looksLikeApproval($message)
            && ($entity === '' || str_contains($normalized, str_replace('_', ' ', $entity)) || preg_match('/\b(final|finalize|submit|finish|complete)\b/u', $normalized) === 1);
    }

    private function approvalTargetsConfiguredFinalTool(AgentSkillDefinition $skill, string $pendingToolName, string $message): bool
    {
        $finalTool = $this->configuredFinalTool($skill);
        if ($finalTool === null || $pendingToolName === $finalTool) {
            return false;
        }

        $entity = $this->entityNameFromToolName($finalTool);
        if ($entity === '') {
            return false;
        }

        return str_contains(mb_strtolower(trim($message)), str_replace('_', ' ', $entity));
    }

    private function executeTool(AgentTool $tool, array $params, UnifiedActionContext $context): ActionResult
    {
        if (!$this->policy()->canUseTool($tool->getName(), [
            'session_id' => $context->sessionId,
            'user_id' => $context->userId,
            'metadata' => $context->metadata,
        ])) {
            return ActionResult::failure(
                $this->policy()->blockedMessage('tool', $tool->getName()),
                metadata: [
                    'policy_blocked' => true,
                    'blocked_tool' => $tool->getName(),
                ]
            );
        }

        $errors = $tool->validate($params);
        if ($errors !== []) {
            return ActionResult::needsUserInput(implode("\n", $errors), [
                'missing_fields' => $errors,
            ]);
        }

        return $tool->execute($params, $context);
    }

    private function plan(AgentSkillDefinition $skill, array $state, string $message, UnifiedActionContext $context): ?array
    {
        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $this->prompt($skill, $state, $message, $context),
                engine: config('ai-agent.skill_tool_planner.engine', config('ai-engine.default', 'openai')),
                model: config('ai-agent.skill_tool_planner.model', config('ai-engine.orchestration_model', config('ai-engine.default_model', 'gpt-4o-mini'))),
                maxTokens: (int) config('ai-agent.skill_tool_planner.max_tokens', 1200),
                temperature: (float) config('ai-agent.skill_tool_planner.temperature', 0.1),
                metadata: ['context' => 'skill_tool_planner', 'skill_id' => $skill->id]
            ));
        } catch (Throwable) {
            return null;
        }

        if (!$response->isSuccessful()) {
            return null;
        }

        $decoded = $this->decodeJson($response->getContent());
        if ($decoded !== null && !$this->validPlannerSchema($decoded)) {
            return [
                '_planner_error' => 'invalid_schema',
                'action' => 'ask_user',
                'message' => 'I need a clearer plan before I can continue.',
                'payload_patch' => [],
                'tool_name' => null,
                'tool_params' => [],
            ];
        }

        return $decoded;
    }

    private function extractPayloadPatch(AgentSkillDefinition $skill, array $state, string $message, UnifiedActionContext $context): array
    {
        if (!(bool) config('ai-agent.skill_tool_planner.extract_before_plan', true)) {
            return [];
        }

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $this->extractPrompt($skill, $state, $message, $context),
                engine: config('ai-agent.skill_tool_planner.engine', config('ai-engine.default', 'openai')),
                model: config('ai-agent.skill_tool_planner.model', config('ai-engine.orchestration_model', config('ai-engine.default_model', 'gpt-4o-mini'))),
                maxTokens: (int) config('ai-agent.skill_tool_planner.max_tokens', 1200),
                temperature: 0.0,
                metadata: ['context' => 'skill_target_json_extractor', 'skill_id' => $skill->id]
            ));
        } catch (Throwable) {
            return [];
        }

        if (!$response->isSuccessful()) {
            return [];
        }

        return $this->decodeJson($response->getContent()) ?? [];
    }

    private function extractPrompt(AgentSkillDefinition $skill, array $state, string $message, UnifiedActionContext $context): string
    {
        return implode("\n", [
            'SKILL_TARGET_JSON_EXTRACTOR',
            'Extract a payload patch for this skill target JSON from the latest message, recent conversation, current payload, and tool results.',
            'Return JSON object only. No markdown. Omit unknown fields. Do not invent IDs, prices, emails, names, or quantities.',
            'Keep previously known current payload values unless the user clearly edits, removes, or replaces them.',
            'For array fields, return the complete intended array after applying the user request when enough information is present.',
            '',
            'Skill JSON:',
            json_encode([
                'id' => $skill->id,
                'name' => $skill->name,
                'description' => $skill->description,
                'target_json' => $skill->metadata['target_json'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Current skill state JSON:',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Recent conversation JSON:',
            json_encode(array_slice($context->conversationHistory, -10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Latest user message:',
            $message,
        ]);
    }

    private function prompt(AgentSkillDefinition $skill, array $state, string $message, UnifiedActionContext $context): string
    {
        return implode("\n", [
            'SKILL_TOOL_PLANNER',
            'You control one skill by choosing the next declared tool or asking the user for missing information.',
            'Use only the tools listed for the skill. Do not invent tools. Do not execute write tools without confirmation; choose the write tool and include a concise confirmation message.',
            'Do not ask permission before running read-only lookup/search tools. Choose run_tool for those tools immediately when they can resolve missing target JSON fields.',
            'Before asking the user for missing information, extract all usable values already present in the latest message, recent conversation, current payload, and prior tool results into payload_patch.',
            'Do not ask the user to repeat details that already appear in recent conversation or current skill state.',
            'Return JSON only. No markdown.',
            'JSON shape: {"action":"ask_user|run_tool|final_response","message":"user-facing message","payload_patch":{},"tool_name":"tool or null","tool_params":{}}',
            'When a lookup tool finds a record, patch the target JSON with IDs and fields from tool_results.',
            'When a lookup tool does not find a record and a create tool exists, collect missing fields, then choose the create tool.',
            'When a tool result returns suggested_tool or suggested_tools, prefer the suggested declared tool before retrying the same failing tool.',
            'When the latest user message confirms a non-final record creation after you asked for missing fields, choose that create/upsert tool with the collected parameters.',
            'Do not choose the final_tool while required relation IDs or required records are unresolved and a lookup/create tool can resolve them.',
            'When target JSON is complete, choose the final create/submit tool.',
            '',
            'Skill JSON:',
            json_encode($this->skillDocument($skill), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Current skill state JSON:',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Recent conversation JSON:',
            json_encode(array_slice($context->conversationHistory, -8), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Latest user message:',
            $message,
        ]);
    }

    private function skillDocument(AgentSkillDefinition $skill): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => $skill->description,
            'target_json' => $skill->metadata['target_json'] ?? [],
            'final_tool' => $this->finalTool($skill),
            'tools' => collect($skill->tools)
                ->map(fn (string $name): ?array => $this->tools()->get($name)?->toArray())
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function normalizeState(array $state, AgentSkillDefinition $skill): array
    {
        return [
            'payload' => is_array($state['payload'] ?? null) ? $state['payload'] : [],
            'target_json' => $skill->metadata['target_json'] ?? [],
            'tool_results' => is_array($state['tool_results'] ?? null) ? array_values($state['tool_results']) : [],
            'pending_tool' => is_array($state['pending_tool'] ?? null) ? $state['pending_tool'] : null,
        ];
    }

    private function resultPayloadPatch(string $toolName, ActionResult $result): array
    {
        if (!$result->success || !is_array($result->data)) {
            return [];
        }

        $data = $result->data;
        $entity = $this->entityNameFromToolName($toolName);
        if ($entity !== '') {
            $patch = [];
            foreach (['id', 'name', 'email'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                    $patch["{$entity}_{$field}"] = $data[$field];
                }
            }

            return $patch;
        }

        return [];
    }

    private function entityNameFromToolName(string $toolName): string
    {
        $normalized = mb_strtolower(trim($toolName));
        $normalized = preg_replace('/^(find|lookup|search|create|upsert|update|get|fetch)[_-]+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[_-]+(tool|record|entity)$/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9_]+/u', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    private function flowData(AgentSkillDefinition $skill, array $state, string $status): array
    {
        return [
            'skill_id' => $skill->id,
            'skill_name' => $skill->name,
            'status' => $status,
            'payload' => $state['payload'] ?? [],
            'target_json' => $state['target_json'] ?? [],
            'pending_tool' => $state['pending_tool'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $trace
     */
    private function needsInput(AgentSkillDefinition $skill, array $state, string $message, array $trace = []): ActionResult
    {
        return $this->withSkillMetadata(ActionResult::needsUserInput($message, $this->flowData($skill, $state, 'collecting'), [
            'agent_strategy' => 'skill_tool',
        ]), $trace);
    }

    /**
     * @param array<int, array<string, mixed>> $trace
     * @param array<string, mixed> $extra
     */
    private function withSkillMetadata(ActionResult $result, array $trace, array $extra = []): ActionResult
    {
        foreach ($extra as $key => $value) {
            $result->metadata[$key] = $value;
        }

        if (!array_key_exists('agent_strategy', $result->metadata)) {
            $result->metadata['agent_strategy'] = 'skill_tool';
        }

        if ($trace !== []) {
            $result->metadata['skill_planner_trace'] = $trace;
        }

        return $result;
    }

    private function plannerTraceEntry(AgentSkillDefinition $skill, int $step, array $plan): array
    {
        return [
            'skill_id' => $skill->id,
            'step' => $step + 1,
            'action' => strtolower(trim((string) ($plan['action'] ?? 'ask_user'))),
            'tool_name' => trim((string) ($plan['tool_name'] ?? '')),
            'has_payload_patch' => !empty($plan['payload_patch']) && is_array($plan['payload_patch']),
            'message_preview' => mb_substr(trim((string) ($plan['message'] ?? '')), 0, 160),
        ];
    }

    private function skill(string $skillId): ?AgentSkillDefinition
    {
        return collect($this->skills->skills())
            ->first(fn (AgentSkillDefinition $skill): bool => $skill->id === $skillId);
    }

    private function toolForSkill(AgentSkillDefinition $skill, string $toolName): ?AgentTool
    {
        if ($toolName === '' || !in_array($toolName, $skill->tools, true)) {
            return null;
        }

        return $this->tools()->get($toolName);
    }

    private function finalTool(AgentSkillDefinition $skill): ?string
    {
        $final = $this->configuredFinalTool($skill);

        return $final ?? ($skill->tools[array_key_last($skill->tools)] ?? null);
    }

    private function configuredFinalTool(AgentSkillDefinition $skill): ?string
    {
        $final = $skill->metadata['final_tool'] ?? null;

        return is_string($final) && trim($final) !== '' ? trim($final) : null;
    }

    private function payloadParamsForTool(AgentTool $tool, array $payload): array
    {
        $params = [];
        foreach (array_keys($tool->getParameters()) as $name) {
            if ($name === 'confirmed' || str_contains($name, '.')) {
                continue;
            }

            if (array_key_exists($name, $payload)) {
                $params[$name] = $payload[$name];
            }
        }

        return $params;
    }

    private function withConfirmationForValidation(AgentTool $tool, array $params): array
    {
        if (array_key_exists('confirmed', $tool->getParameters())) {
            $params['confirmed'] = true;
        }

        return $params;
    }

    private function mergePayload(array $current, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && is_array($current[$key] ?? null) && !array_is_list($value)) {
                $current[$key] = $this->mergePayload($current[$key], $value);
                continue;
            }

            $current[$key] = $value;
        }

        return array_filter($current, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function state(UnifiedActionContext $context, string $skillId): array
    {
        $state = $this->memory->get('skill-tool:state', $this->stateKey($context, $skillId), []);

        return is_array($state) ? $state : [];
    }

    private function putState(UnifiedActionContext $context, string $skillId, array $state): void
    {
        $this->memory->put('skill-tool:state', $this->stateKey($context, $skillId), $state, now()->addHours(2));
    }

    private function forgetState(UnifiedActionContext $context, string $skillId): void
    {
        $this->memory->forget('skill-tool:state', $this->stateKey($context, $skillId));
    }

    private function stateKey(UnifiedActionContext $context, string $skillId): string
    {
        return ((string) ($context->userId ?: 'guest')) . ':' . $context->sessionId . ':' . $skillId;
    }

    private function maxSteps(): int
    {
        return max(1, (int) config('ai-agent.skill_tool_planner.max_steps', 4));
    }

    private function planMessage(array $plan): string
    {
        $message = trim((string) ($plan['message'] ?? ''));

        return $message !== '' ? $message : 'Please provide the missing information so I can continue.';
    }

    private function validPlannerSchema(array $plan): bool
    {
        if (!(bool) config('ai-agent.skill_tool_planner.strict_schema', true)) {
            return true;
        }

        $action = $plan['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['ask_user', 'run_tool', 'final_response'], true)) {
            return false;
        }

        if (array_key_exists('message', $plan) && !is_string($plan['message'])) {
            return false;
        }

        if (array_key_exists('payload_patch', $plan) && !is_array($plan['payload_patch'])) {
            return false;
        }

        if ($action === 'run_tool') {
            if (!is_string($plan['tool_name'] ?? null) || trim((string) $plan['tool_name']) === '') {
                return false;
            }

            if (array_key_exists('tool_params', $plan) && !is_array($plan['tool_params'])) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeApproval(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '' || preg_match('/\b(no|not|don\'t|do not|cancel|stop|instead)\b/u', $normalized) === 1) {
            return false;
        }

        return preg_match('/\b(yes|approve|approved|confirm|create|go ahead|proceed|ok|okay|sure)\b/u', $normalized) === 1;
    }

    private function looksLikeExplicitApproval(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '' || preg_match('/\b(no|not|don\'t|do not|cancel|stop|instead)\b/u', $normalized) === 1) {
            return false;
        }

        return preg_match('/\b(yes|approve|approved|confirm|confirmed|go ahead|proceed|ok|okay|sure)\b/u', $normalized) === 1;
    }

    private function decodeJson(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function tools(): ToolRegistry
    {
        return $this->tools ??= app(ToolRegistry::class);
    }

    private function policy(): AgentExecutionPolicyService
    {
        return $this->policy ??= app(AgentExecutionPolicyService::class);
    }
}
