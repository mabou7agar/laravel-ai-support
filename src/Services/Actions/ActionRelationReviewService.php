<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

class ActionRelationReviewService
{
    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $pendingRelations
     * @return array<string, mixed>
     */
    public function review(string $actionId, array $payload, array $pendingRelations, array $action = []): array
    {
        $approved = array_flip(array_map('strval', (array) ($payload['approved_missing_relations'] ?? [])));

        $pendingCreates = collect($pendingRelations)
            ->filter(fn (mixed $relation): bool => is_array($relation) && (bool) ($relation['will_create'] ?? false))
            ->map(function (array $relation) use ($payload, $approved, $action): array {
                $field = (string) ($relation['field'] ?? 'record');
                $rule = $this->relationRule($field, $action);
                $requiredFields = array_values((array) (
                    $relation['all_required_fields']
                    ?? $relation['create_required_fields']
                    ?? $relation['required_fields']
                    ?? $rule['create_required_fields']
                    ?? $rule['required_fields']
                    ?? []
                ));
                $approvalKey = (string) ($relation['approval_key'] ?? $rule['approval_key'] ?? $field);

                return [
                    'field' => $field,
                    'relation_type' => (string) ($relation['relation_type'] ?? $rule['relation_type'] ?? $this->relationType($field)),
                    'label' => (string) ($relation['label'] ?? 'record'),
                    'approval_key' => $approvalKey,
                    'approved' => isset($approved[$approvalKey]),
                    'required_fields' => $this->missingRequiredFields($payload, $requiredFields),
                    'all_required_fields' => $requiredFields,
                    'instruction' => $this->message('Ask the user before creating this missing related record and collect required fields.'),
                ];
            })
            ->values()
            ->all();

        return [
            'pending_creates' => $pendingCreates,
            'requires_relation_approval' => collect($pendingCreates)->contains(fn (array $relation): bool => !($relation['approved'] ?? false)),
            'approval_payload_field' => 'approved_missing_relations',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function createCandidates(string $actionId, array $payload, array $action = []): array
    {
        if (!($payload['create_missing_relations'] ?? false)) {
            return [];
        }

        $candidates = [];
        foreach ($this->relationRules($action) as $rule) {
            $field = (string) ($rule['field'] ?? '');
            if ($field === '' || data_get($payload, $field) !== null) {
                continue;
            }

            $label = $this->firstPayloadValue($payload, (array) ($rule['label_fields'] ?? $rule['lookup_fields'] ?? []));
            if ($label === null && isset($rule['label'])) {
                $label = (string) $rule['label'];
            }

            if ($label === null || trim($label) === '') {
                continue;
            }

            $candidates[] = array_filter([
                'field' => $field,
                'label' => $label,
                'relation_type' => $rule['relation_type'] ?? $this->relationType($field),
                'approval_key' => $rule['approval_key'] ?? $field,
                'all_required_fields' => $rule['create_required_fields'] ?? $rule['required_fields'] ?? [],
                'will_create' => true,
            ], fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $relationReview
     * @return array<int, array<string, mixed>>
     */
    public function nextOptions(array $relationReview, bool $includeFinalConfirmation = true): array
    {
        $pending = collect($relationReview['pending_creates'] ?? [])
            ->filter(fn (array $relation): bool => !($relation['approved'] ?? false))
            ->values();

        if ($pending->isEmpty()) {
            return $includeFinalConfirmation ? [
                [
                    'type' => 'final_action_confirmation',
                    'instruction' => $this->message('Summarize the prepared draft and ask the user to confirm the final action.'),
                ],
            ] : [];
        }

        return $pending
            ->map(fn (array $relation): array => [
                'type' => 'relation_create_confirmation',
                'relation_type' => $relation['relation_type'],
                'label' => $relation['label'],
                'approval_key' => $relation['approval_key'],
                'required_fields' => $relation['required_fields'],
                'instruction' => $this->message('Ask whether to create this missing '.$relation['relation_type'].' before final action confirmation.'),
            ])
            ->all();
    }

    /**
     * @param array<string, mixed> $prepared
     * @return array<int, array<string, mixed>>
     */
    public function unapprovedMissingRelations(array $prepared): array
    {
        return collect($prepared['relation_review']['pending_creates'] ?? [])
            ->filter(fn (array $relation): bool => !($relation['approved'] ?? false))
            ->values()
            ->all();
    }

    public function relationType(string $field): string
    {
        $segment = collect(explode('.', $field))
            ->reject(fn (string $part): bool => $part === '*' || ctype_digit($part))
            ->last() ?: $field;

        return trim(str_replace(['_id', '_'], ['', ' '], $segment)) ?: 'record';
    }

    /**
     * @return array<int, string>
     */
    public function requiredFields(string $actionId, string $field): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<int, array<string, mixed>>
     */
    private function relationRules(array $action): array
    {
        return collect($action['relation_creates'] ?? $action['relations'] ?? [])
            ->filter(fn (mixed $rule): bool => is_array($rule))
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function relationRule(string $field, array $action): array
    {
        foreach ($this->relationRules($action) as $rule) {
            if (($rule['field'] ?? null) === $field) {
                return $rule;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $fields
     */
    private function firstPayloadValue(array $payload, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $value = data_get($payload, $field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    private function missingRequiredFields(array $payload, array $fields): array
    {
        return collect($fields)
            ->filter(function (string $field) use ($payload): bool {
                $value = data_get($payload, $field);

                return $value === null || $value === '';
            })
            ->values()
            ->all();
    }

    private function message(string $message): string
    {
        return function_exists('__') ? __($message) : $message;
    }
}
