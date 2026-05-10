<?php

namespace LaravelAIEngine\Services\Actions;

class ActionFlowGuideService
{
    /**
     * @param array<string, mixed>|null $action
     * @return array<string, mixed>
     */
    public function guide(string $actionId, ?array $action): array
    {
        if (!$action) {
            return [
                'success' => false,
                'message' => $this->message('Action is not available.'),
            ];
        }

        return [
            'success' => true,
            'message' => $this->message('Action flow guide loaded.'),
            'action_id' => $actionId,
            'action' => [
                'label' => $action['label'] ?? $actionId,
                'module' => $action['module'] ?? 'default',
                'operation' => $action['operation'] ?? 'create',
                'required_fields' => array_values((array) ($action['required'] ?? [])),
                'parameters' => (array) ($action['parameters'] ?? []),
                'confirmation_required' => (bool) ($action['confirmation_required'] ?? true),
            ],
            'flow' => $this->flow($actionId, $action),
            'guardrails' => $this->guardrails(),
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<int, array<string, mixed>>
     */
    public function flow(string $actionId, array $action): array
    {
        $flow = [
            [
                'step' => 'collect_required_fields',
                'instruction' => 'Collect required fields from the action schema. Ask concise follow-up questions for missing fields.',
                'required_fields' => array_values((array) ($action['required'] ?? [])),
            ],
        ];

        foreach ($this->relationSteps($action) as $step) {
            $flow[] = $step;
        }

        $flow[] = [
                'step' => 'prepare_draft',
                'instruction' => 'Call update_action_draft after each user answer and inspect missing_fields, current_payload, relation_review, and next_options.',
                'tool' => 'update_action_draft',
        ];
        $flow[] = [
                'step' => 'final_confirmation',
                'instruction' => 'Execute only after explicit user confirmation of the final prepared draft.',
                'tool' => 'execute_action',
                'requires_confirmed_true' => true,
        ];

        return $flow;
    }

    /**
     * @return array<int, string>
     */
    public function guardrails(): array
    {
        return [
            'Do not write data from conversation text alone.',
            'Never set execute_action.confirmed=true unless the user explicitly confirms the final prepared draft.',
            'When a configured related record is missing and marked safe to create, ask whether to create it and collect its configured create fields before continuing.',
            'If multiple relation matches exist, ask the user to choose one by ID or unique label.',
            'Use update_action_draft after each user answer; trust its missing_fields, pending_relations, resolved_relations, and current_payload as the source of truth.',
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<int, array<string, mixed>>
     */
    private function relationSteps(array $action): array
    {
        return collect($action['relation_creates'] ?? $action['relations'] ?? [])
            ->filter(fn (mixed $relation): bool => is_array($relation))
            ->map(function (array $relation): array {
                $field = (string) ($relation['field'] ?? 'record');
                $type = (string) ($relation['relation_type'] ?? trim(str_replace(['_id', '_'], ['', ' '], $field)) ?: 'record');

                return array_filter([
                    'step' => 'review_' . str_replace(['.', '*'], '_', $field) . '_relation',
                    'instruction' => 'Resolve the related record. If it is missing and safe_create is true, ask whether to create it and collect create_required_fields before final confirmation.',
                    'relation' => $type,
                    'field' => $field,
                    'lookup_fields' => array_values((array) ($relation['lookup_fields'] ?? [])),
                    'safe_create' => (bool) ($relation['safe_create'] ?? true),
                    'create_required_fields' => array_values((array) ($relation['create_required_fields'] ?? $relation['required_fields'] ?? [])),
                    'approval_payload' => ['approved_missing_relations' => [$relation['approval_key'] ?? $field]],
                    'user_must_confirm_relation_create' => true,
                ], fn (mixed $value): bool => $value !== null && $value !== []);
            })
            ->values()
            ->all();
    }

    private function message(string $message): string
    {
        return function_exists('__') ? __($message) : $message;
    }
}
