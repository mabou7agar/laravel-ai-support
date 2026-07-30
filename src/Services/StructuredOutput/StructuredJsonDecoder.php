<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\StructuredOutput;

final class StructuredJsonDecoder
{
    /** @return array<string|int, mixed> */
    public function decode(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        foreach ([$content, $this->withoutFence($content)] as $candidate) {
            $decoded = json_decode(trim($candidate), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $candidate = $this->balancedValue($content, $open, $close);
            if ($candidate === null) {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function withoutFence(string $content): string
    {
        return (string) preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/iu', '', $content);
    }

    private function balancedValue(string $content, string $open, string $close): ?string
    {
        $start = strpos($content, $open);
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($index = $start; $index < $length; $index++) {
            $character = $content[$index];

            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($inString && $character === '\\') {
                $escaped = true;
                continue;
            }
            if ($character === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($character === $open) {
                $depth++;
                continue;
            }
            if ($character !== $close) {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                return substr($content, $start, $index - $start + 1);
            }
        }

        return null;
    }
}
