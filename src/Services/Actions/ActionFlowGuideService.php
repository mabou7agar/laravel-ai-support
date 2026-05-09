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
        if (in_array($actionId, ['create_sales_invoice', 'create_sales_proposal'], true)) {
            return [
                [
                    'step' => 'select_action',
                    'instruction' => 'Identify the requested action and start or continue the current session draft.',
                    'tool' => 'update_action_draft',
                    'required_payload' => [
                        'create_missing_relations' => true,
                    ],
                ],
                [
                    'step' => 'collect_core_fields',
                    'instruction' => 'Collect dates, type, related party reference, and line item intent. Use reasonable defaults for dates only when the user did not specify them.',
                    'required_fields' => array_values((array) ($action['required'] ?? [])),
                ],
                [
                    'step' => 'review_customer_relation',
                    'instruction' => 'If customer is missing, ask whether to create it before moving on. Do not treat final action confirmation as customer creation approval.',
                    'relation' => 'customer',
                    'lookup_fields' => ['customer_id', 'customer_email', 'customer_name'],
                    'safe_create' => true,
                    'create_required_fields' => ['customer_name', 'customer_email'],
                    'approval_payload' => ['approved_missing_relations' => ['customer_id']],
                    'user_must_confirm_relation_create' => true,
                ],
                [
                    'step' => 'review_product_relations',
                    'instruction' => 'For every line item, resolve existing product by product_id, product_sku, or product_name. If missing, ask whether to create the product and collect required product fields before moving on.',
                    'relation' => 'product',
                    'lookup_fields' => ['items.*.product_id', 'items.*.product_sku', 'items.*.product_name'],
                    'safe_create' => true,
                    'create_required_fields' => ['items.*.product_name', 'items.*.product_sku', 'items.*.quantity', 'items.*.unit_price'],
                    'approval_payload' => ['approved_missing_relations' => ['items.{index}.product_id']],
                    'user_must_confirm_relation_create' => true,
                ],
                [
                    'step' => 'review_warehouse_relation',
                    'instruction' => 'For actions that need a warehouse, resolve existing warehouse or ask whether to create one.',
                    'relation' => 'warehouse',
                    'lookup_fields' => ['warehouse_id', 'warehouse_name'],
                    'safe_create' => true,
                    'create_required_fields' => ['warehouse_name', 'warehouse_address', 'warehouse_city', 'warehouse_zip_code'],
                    'approval_payload' => ['approved_missing_relations' => ['warehouse_id']],
                    'user_must_confirm_relation_create' => true,
                ],
                [
                    'step' => 'prepare_draft',
                    'instruction' => 'Call update_action_draft after each user answer. When it returns success, summarize the draft, resolved relations, and approved relation records that will be created.',
                    'tool' => 'update_action_draft',
                ],
                [
                    'step' => 'final_confirmation',
                    'instruction' => 'Execute only after the user confirms the final action draft. Final confirmation must mention the main record and approved missing relations that will be created.',
                    'tool' => 'execute_action',
                    'requires_confirmed_true' => true,
                ],
            ];
        }

        return [
            [
                'step' => 'collect_required_fields',
                'instruction' => 'Collect required fields from the action schema. Ask concise follow-up questions for missing fields.',
                'required_fields' => array_values((array) ($action['required'] ?? [])),
            ],
            [
                'step' => 'prepare_draft',
                'instruction' => 'Call update_action_draft after each user answer and inspect missing_fields/current_payload.',
                'tool' => 'update_action_draft',
            ],
            [
                'step' => 'final_confirmation',
                'instruction' => 'Execute only after explicit user confirmation.',
                'tool' => 'execute_action',
                'requires_confirmed_true' => true,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function guardrails(): array
    {
        return [
            'Do not write data from conversation text alone.',
            'Never set execute_action.confirmed=true unless the user explicitly confirms the final prepared draft.',
            'When a related customer, product, warehouse, category, account, employee, project, asset, or vendor is missing, ask whether to create it and collect that relation create schema before continuing.',
            'If multiple relation matches exist, ask the user to choose one by ID or unique label.',
            'Use update_action_draft after each user answer; trust its missing_fields, pending_relations, resolved_relations, and current_payload as the source of truth.',
        ];
    }

    private function message(string $message): string
    {
        return function_exists('__') ? __($message) : $message;
    }
}
