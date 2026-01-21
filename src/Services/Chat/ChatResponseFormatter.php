<?php

namespace LaravelAIEngine\Services\Chat;

class ChatResponseFormatter
{
    /**
     * Format model data into a readable summary
     */
    public function formatModelSummary(array $data, string $modelName, array $context = []): string
    {
        $summary = "**Created {$modelName} Summary:**\n";

        // Use AI-provided priority fields or fallback to common identifiers
        $priorityFields = $context['priority_fields'] ?? ['invoice_id', 'order_id', 'number', 'code', 'reference'];
        $shownFields = [];

        // Show priority fields first
        foreach ($priorityFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $label = $this->formatFieldLabel($field, $context);
                $summary .= "- **{$label}:** {$data[$field]}\n";
                $shownFields[] = $field;
            }
        }

        // Show relationship data (customer, user, etc.)
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['name'])) {
                $label = $this->formatFieldLabel($key, $context);
                $summary .= "- **{$label}:** {$value['name']}\n";
                if (isset($value['email'])) {
                    $summary .= "- **{$label} Email:** {$value['email']}\n";
                }
                $shownFields[] = $key;
            }
        }

        // Get important fields using AI determination
        $importantFields = $this->getImportantFields($data, $modelName, $context);
        foreach ($importantFields as $field) {
            if (isset($data[$field]) && !in_array($field, $shownFields) && !empty($data[$field]) && $data[$field] !== '0000-00-00') {
                $label = $this->formatFieldLabel($field, $context);
                $value = $data[$field];

                // Format status if numeric
                if ($field === 'status' && is_numeric($value)) {
                    $value = $this->formatStatus($value, $modelName);
                }

                $summary .= "- **{$label}:** {$value}\n";
                $shownFields[] = $field;
            }
        }

        // Show array fields (items, products, etc.)
        foreach ($data as $key => $value) {
            if (is_array($value) && !isset($value['name']) && count($value) > 0 && !in_array($key, $shownFields)) {
                // Check if it's a list of items
                $firstItem = reset($value);
                if (is_array($firstItem) && (isset($firstItem['price']) || isset($firstItem['amount']))) {
                    $label = $this->formatFieldLabel($key, $context);
                    $summary .= "\n**{$label}:**\n";
                    $total = 0;

                    foreach ($value as $item) {
                        $itemName = $item['product_name'] ?? $item['name'] ?? $item['description'] ?? $item['item'] ?? 'Item';
                        $price = $item['price'] ?? $item['amount'] ?? 0;
                        $quantity = $item['quantity'] ?? $item['qty'] ?? 1;
                        $itemTotal = $price * $quantity;
                        $total += $itemTotal;
                        $summary .= "  • {$itemName}: \${$price} × {$quantity} = \${$itemTotal}\n";
                    }

                    if ($total > 0) {
                        $summary .= "\n- **Total Amount:** \${$total}\n";
                    }
                    $shownFields[] = $key;
                }
            }
        }

        // Show ID last
        if (isset($data['id'])) {
            $summary .= "- **Database ID:** {$data['id']}\n";
        }

        return $summary;
    }

    /**
     * Format field label with AI enhancement
     */
    /**
     * Format field label with AI enhancement
     */
    public function formatFieldLabel(string $fieldName, array $context = []): string
    {
        // Check if AI provided custom labels in context
        if (!empty($context['field_labels'][$fieldName])) {
            return $context['field_labels'][$fieldName];
        }

        // Check action definition for description
        if (!empty($context['action_definition'])) {
            $fields = $context['action_definition']['parameters']['fields'] ?? [];
            $fieldConfig = $fields[$fieldName] ?? null;

            if ($fieldConfig) {
                // For relationship fields, use the search field description
                if (($fieldConfig['type'] ?? '') === 'relationship') {
                    $relationship = $fieldConfig['relationship'] ?? [];
                    $searchField = $relationship['search_field'] ?? 'name';
                    $modelClass = $relationship['model'] ?? '';
                    $modelName = class_basename($modelClass);
                    return ucfirst($modelName) . ' ' . $searchField;
                }

                // Description
                if (!empty($fieldConfig['description'])) {
                    $description = is_array($fieldConfig['description'])
                        ? ($fieldConfig['description'][0] ?? '')
                        : $fieldConfig['description'];

                    // Clean up technical notes
                    $description = preg_replace('/\s*\(.*?\)/', '', $description);
                    return ucfirst(trim($description));
                }
            }
        }

        // Fallback to formatted field name
        return ucfirst(str_replace('_', ' ', $fieldName));
    }

    /**
     * Get important fields for a model
     */
    protected function getImportantFields(array $data, string $modelName, array $context = []): array
    {
        // Check if AI provided important fields
        if (!empty($context['important_fields'])) {
            return $context['important_fields'];
        }

        // Fallback: determine from data structure
        $importantFields = [];

        // Always include these if present
        $alwaysImportant = ['name', 'title', 'email', 'phone', 'status'];
        foreach ($alwaysImportant as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $importantFields[] = $field;
            }
        }

        // Add date fields
        foreach ($data as $key => $value) {
            if (str_ends_with($key, '_date') || str_ends_with($key, '_at')) {
                if (!empty($value) && $value !== '0000-00-00') {
                    $importantFields[] = $key;
                }
            }
        }

        return $importantFields;
    }

    /**
     * Format status value to human-readable string
     */
    protected function formatStatus($status, string $modelName): string
    {
        // Common status mappings
        $statusMaps = [
            'Invoice' => ['Draft', 'Sent', 'Unpaid', 'Partially Paid', 'Paid'],
            'Order' => ['Pending', 'Processing', 'Completed', 'Cancelled'],
            'default' => ['Inactive', 'Active', 'Pending', 'Completed', 'Cancelled'],
        ];

        $map = $statusMaps[$modelName] ?? $statusMaps['default'];
        return $map[$status] ?? (string) $status;
    }
}
