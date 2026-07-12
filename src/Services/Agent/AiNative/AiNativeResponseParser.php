<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

class AiNativeResponseParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['action' => 'ask_user', 'message' => 'I need more information to continue.'];
        }

        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode(trim($content), true);

        if (!is_array($decoded)) {
            // Models routinely emit prose followed by the plan JSON. Extract the
            // embedded plan object instead of dumping the raw mixed content —
            // including the JSON blob — into the user-facing final message.
            $embedded = $this->extractEmbeddedPlan($content);
            if ($embedded === null) {
                return ['action' => 'final', 'message' => $content];
            }

            [$decoded, $prose] = $embedded;
            $plan = $this->normalizePlan($decoded);
            if (trim((string) ($plan['message'] ?? '')) === '' && $prose !== '') {
                $plan['message'] = $prose;
            }

            return $plan;
        }

        return $this->normalizePlan($decoded);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalizePlan(array $decoded): array
    {
        $action = strtolower(trim((string) ($decoded['action'] ?? $decoded['type'] ?? 'final')));

        $normalized = match ($action) {
            'tool', 'call_tool', 'tool_call', 'run_tool' => 'tool_call',
            'ask', 'ask_user', 'needs_user_input', 'input_required' => 'ask_user',
            'final', 'final_response', 'answer', 'done' => 'final',
            default => null,
        };

        if ($normalized === null && $action !== '') {
            $arguments = $decoded['arguments'] ?? $decoded['parameters'] ?? array_diff_key($decoded, array_flip([
                'action',
                'type',
                'tool',
                'tool_name',
                'message',
                'required_inputs',
                'data',
            ]));

            return array_replace($decoded, [
                'action' => 'tool_call',
                'tool' => $decoded['tool'] ?? $decoded['tool_name'] ?? $action,
                'arguments' => is_array($arguments) ? $arguments : [],
            ]);
        }

        return array_replace($decoded, [
            'action' => $normalized ?? 'final',
        ]);
    }

    /**
     * Find the first balanced JSON object in mixed prose+JSON content that
     * looks like a plan (carries an action/type/tool key). Returns the decoded
     * object plus the prose preceding it, or null when no such object exists.
     *
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private function extractEmbeddedPlan(string $content): ?array
    {
        $offset = 0;
        while (($start = strpos($content, '{', $offset)) !== false) {
            $candidate = $this->balancedObjectAt($content, $start);
            if ($candidate !== null) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)
                    && (isset($decoded['action']) || isset($decoded['type']) || isset($decoded['tool']) || isset($decoded['tool_name']))
                ) {
                    return [$decoded, trim(substr($content, 0, $start))];
                }
            }

            $offset = $start + 1;
        }

        return null;
    }

    /**
     * The balanced {...} substring starting at $start, tracking string literals
     * and escapes so braces inside JSON strings don't break the count. Null
     * when the object never closes.
     */
    private function balancedObjectAt(string $content, int $start): ?string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = $inString;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
