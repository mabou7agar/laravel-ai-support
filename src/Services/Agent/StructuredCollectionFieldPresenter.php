<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\StructuredCollectionDefinition;

class StructuredCollectionFieldPresenter
{
    public function present(StructuredCollectionDefinition $definition, ?string $language = null): array
    {
        $required = array_flip($definition->requiredFields());
        $fields = [];

        foreach ($definition->properties() as $name => $property) {
            if (!is_string($name) || !is_array($property)) {
                continue;
            }

            $metadata = is_array($property['metadata'] ?? null) ? $property['metadata'] : [];
            $field = array_filter([
                'name' => $name,
                'type' => (string) ($property['type'] ?? 'string'),
                'ui' => $this->uiType($property, $metadata),
                'required' => array_key_exists($name, $required),
                'description' => isset($property['description']) ? (string) $property['description'] : null,
                'format' => isset($property['format']) ? (string) $property['format'] : null,
            ], static fn (mixed $value): bool => $value !== null);

            $options = $this->options($property, $metadata, $language);
            if ($options !== []) {
                $field['options'] = $options;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    protected function uiType(array $property, array $metadata): string
    {
        if (isset($metadata['ui']) && is_string($metadata['ui']) && trim($metadata['ui']) !== '') {
            return trim($metadata['ui']);
        }

        $format = (string) ($property['format'] ?? '');
        if ($format === 'email') {
            return 'email';
        }

        if ($format === 'uri') {
            return 'url';
        }

        if (in_array($format, ['date', 'date-time', 'time'], true)) {
            return $format === 'date-time' ? 'datetime' : $format;
        }

        if (isset($property['enum']) || isset($metadata['options'])) {
            return 'select';
        }

        return match ((string) ($property['type'] ?? 'string')) {
            'number' => 'number',
            'integer' => 'integer',
            'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'text',
        };
    }

    protected function options(array $property, array $metadata, ?string $language): array
    {
        $source = is_array($metadata['options'] ?? null) ? $metadata['options'] : null;
        if ($source === null && is_array($property['enum'] ?? null)) {
            $source = $property['enum'];
        }
        if ($source === null && is_array($property['items']['enum'] ?? null)) {
            $source = $property['items']['enum'];
        }

        if (!is_array($source)) {
            return [];
        }

        $options = [];
        foreach ($source as $key => $option) {
            $value = $this->optionValue($key, $option);
            if ($value === '') {
                continue;
            }

            $options[] = [
                'value' => $value,
                'label' => $this->optionLabel($value, $option, $language),
            ];
        }

        return $options;
    }

    protected function optionValue(int|string $key, mixed $option): string
    {
        if (is_array($option)) {
            return (string) ($option['value'] ?? $key);
        }

        return is_string($key) ? $key : (string) $option;
    }

    protected function optionLabel(string $value, mixed $option, ?string $language): string
    {
        if (is_array($option)) {
            $labels = is_array($option['labels'] ?? null) ? $option['labels'] : [];
            $locale = $this->locale($language);

            if ($locale !== null && isset($labels[$locale]) && is_scalar($labels[$locale])) {
                return (string) $labels[$locale];
            }

            if ($locale !== null) {
                $baseLocale = strtolower(strtok($locale, '_-') ?: $locale);
                if (isset($labels[$baseLocale]) && is_scalar($labels[$baseLocale])) {
                    return (string) $labels[$baseLocale];
                }
            }

            if (isset($option['label']) && is_scalar($option['label'])) {
                return (string) $option['label'];
            }
        }

        return $this->humanize($value);
    }

    protected function locale(?string $language): ?string
    {
        $language = is_string($language) ? trim($language) : '';

        return $language !== '' ? strtolower($language) : null;
    }

    protected function humanize(string $value): string
    {
        $label = str_replace(['_', '-'], ' ', trim($value));
        $label = preg_replace('/\s+/', ' ', $label) ?: $value;

        return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
    }
}
