<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeAskUserConfirmationHandler
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly AgentSkillRegistry $skills,
        private readonly AiNativeSkillPolicy $skillPolicy,
        private readonly AgentTaskStateService $taskState,
        private readonly AiNativeStateStore $stateStore,
        private readonly AiNativeConfirmationPreviewService $confirmationPreview,
        private readonly AiNativeResponseFactory $responses,
        private readonly AiNativeToolExecutor $toolExecutor
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function handle(UnifiedActionContext $context, array &$state, array $options, array $plan): ?AiNativeActionOutcome
    {
        $message = mb_strtolower($this->planMessage($plan));
        if (!$this->isWriteConfirmationRequest($message)) {
            return null;
        }

        $confirmation = $this->structuredConfirmation($context, $state, $plan, $options);
        if ($confirmation instanceof AiNativeActionOutcome) {
            return $confirmation;
        }

        if ($this->shouldAskModelToUseConfirmingTool($state, $options, $plan)) {
            $state['runtime_feedback'][] = [
                'reason' => 'write_confirmation_requires_tool_call',
                'message' => 'The plan asked the user to confirm an application write in free text. Call the matching confirming tool with the collected payload instead; Laravel will present the structured confirmation and preserve pending approval state.',
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function planMessage(array $plan): string
    {
        return trim((string) ($plan['message'] ?? ''));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     */
    private function structuredConfirmation(UnifiedActionContext $context, array &$state, array $plan, array $options): ?AiNativeActionOutcome
    {
        $payload = $this->payloadForWriteConfirmation($state, $plan);
        if ($payload === []) {
            return null;
        }

        $targetEntity = $this->entityFromWriteConfirmation($this->planMessage($plan), $state);
        $toolNames = $this->candidateToolNames($state);

        foreach ($toolNames as $toolName) {
            $tool = $this->tools->get($toolName);
            if (!$tool instanceof AgentTool || !$tool->requiresConfirmation()) {
                continue;
            }

            if ($targetEntity !== null && !$this->toolMatchesEntity((string) $toolName, $tool, $targetEntity)) {
                continue;
            }

            $arguments = $this->argumentsFromPayloadForTool($payload, $tool);
            if ($arguments === []) {
                continue;
            }

            if ($this->writeConfirmationNeedsLookup($toolName, $arguments, $state, $options)) {
                $this->stateStore->put($context, $state);

                return AiNativeActionOutcome::continueLoop();
            }

            $validation = $tool->validate($this->toolExecutor->withConfirmationForValidation($tool, $arguments));
            if ($validation !== []) {
                continue;
            }

            $preview = $this->confirmationPreview->preview($tool, $arguments, $context);
            $previewResult = $preview['result'];
            if ($previewResult instanceof ActionResult && (!$previewResult->success || $previewResult->requiresUserInput())) {
                continue;
            }

            $arguments = $preview['arguments'];
            $message = $this->planMessage($plan);
            $state['pending_tool'] = [
                'name' => $toolName,
                'params' => $arguments,
                'message' => $message !== '' ? $message : 'Please confirm before I continue.',
            ];
            $this->taskState->markPendingConfirmation($state, $toolName, $arguments);
            $this->stateStore->put($context, $state);

            $response = $this->responses->confirmation(
                $context,
                $state,
                $toolName,
                $arguments,
                $state['pending_tool']['message'],
                $preview['summary']
            );

            return AiNativeActionOutcome::response($response);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    private function shouldAskModelToUseConfirmingTool(array $state, array $options, array $plan): bool
    {
        if ($this->skillPolicy->hasRuntimeFeedback($state, 'write_confirmation_requires_tool_call')) {
            return false;
        }

        if ($this->hasNonConfirmationRequiredInputs((array) ($plan['required_inputs'] ?? []))) {
            return false;
        }

        return $this->skillPolicy->payloadFromPlan($plan, $state, $options) !== []
            || (array) data_get($state, 'task_frame.current_payload', []) !== []
            || (array) ($state['recent_outcomes'] ?? []) !== [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    private function writeConfirmationNeedsLookup(string $toolName, array $arguments, array &$state, array $options): bool
    {
        if ($this->skillPolicy->relationCreateNeedsLookupMiss($toolName, $arguments, $state, $options)) {
            $state['runtime_feedback'][] = [
                'reason' => 'relation_write_confirmation_without_lookup_miss',
                'message' => 'The plan asked for confirmation to create a related record before proving the matching lookup missed. Call the relation lookup tool for the same record first.',
                'write_tool' => $toolName,
            ];

            return true;
        }

        if ($this->skillPolicy->needsLookupBeforeWrite($toolName, $arguments, $state, $options)) {
            $state['runtime_feedback'][] = [
                'reason' => 'write_confirmation_without_lookup',
                'message' => 'The plan asked for confirmation to run a write before a matching lookup/search/find tool. Call the lookup tool for the same record first.',
                'write_tool' => $toolName,
                'lookup_tools' => $this->skillPolicy->matchingLookupToolsForWrite($toolName, $state, $options),
            ];

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    private function candidateToolNames(array $state): array
    {
        $activeObjective = trim((string) data_get($state, 'task_frame.active_objective', ''));
        $toolNames = [];

        foreach ($this->skills->skills() as $skill) {
            if ($activeObjective !== '' && $skill->id !== $activeObjective) {
                continue;
            }

            $toolNames = array_merge($toolNames, $skill->tools);
            if ($activeObjective !== '') {
                break;
            }
        }

        return array_values(array_unique(array_merge(
            $toolNames,
            array_map(static fn (AgentTool $tool): string => $tool->getName(), $this->tools->all())
        )));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function payloadForWriteConfirmation(array $state, array $plan): array
    {
        $payload = [];
        foreach ([
            data_get($state, 'task_frame.current_payload'),
            $plan['data']['current_payload'] ?? null,
            $plan['data']['payload'] ?? null,
            $plan['data'] ?? null,
        ] as $candidate) {
            if (is_array($candidate)) {
                $payload = array_replace_recursive($payload, $candidate);
            }
        }

        $latestOutcome = $this->latestNamedOutcome($state);
        $entity = trim((string) ($latestOutcome['entity_type'] ?? ''));
        $label = trim((string) ($latestOutcome['label'] ?? ''));
        if ($label !== '') {
            $payload = $this->withAliasedPayloadValue($payload, $label, $entity, $this->labelFields());
        }

        $email = $this->firstEmail($this->planMessage($plan));
        if ($email !== null) {
            $payload = $this->withAliasedPayloadValue($payload, $email, $entity, $this->emailFields());
        }

        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function withAliasedPayloadValue(array $payload, string $value, string $entity, array $fields): array
    {
        foreach ($fields as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }

            $payload[$field] ??= $value;
            if ($entity !== '' && !str_starts_with($field, $entity.'_')) {
                $payload[$entity.'_'.$field] ??= $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function labelFields(): array
    {
        return $this->configuredStringList('label_fields');
    }

    /**
     * @return array<int, string>
     */
    private function emailFields(): array
    {
        return $this->configuredStringList('email_fields');
    }

    /**
     * @return array<int, string>
     */
    private function configuredStringList(string $key): array
    {
        $values = (array) config('ai-agent.ai_native.payload_aliases.'.$key, []);

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function latestNamedOutcome(array $state): array
    {
        $outcomes = array_reverse(array_merge(
            (array) ($state['recent_outcomes'] ?? []),
            (array) data_get($state, 'task_frame.recent_outcomes', [])
        ));

        foreach ($outcomes as $outcome) {
            if (is_array($outcome) && trim((string) ($outcome['label'] ?? '')) !== '') {
                return $outcome;
            }
        }

        return [];
    }

    private function firstEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $matches) !== 1) {
            return null;
        }

        return mb_strtolower($matches[0]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function entityFromWriteConfirmation(string $message, array $state): ?string
    {
        $message = mb_strtolower($message);
        $entities = [];

        foreach (array_merge(
            (array) ($state['recent_outcomes'] ?? []),
            (array) data_get($state, 'task_frame.recent_outcomes', [])
        ) as $outcome) {
            if (!is_array($outcome)) {
                continue;
            }

            $entity = mb_strtolower(trim((string) ($outcome['entity_type'] ?? '')));
            if ($entity !== '') {
                $entities[$entity] = true;
            }
        }

        foreach (array_keys($entities) as $entity) {
            if (str_contains($message, $entity) || str_contains($message, Str::plural($entity))) {
                return $entity;
            }
        }

        return null;
    }

    private function toolMatchesEntity(string $toolName, AgentTool $tool, string $entity): bool
    {
        $toolEntity = mb_strtolower(trim((string) $tool->getEntityType()));
        if ($toolEntity === $entity) {
            return true;
        }

        $toolName = mb_strtolower($toolName);

        return str_contains($toolName, $entity)
            || str_contains($toolName, Str::plural($entity));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function argumentsFromPayloadForTool(array $payload, AgentTool $tool): array
    {
        $arguments = [];

        foreach ($tool->getParameters() as $name => $definition) {
            $definition = is_array($definition) ? $definition : [];
            if ((string) $name === 'confirmed') {
                continue;
            }

            $value = $this->payloadValueForParameter($payload, (string) $name);
            if ($value !== null && $value !== '') {
                $arguments[(string) $name] = $value;
            } elseif ((bool) ($definition['required'] ?? false)) {
                return [];
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadValueForParameter(array $payload, string $parameter): mixed
    {
        if (array_key_exists($parameter, $payload)) {
            return $payload[$parameter];
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || $value === null || $value === '') {
                continue;
            }

            if ($key === $parameter || str_ends_with($key, '_'.$parameter)) {
                return $value;
            }
        }

        return null;
    }

    private function isWriteConfirmationRequest(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        $missingInputTerms = array_map('strval', (array) config('ai-agent.ai_native.write_confirmation_question_terms.missing_input', []));
        if ($this->containsAnyTerm($message, $missingInputTerms)) {
            return false;
        }

        $approvalTerms = array_map('strval', (array) config('ai-agent.ai_native.write_confirmation_question_terms.approval', []));
        $writeTerms = array_map('strval', (array) config('ai-agent.ai_native.write_confirmation_question_terms.actions', []));

        return $this->containsAnyTerm($message, $approvalTerms)
            && $this->containsAnyTerm($message, $writeTerms);
    }

    /**
     * @param array<int|string, mixed> $requiredInputs
     */
    private function hasNonConfirmationRequiredInputs(array $requiredInputs): bool
    {
        if ($requiredInputs === []) {
            return false;
        }

        foreach ($requiredInputs as $input) {
            $name = is_array($input)
                ? (string) ($input['name'] ?? $input['id'] ?? $input['field'] ?? '')
                : (string) $input;
            $type = is_array($input) ? (string) ($input['type'] ?? '') : '';
            $value = mb_strtolower(trim($name.' '.$type));

            if ($value === '' || !preg_match('/\b(confirm|confirmation|approve|approval|yes_no|boolean)\b/u', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $terms
     */
    private function containsAnyTerm(string $message, array $terms): bool
    {
        foreach ($terms as $term) {
            $rawTerm = mb_strtolower((string) $term);
            $trimmedTerm = trim($rawTerm);
            if ($trimmedTerm === '') {
                continue;
            }

            $needle = $rawTerm !== $trimmedTerm ? $rawTerm : $trimmedTerm;
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
