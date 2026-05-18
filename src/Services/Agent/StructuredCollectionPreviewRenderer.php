<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\StructuredCollectionDefinition;

class StructuredCollectionPreviewRenderer
{
    public function render(StructuredCollectionDefinition $definition, array $state, array $fields, string $status): ?array
    {
        $presentation = $this->presentation($definition);
        if (($presentation['preview'] ?? false) !== true) {
            return null;
        }

        $mode = (string) ($presentation['mode'] ?? 'html');
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        $missingFields = array_values(array_filter((array) ($state['missing_fields'] ?? []), 'is_string'));
        $assets = $this->assets($presentation);
        $props = [
            'name' => $definition->name,
            'status' => $status,
            'data' => $data,
            'missing_fields' => $missingFields,
            'language' => $state['language'] ?? null,
            'fields' => $fields,
        ];

        if ($mode === 'component') {
            return [
                'type' => 'component',
                'component' => [
                    'name' => 'ai-structured-collection-form',
                    'props' => $props,
                ],
                'assets' => $assets,
            ];
        }

        if ($mode === 'schema') {
            return [
                'type' => 'schema',
                'schema' => $props,
                'assets' => $assets,
            ];
        }

        return [
            'type' => 'html',
            'html' => $this->html($definition, $fields, $data, $missingFields, $status),
            'component' => [
                'name' => 'ai-structured-collection-form',
                'props' => $props,
            ],
            'assets' => $assets,
        ];
    }

    protected function presentation(StructuredCollectionDefinition $definition): array
    {
        $presentation = $definition->presentation();
        if ($presentation !== []) {
            return $presentation;
        }

        if ((bool) config('ai-agent.structured_collection.preview.enabled', false) === false) {
            return ['preview' => false];
        }

        return [
            'preview' => true,
            'mode' => (string) config('ai-agent.structured_collection.preview.mode', 'component'),
        ];
    }

    protected function assets(array $presentation): array
    {
        $assets = is_array($presentation['assets'] ?? null)
            ? $presentation['assets']
            : (array) config('ai-agent.structured_collection.preview.assets', []);

        return [
            'css' => array_values(array_filter((array) ($assets['css'] ?? []), 'is_string')),
            'js' => array_values(array_filter((array) ($assets['js'] ?? []), 'is_string')),
        ];
    }

    protected function html(StructuredCollectionDefinition $definition, array $fields, array $data, array $missingFields, string $status): string
    {
        $collection = $this->escape($definition->name);
        $statusValue = $this->escape($status);
        $html = "<form class=\"ai-collection-form\" data-ai-collection=\"{$collection}\" data-ai-status=\"{$statusValue}\">";

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $html .= $this->fieldHtml($field, $data, $missingFields);
        }

        return $html . '</form>';
    }

    protected function fieldHtml(array $field, array $data, array $missingFields): string
    {
        $name = (string) ($field['name'] ?? '');
        if ($name === '') {
            return '';
        }

        $id = 'ai-collection-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name);
        $ui = (string) ($field['ui'] ?? 'text');
        $label = $this->escape((string) ($field['label'] ?? $field['description'] ?? $name));
        $escapedName = $this->escape($name);
        $escapedId = $this->escape($id);
        $value = $data[$name] ?? null;
        $required = !empty($field['required']) ? ' required' : '';
        $missing = in_array($name, $missingFields, true) ? ' data-ai-missing="true"' : '';
        $description = isset($field['description'])
            ? '<small class="ai-collection-field-description">' . $this->escape((string) $field['description']) . '</small>'
            : '';

        return match ($ui) {
            'textarea' => "<label class=\"ai-collection-field\"{$missing} for=\"{$escapedId}\"><span>{$label}</span><textarea id=\"{$escapedId}\" name=\"{$escapedName}\"{$required}>" . $this->escapeScalar($value) . "</textarea>{$description}</label>",
            'select', 'radio', 'multiselect', 'checkboxes' => $this->choiceFieldHtml($field, $value, $required, $missing, $escapedId, $escapedName, $label, $description),
            'boolean' => "<label class=\"ai-collection-field ai-collection-field-checkbox\"{$missing}><input id=\"{$escapedId}\" name=\"{$escapedName}\" type=\"checkbox\" value=\"1\"" . ($value ? ' checked' : '') . "{$required}><span>{$label}</span>{$description}</label>",
            default => "<label class=\"ai-collection-field\"{$missing} for=\"{$escapedId}\"><span>{$label}</span><input id=\"{$escapedId}\" name=\"{$escapedName}\" type=\"" . $this->inputType($ui) . "\" value=\"" . $this->escapeScalar($value) . "\"{$required}>{$description}</label>",
        };
    }

    protected function choiceFieldHtml(array $field, mixed $value, string $required, string $missing, string $id, string $name, string $label, string $description): string
    {
        $ui = (string) ($field['ui'] ?? 'select');
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $multiple = in_array($ui, ['multiselect', 'checkboxes'], true);
        $selected = is_array($value) ? array_map('strval', $value) : [(string) $value];

        if (in_array($ui, ['radio', 'checkboxes'], true)) {
            $html = "<fieldset class=\"ai-collection-field ai-collection-choice-field\"{$missing}><legend>{$label}</legend>";
            foreach ($options as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionValue = (string) ($option['value'] ?? '');
                $optionLabel = $this->escape((string) ($option['label'] ?? $optionValue));
                $escapedValue = $this->escape($optionValue);
                $optionId = $this->escape($id . '-' . $index);
                $type = $ui === 'radio' ? 'radio' : 'checkbox';
                $checked = in_array($optionValue, $selected, true) ? ' checked' : '';
                $fieldName = $multiple ? $name . '[]' : $name;
                $html .= "<label for=\"{$optionId}\"><input id=\"{$optionId}\" name=\"{$fieldName}\" type=\"{$type}\" value=\"{$escapedValue}\"{$checked}{$required}><span>{$optionLabel}</span></label>";
            }

            return $html . $description . '</fieldset>';
        }

        $html = "<label class=\"ai-collection-field\"{$missing} for=\"{$id}\"><span>{$label}</span><select id=\"{$id}\" name=\"" . ($multiple ? $name . '[]' : $name) . '"' . ($multiple ? ' multiple' : '') . "{$required}>";
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $optionValue = (string) ($option['value'] ?? '');
            $optionLabel = $this->escape((string) ($option['label'] ?? $optionValue));
            $escapedValue = $this->escape($optionValue);
            $isSelected = in_array($optionValue, $selected, true) ? ' selected' : '';
            $html .= "<option value=\"{$escapedValue}\"{$isSelected}>{$optionLabel}</option>";
        }

        return $html . "</select>{$description}</label>";
    }

    protected function inputType(string $ui): string
    {
        return match ($ui) {
            'email' => 'email',
            'url' => 'url',
            'phone' => 'tel',
            'number', 'integer' => 'number',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'time' => 'time',
            'file' => 'file',
            'hidden' => 'hidden',
            default => 'text',
        };
    }

    protected function escapeScalar(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $this->escape((string) $value);
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
