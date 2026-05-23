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
            return ['action' => 'final', 'message' => $content];
        }

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
}
