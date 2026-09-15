<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\Tools\AgentTool;

/**
 * Finds optional details the user literally gave in the latest message that the planned write
 * left out (e.g. "phone +20 100 123 4567" on a create_lead call without a phone argument).
 *
 * Deliberately conservative — it only reports, it never fills or guesses:
 *  - only scalar, optional, top-level parameters that are empty in the arguments;
 *  - never ids/foreign keys, booleans, arrays or the confirmation flag;
 *  - the message must carry the parameter's own label (its name as words, e.g. "due date"),
 *    followed by a value of the expected shape (email, phone, date, number, url) or, for
 *    free text, an explicit "label: value" / "label = value" / quoted value;
 *  - a label that points at more than one empty parameter is ambiguous and skipped;
 *  - a value already present anywhere in the arguments is skipped.
 */
class AiNativeOmittedDetailDetector
{
    /** Words too generic to identify a parameter on their own. */
    private const GENERIC_WORDS = [
        'name', 'number', 'date', 'type', 'status', 'value', 'code', 'amount', 'text', 'data',
        'info', 'details', 'detail', 'note', 'notes', 'time', 'first', 'last', 'full', 'total',
        'user', 'customer', 'client', 'main', 'primary', 'secondary', 'other',
    ];

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, string> parameter name => value as written by the user
     */
    public function detect(AgentTool $tool, array $arguments, string $message): array
    {
        // Hosts may prepend a TURN CONTEXT preamble (page state) before a "User request:" fence;
        // only the user's own words count.
        if (preg_match_all('/\n\s*User request(?:\s*\([^)\n]{0,80}\))?\s*:\s*/u', $message, $fences, PREG_OFFSET_CAPTURE) > 0) {
            [$marker, $offset] = $fences[0][count($fences[0]) - 1];
            $message = substr($message, (int) $offset + strlen((string) $marker));
        }

        $message = trim($message);
        if ($message === '') {
            return [];
        }

        $candidates = [];
        foreach ($tool->getParameters() as $name => $definition) {
            $name = (string) $name;
            if (!is_array($definition) || !$this->isFillableOptional($name, $definition)) {
                continue;
            }

            $value = $arguments[$name] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                continue;
            }

            foreach ($this->labels($name) as $label) {
                $candidates[$label][] = [$name, $definition];
            }
        }

        $encodedArguments = mb_strtolower((string) json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $found = [];
        foreach ($candidates as $label => $parameters) {
            if (count($parameters) !== 1) {
                continue;
            }

            [$name, $definition] = $parameters[0];
            if (isset($found[$name])) {
                continue;
            }

            $value = $this->labelledValue($message, $label, $this->shape($name, $definition));
            if ($value === null || str_contains($encodedArguments, mb_strtolower($value))) {
                continue;
            }

            $found[$name] = $value;
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function isFillableOptional(string $name, array $definition): bool
    {
        if (($definition['required'] ?? false) === true) {
            return false;
        }

        $lower = mb_strtolower($name);
        if ($lower === 'confirmed' || $lower === 'id' || str_ends_with($lower, '_id') || str_ends_with($lower, '_ids')) {
            return false;
        }

        $type = mb_strtolower((string) ($definition['type'] ?? 'string'));

        return in_array($type, ['string', 'number', 'integer', 'float', 'date', 'datetime', 'email', 'url', ''], true)
            && !isset($definition['enum']);
    }

    /**
     * @return array<int, string>
     */
    private function labels(string $name): array
    {
        $words = array_values(array_filter(preg_split('/[_\-\s]+/', mb_strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name)) ?: []));
        if ($words === []) {
            return [];
        }

        $labels = [implode(' ', $words)];
        if (count($words) > 1) {
            foreach ($words as $word) {
                if (mb_strlen($word) >= 4 && !in_array($word, self::GENERIC_WORDS, true)) {
                    $labels[] = $word;
                }
            }
        } elseif (in_array($words[0], self::GENERIC_WORDS, true)) {
            // A lone generic name ("name", "notes") is only matched with an explicit separator.
            return [$words[0] . ':'];
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function shape(string $name, array $definition): string
    {
        $lower = mb_strtolower($name);
        $type = mb_strtolower((string) ($definition['type'] ?? 'string'));
        $format = mb_strtolower((string) ($definition['format'] ?? ''));

        return match (true) {
            $type === 'email' || $format === 'email' || str_contains($lower, 'email') => 'email',
            preg_match('/phone|mobile|tel|whatsapp|fax/', $lower) === 1 => 'phone',
            in_array($type, ['date', 'datetime'], true) || in_array($format, ['date', 'date-time'], true) || preg_match('/(^|_)(date|dob|birthday)($|_)|_at$|_on$/', $lower) === 1 => 'date',
            in_array($type, ['number', 'integer', 'float'], true) => 'number',
            $type === 'url' || $format === 'uri' || preg_match('/url|website|link/', $lower) === 1 => 'url',
            default => 'text',
        };
    }

    private function labelledValue(string $message, string $label, string $shape): ?string
    {
        $explicitOnly = str_ends_with($label, ':');
        $label = rtrim($label, ':');
        $labelPattern = implode('[\s_\-]+', array_map(static fn (string $word): string => preg_quote($word, '/'), explode(' ', $label)));
        $separator = $explicitOnly || $shape === 'text'
            ? '\s*(?:[:=]|\bis\b)\s*'
            : '\s*(?:[:=]|\bis\b|\bof\b)?\s*';

        $valuePattern = match ($shape) {
            'email' => '[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}',
            'phone' => '\+?\d[\d\s().\-]{5,}\d',
            'date' => '\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2})?)?|\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}',
            'number' => '-?\d+(?:[.,]\d+)?',
            'url' => 'https?:\/\/[^\s,;]+',
            default => '"[^"\n]{1,200}"|\'[^\'\n]{1,200}\'|[^,;\n.]{1,120}',
        };

        $pattern = '/(?<![\p{L}\p{N}_])' . $labelPattern . '(?![\p{L}\p{N}_])' . $separator . '(' . $valuePattern . ')/iu';
        if (preg_match($pattern, $message, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);
        $value = trim($value, "\"'");
        if ($shape === 'text') {
            // Stop free text at a following "and <label>" clause.
            $value = trim((string) preg_replace('/\s+(?:and|with)\s+.*$/iu', '', $value));
        }

        return $value !== '' ? $value : null;
    }
}
