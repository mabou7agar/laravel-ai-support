<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

class RAGAggregateIntentResolver
{
    public function operation(string $messageLower, string $content): string
    {
        $haystack = strtolower($content . ' ' . $messageLower);

        if (
            str_contains($haystack, 'summary') ||
            str_contains($haystack, 'statistics') ||
            str_contains($haystack, 'stats') ||
            ((str_contains($haystack, 'total') || str_contains($haystack, 'sum')) &&
                (str_contains($haystack, 'average') || str_contains($haystack, 'avg')))
        ) {
            return 'summary';
        }

        if (str_contains($haystack, 'count') || str_contains($haystack, 'how many')) {
            return 'count';
        }

        if (str_contains($haystack, 'average') || str_contains($haystack, 'avg')) {
            return 'avg';
        }

        if (str_contains($haystack, 'minimum') || str_contains($haystack, 'min')) {
            return 'min';
        }

        if (str_contains($haystack, 'maximum') || str_contains($haystack, 'max')) {
            return 'max';
        }

        return 'sum';
    }

    public function field(array $context, ?string $modelName, string $messageLower = ''): string
    {
        $modelContext = collect($context['models'] ?? [])
            ->first(fn (array $model) => ($model['name'] ?? null) === $modelName);

        $schema = (array) ($modelContext['schema'] ?? []);
        $scored = [];
        foreach ($schema as $field => $type) {
            $fieldLower = strtolower((string) $field);
            $typeString = strtolower((string) $type);
            $isNumeric = str_contains($typeString, 'int') ||
                str_contains($typeString, 'float') ||
                str_contains($typeString, 'double') ||
                str_contains($typeString, 'decimal') ||
                str_contains($typeString, 'numeric') ||
                preg_match('/(amount|total|price|cost|balance|subtotal|tax|quantity|qty|rate|value)$/i', $fieldLower) === 1;

            if (!$isNumeric) {
                continue;
            }

            $score = 10;
            if (preg_match('/(^|_)id$/', $fieldLower)) {
                $score -= 100;
            }
            if (str_contains($messageLower, str_replace('_', ' ', $fieldLower)) || str_contains($messageLower, $fieldLower)) {
                $score += 120;
            }
            if (in_array($fieldLower, ['total', 'amount', 'total_amount', 'amount_total'], true)) {
                $score += 90;
            } elseif (str_contains($fieldLower, 'total') || str_contains($fieldLower, 'amount')) {
                $score += 75;
            } elseif (str_contains($fieldLower, 'balance') || str_contains($fieldLower, 'price') || str_contains($fieldLower, 'cost')) {
                $score += 60;
            } elseif (str_contains($fieldLower, 'subtotal') || str_contains($fieldLower, 'tax')) {
                $score += 45;
            }

            $scored[(string) $field] = $score;
        }

        if ($scored !== []) {
            arsort($scored);

            return (string) array_key_first($scored);
        }

        return array_key_first($schema) ?: 'id';
    }

    public function groupBy(array $context, string $messageLower): ?string
    {
        if (preg_match('/\b(group by|by|per)\s+([a-z_ ]{2,32})/i', $messageLower, $matches)) {
            $candidate = trim(str_replace(' ', '_', strtolower($matches[2])));
            $candidate = preg_replace('/\b(invoice|invoices|mail|mails|emails|records|items|amount|total|average|avg|sum|count)\b/', '', $candidate) ?? '';
            $candidate = trim($candidate, '_ ');
            if ($candidate !== '') {
                $field = $this->resolveGroupField($context, $candidate);
                if ($field !== null) {
                    return $field;
                }
            }
        }

        foreach ([
            'status' => ['status', 'state'],
            'month' => ['month', 'monthly'],
            'customer_name' => ['customer', 'client'],
            'project_id' => ['project'],
            'workspace_id' => ['workspace'],
            'user_id' => ['user', 'owner'],
        ] as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/\b(by|per|grouped by|group by)\s+' . preg_quote($alias, '/') . '\b/i', $messageLower)) {
                    return $this->resolveGroupField($context, $field) ?? $field;
                }
            }
        }

        return null;
    }

    public function resolveGroupField(array $context, string $candidate): ?string
    {
        if ($candidate === 'month') {
            return 'month';
        }

        $schema = [];
        foreach ((array) ($context['models'] ?? []) as $model) {
            $schema = array_merge($schema, (array) ($model['schema'] ?? []));
        }

        $candidate = strtolower(trim($candidate));
        foreach (array_keys($schema) as $field) {
            $fieldLower = strtolower((string) $field);
            if ($fieldLower === $candidate || str_replace('_', ' ', $fieldLower) === str_replace('_', ' ', $candidate)) {
                return (string) $field;
            }
        }

        return null;
    }

    public function hasIntent(string $messageLower, string $content): bool
    {
        $haystack = strtolower($content . ' ' . $messageLower);

        return str_contains($haystack, 'db_aggregate') ||
            str_contains($haystack, 'sum') ||
            str_contains($haystack, 'total') ||
            str_contains($haystack, 'average') ||
            str_contains($haystack, 'avg') ||
            str_contains($haystack, 'minimum') ||
            str_contains($haystack, 'maximum') ||
            str_contains($haystack, 'how many') ||
            str_contains($haystack, 'count');
    }
}
