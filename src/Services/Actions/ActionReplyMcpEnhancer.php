<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ActionReplyMcpEnhancer
{
    /**
     * @param  array{style?: string, preserve_terms?: array<int, string>}  $context
     * @return array{text: string, provider: string, metadata: array<string, mixed>}|null
     */
    public function __invoke(string $prompt, array $context): ?array
    {
        $prompt = trim($prompt);
        if ($prompt === '' || !$this->enabled()) {
            return null;
        }

        $maxChars = max(200, (int) config('ai-agent.action_reply.mcp.max_chars', config('ai-agent.humanize.max_chars', 4000)));
        if (mb_strlen($prompt) > $maxChars) {
            return null;
        }

        [$protectedPrompt, $replacements] = $this->protectTerms(
            $prompt,
            is_array($context['preserve_terms'] ?? null) ? $context['preserve_terms'] : []
        );

        $text = $this->callMcp($protectedPrompt, (string) ($context['style'] ?? 'action_reply'));
        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        return [
            'text' => $this->normalizeWhitespace(strtr($text, $replacements)),
            'provider' => 'mcp',
            'metadata' => [
                'protected_terms_count' => count($replacements),
                'style' => $context['style'] ?? 'action_reply',
            ],
        ];
    }

    private function enabled(): bool
    {
        $url = $this->url();

        return $url !== ''
            && (
                (bool) config('ai-agent.action_reply.mcp.enabled', false)
                || (bool) config('ai-agent.humanize.enabled', false)
            );
    }

    private function callMcp(string $text, string $style): ?string
    {
        try {
            $response = Http::timeout(max(1, (int) config('ai-agent.action_reply.mcp.timeout', config('ai-agent.humanize.timeout', 8))))
                ->acceptJson()
                ->post($this->url(), [
                    'jsonrpc' => '2.0',
                    'id' => (string) Str::uuid(),
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $this->toolName(),
                        'arguments' => [
                            'text' => $text,
                            'style' => $style,
                        ],
                    ],
                ]);

            if ($response->failed()) {
                return null;
            }

            return $this->extractMcpText((array) $response->json());
        } catch (Throwable) {
            return null;
        }
    }

    private function extractMcpText(array $payload): ?string
    {
        $result = $payload['result'] ?? $payload;
        if (!is_array($result)) {
            return null;
        }

        if (isset($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $item) {
                if (is_array($item) && is_string($item['text'] ?? null)) {
                    return $item['text'];
                }
            }
        }

        foreach (['text', 'humanized_text', 'output'] as $key) {
            if (is_string($result[$key] ?? null)) {
                return $result[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $explicitTerms
     * @return array{0: string, 1: array<string, string>}
     */
    private function protectTerms(string $text, array $explicitTerms): array
    {
        $terms = array_values(array_unique(array_filter(
            array_merge($explicitTerms, $this->detectedProtectedTerms($text)),
            fn (string $term): bool => trim($term) !== ''
        )));
        usort($terms, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $protected = $text;
        $replacements = [];

        foreach ($terms as $index => $term) {
            if (!str_contains($protected, $term)) {
                continue;
            }

            $token = '__AI_ACTION_REPLY_TOKEN_' . $index . '__';
            $protected = str_replace($term, $token, $protected);
            $replacements[$token] = $term;
        }

        return [$protected, $replacements];
    }

    /**
     * @return array<int, string>
     */
    private function detectedProtectedTerms(string $text): array
    {
        $patterns = [
            '/[A-Z]{2,}-\d{2,}/',
            '/\b[A-Z]{2,}\d{2,}\b/',
            '/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/',
            '/https?:\/\/\S+/',
            '/\b\d{4}-\d{2}-\d{2}\b/',
            '/[$€£]\s?\d[\d,]*(?:\.\d+)?/',
        ];

        $terms = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                $terms = array_merge($terms, $matches[0]);
            }
        }

        return $terms;
    }

    private function url(): string
    {
        $url = config('ai-agent.action_reply.mcp.url');
        if (!is_string($url) || trim($url) === '') {
            $url = config('ai-agent.humanize.mcp_url');
        }

        return is_string($url) ? trim($url) : '';
    }

    private function toolName(): string
    {
        $toolName = config('ai-agent.action_reply.mcp.tool_name');
        if (!is_string($toolName) || trim($toolName) === '') {
            $toolName = config('ai-agent.humanize.tool_name', 'humanize_text');
        }

        return is_string($toolName) && trim($toolName) !== '' ? trim($toolName) : 'humanize_text';
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
