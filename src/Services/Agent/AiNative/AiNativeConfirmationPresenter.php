<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use Illuminate\Support\Str;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class AiNativeConfirmationPresenter
{
    /**
     * @param array<string, mixed> $params
     */
    public function confirmationMessage(?AgentTool $tool, string $toolName, array $params, string $message, ?array $summary = null): string
    {
        $toolMessage = trim((string) ($tool?->getConfirmationMessage() ?? ''));
        if ($toolMessage !== '') {
            $message = $toolMessage;
        } else {
            $message = trim($message);
            if ($message === '' || (!str_contains($message, '?') && !str_contains(mb_strtolower($message), 'confirm'))) {
                $message = $this->defaultConfirmationMessage($toolName);
            }
        }

        $summary = $this->confirmationSummary($summary ?? $params);
        $instruction = trim((string) config(
            'ai-agent.ai_native.confirmation_summary.instruction',
            'Choose Confirm to continue, or Change to edit before execution.'
        ));

        $parts = [$message];
        if ($summary !== '') {
            $parts[] = $summary;
        }

        if ($instruction !== '') {
            $parts[] = $instruction;
        }

        return implode("\n\n", $parts);
    }

    public function defaultConfirmationMessage(string $toolName): string
    {
        $template = (string) config(
            'ai-agent.ai_native.confirmation_summary.prompt',
            'Please review before I run {tool}.'
        );

        return strtr($template, [
            '{tool}' => str_replace('_', ' ', $toolName),
            '{tool_name}' => $toolName,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function confirmationSummary(array $params): string
    {
        if (!(bool) config('ai-agent.ai_native.confirmation_summary.enabled', true)) {
            return '';
        }

        $params = array_diff_key($params, ['confirmed' => true]);
        if ($params === []) {
            return '';
        }

        $lines = $this->formatSummaryValue(
            value: $params,
            depth: 0,
            maxDepth: max(1, (int) config('ai-agent.ai_native.confirmation_summary.max_depth', 3)),
            maxItems: max(1, (int) config('ai-agent.ai_native.confirmation_summary.max_items', 20)),
            path: ''
        );

        $heading = trim((string) config('ai-agent.ai_native.confirmation_summary.heading', 'Summary:'));
        if ($heading !== '') {
            array_unshift($lines, $heading);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    private function formatSummaryValue(mixed $value, int $depth, int $maxDepth, int $maxItems, string $path): array
    {
        if (!is_array($value)) {
            return [$this->formatScalar($value)];
        }

        if ($depth >= $maxDepth) {
            return ['...'];
        }

        $lines = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= $maxItems) {
                $lines[] = str_repeat('  ', $depth).'- ...';
                break;
            }

            $count++;
            $key = (string) $key;
            $currentPath = $path === '' ? $key : $path.'.'.$key;
            $indent = str_repeat('  ', $depth);
            $label = is_numeric($key) ? '- '.((int) $key + 1) : $this->friendlySummaryLabel($key);

            if ($this->isHiddenSummaryField($currentPath) || $this->isHiddenSummaryValue($item)) {
                continue;
            }

            if ($this->isRedactedSummaryField($currentPath)) {
                $lines[] = "{$indent}{$label}: [redacted]";
                continue;
            }

            if (!is_array($item)) {
                $lines[] = "{$indent}{$label}: ".$this->formatScalar($item);
                continue;
            }

            $lines[] = "{$indent}{$label}:";
            foreach ($this->formatSummaryValue($item, $depth + 1, $maxDepth, $maxItems, $currentPath) as $childLine) {
                $lines[] = $childLine;
            }
        }

        return $lines;
    }

    private function formatScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            $value = (string) $value;
        } else {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[value]';
        }

        $maxLength = max(20, (int) config('ai-agent.ai_native.confirmation_summary.max_value_length', 160));

        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength - 3).'...' : $value;
    }

    private function friendlySummaryLabel(string $key): string
    {
        $label = preg_replace('/[_\-. ]name$/i', '', $key);
        $label = is_string($label) && $label !== '' ? $label : $key;

        return Str::headline(str_replace(['.', '-'], '_', $label));
    }

    private function isHiddenSummaryValue(mixed $value): bool
    {
        if (!(bool) config('ai-agent.ai_native.confirmation_summary.hide_empty_values', true)) {
            return false;
        }

        if ($value === null || $value === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }

    private function isHiddenSummaryField(string $path): bool
    {
        $path = mb_strtolower($path);
        $segments = array_map('mb_strtolower', explode('.', $path));

        foreach ((array) config('ai-agent.ai_native.confirmation_summary.hidden_fields', []) as $pattern) {
            $pattern = mb_strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }

            if (fnmatch($pattern, $path)) {
                return true;
            }

            foreach ($segments as $segment) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isRedactedSummaryField(string $path): bool
    {
        $segments = array_map('mb_strtolower', explode('.', $path));
        foreach ((array) config('ai-agent.ai_native.confirmation_summary.redacted_fields', []) as $field) {
            $field = mb_strtolower(trim((string) $field));
            if ($field === '') {
                continue;
            }

            foreach ($segments as $segment) {
                if ($segment === $field || str_contains($segment, $field)) {
                    return true;
                }
            }
        }

        return false;
    }
}
