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
                // Last resort: the model narrated a tool call in prose/markdown
                // (e.g. "**Tool Call:** add_section\n```json\n{args}```") instead
                // of the JSON action shape. The tool NAME is in the prose and the
                // JSON is arguments-only, so extractEmbeddedPlan (which needs an
                // action/tool key inside the object) misses it — and the raw
                // markdown would otherwise be dumped into the chat as a final
                // message while the tool never runs. Reconstruct the tool_call.
                $salvaged = $this->salvageToolCallNarration($content);
                if ($salvaged !== null) {
                    return $salvaged;
                }

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
     * Salvage a tool call the model narrated as prose/markdown instead of the
     * JSON action shape — e.g. "**Tool Call:** add_section" (or "Tool: x",
     * "calling add_section") followed by a fenced/bare JSON object of arguments.
     * High precision: fires only after valid-JSON and embedded-plan parsing have
     * both failed, and only when a tool-call CUE and a snake_case tool identifier
     * both appear in the prose BEFORE the first JSON object (so argument keys are
     * never mistaken for the tool name). The first balanced {...} is the arguments.
     *
     * @return array<string, mixed>|null
     */
    private function salvageToolCallNarration(string $content): ?array
    {
        $jsonStart = strpos($content, '{');
        $prefix = $jsonStart !== false ? substr($content, 0, $jsonStart) : $content;

        // A tool-call cue somewhere in the prose lead-in. Beyond the verbose
        // cues, terse narration formats observed live must match:
        //   "**Tool: theme_builder_add_section**"        (bare "Tool:" label)
        //   "_call theme_builder_regenerate_section"     (underscore prefix —
        //    the \b in the verbose cue can never fire inside "_call" because
        //    the underscore is a word character)
        //   "**theme_builder_generate_view**"            (bold tool name, NO cue
        //    word) — the content OPENS with a markdown-emphasised snake_case
        //    identifier and nothing else on that first line; a strong tool-call
        //    signal with no verb, so treat a leading bold/emphasised bare tool
        //    name as its own cue.
        $leadingBoldTool = preg_match(
            '/^\s*(?:[*_`]{1,2})\s*([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\s*(?:[*_`]{1,2})/i',
            (string) preg_replace('/^\s*#{1,6}\s*/', '', $content),
        ) === 1;
        if (! $leadingBoldTool && preg_match(
            '/(?:\b(?:tool\s*_?\s*call|call\s+tool|invoke|calling|function\s+call|use\s+tool)\b|\btool\s*:|(?:^|[\s>*`\-])_call\b|\bcall\s*:)/im',
            $prefix,
        ) !== 1) {
            return null;
        }

        // The first snake_case identifier (>= 1 underscore) is the tool name.
        if (preg_match('/\b([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b/i', $prefix, $m) !== 1) {
            return null;
        }
        $tool = strtolower($m[1]);

        $arguments = [];
        if ($jsonStart !== false) {
            $candidate = $this->balancedObjectAt($content, $jsonStart);
            if ($candidate !== null) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    // If the object already carries a plan, defer to normal parsing.
                    if (isset($decoded['action']) || isset($decoded['tool']) || isset($decoded['tool_name'])) {
                        return null;
                    }
                    $arguments = $decoded;
                }
            }
        }

        return ['action' => 'tool_call', 'tool' => $tool, 'arguments' => $arguments];
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
