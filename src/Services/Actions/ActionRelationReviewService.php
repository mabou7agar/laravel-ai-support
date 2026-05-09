<?php

namespace LaravelAIEngine\Services\Actions;

class ActionRelationReviewService
{
    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $pendingRelations
     * @return array<string, mixed>
     */
    public function review(string $actionId, array $payload, array $pendingRelations): array
    {
        $approved = array_flip(array_map('strval', (array) ($payload['approved_missing_relations'] ?? [])));

        $pendingCreates = collect($pendingRelations)
            ->filter(fn (mixed $relation): bool => is_array($relation) && (bool) ($relation['will_create'] ?? false))
            ->map(function (array $relation) use ($actionId, $payload, $approved): array {
                $field = (string) ($relation['field'] ?? 'record');
                $requiredFields = $this->requiredFields($actionId, $field);

                return [
                    'field' => $field,
                    'relation_type' => $this->relationType($field),
                    'label' => (string) ($relation['label'] ?? 'record'),
                    'approval_key' => $field,
                    'approved' => isset($approved[$field]),
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
    public function createCandidates(string $actionId, array $payload): array
    {
        if (!($payload['create_missing_relations'] ?? false)) {
            return [];
        }

        $candidates = [];

        if (in_array($actionId, ['create_sales_invoice', 'create_sales_proposal'], true)) {
            if (empty($payload['customer_id']) && !empty($payload['customer_name'])) {
                $candidates[] = [
                    'field' => 'customer_id',
                    'label' => (string) $payload['customer_name'],
                    'will_create' => true,
                ];
            }

            if (empty($payload['warehouse_id']) && !empty($payload['warehouse_name'])) {
                $candidates[] = [
                    'field' => 'warehouse_id',
                    'label' => (string) $payload['warehouse_name'],
                    'will_create' => true,
                ];
            }

            foreach ((array) ($payload['items'] ?? []) as $index => $item) {
                if (!is_array($item) || !empty($item['product_id'])) {
                    continue;
                }

                if (!empty($item['product_name']) || !empty($item['product_sku'])) {
                    $candidates[] = [
                        'field' => "items.{$index}.product_id",
                        'label' => (string) ($item['product_name'] ?? $item['product_sku']),
                        'will_create' => true,
                    ];
                }
            }
        }

        if ($actionId === 'create_helpdesk_ticket' && empty($payload['category_id']) && !empty($payload['category_name'])) {
            $candidates[] = [
                'field' => 'category_id',
                'label' => (string) $payload['category_name'],
                'will_create' => true,
            ];
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
        return match (true) {
            $field === 'customer_id' => 'customer',
            $field === 'vendor_id' => 'vendor',
            $field === 'warehouse_id' => 'warehouse',
            $field === 'category_id' => 'category',
            $field === 'account_id' => 'account',
            $field === 'employee_id' => 'employee',
            $field === 'project_id' => 'project',
            $field === 'asset_id' => 'asset',
            str_contains($field, 'product_id') => 'product',
            default => trim(str_replace(['_id', '_', '.'], ['', ' ', ' '], $field)),
        };
    }

    /**
     * @return array<int, string>
     */
    public function requiredFields(string $actionId, string $field): array
    {
        return match (true) {
            $field === 'customer_id' => ['customer_name', 'customer_email'],
            $field === 'vendor_id' => ['vendor_name', 'vendor_email'],
            $field === 'warehouse_id' => ['warehouse_name', 'warehouse_address', 'warehouse_city', 'warehouse_zip_code'],
            $field === 'category_id' => ['category_name'],
            $field === 'account_id' => ['account_name'],
            $field === 'employee_id' => ['employee_name', 'employee_email'],
            $field === 'project_id' => ['project_name'],
            $field === 'asset_id' => ['asset_name'],
            str_contains($field, 'product_id') => [
                str_replace('product_id', 'product_name', $field),
                str_replace('product_id', 'product_sku', $field),
                str_replace('product_id', 'quantity', $field),
                str_replace('product_id', 'unit_price', $field),
            ],
            default => [],
        };
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
