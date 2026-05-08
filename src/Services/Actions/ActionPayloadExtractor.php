<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;

class ActionPayloadExtractor
{
    public function __construct(protected AIEngineService $ai)
    {
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $currentPayload
     * @param array<int, array<string, mixed>> $recentHistory
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function extract(
        array $action,
        string $message,
        array $currentPayload = [],
        array $recentHistory = [],
        array $options = []
    ): ?array {
        if (!$this->configValue('enabled', true)) {
            return null;
        }

        $actionId = (string) ($action['id'] ?? '');
        if ($actionId === '') {
            return null;
        }

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $this->prompt($action, $message, $currentPayload, $recentHistory, $options),
                engine: $options['engine'] ?? $this->configValue('engine', config('ai-engine.default', 'openai')),
                model: $options['model'] ?? $this->configValue('model', config('ai-engine.orchestration_model', config('ai-engine.default_model', 'gpt-4o'))),
                maxTokens: (int) ($options['max_tokens'] ?? $this->configValue('max_tokens', 1400)),
                temperature: (float) ($options['temperature'] ?? $this->configValue('temperature', 0.1)),
                metadata: [
                    'context' => 'action_payload_extraction',
                    'action_id' => $actionId,
                ]
            ));
        } catch (\Throwable $exception) {
            Log::channel('ai-engine')->warning('Action payload extraction failed', [
                'action_id' => $actionId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!$response->isSuccessful()) {
            return null;
        }

        $decoded = $this->decodeJson($response->getContent());
        if (!is_array($decoded)) {
            return null;
        }

        $payload = Arr::get($decoded, 'payload_patch', Arr::get($decoded, 'payload'));
        if (!is_array($payload)) {
            return null;
        }

        return $this->sanitizePayload($payload, $action);
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $currentPayload
     * @param array<int, array<string, mixed>> $recentHistory
     * @param array<string, mixed> $options
     */
    protected function prompt(
        array $action,
        string $message,
        array $currentPayload,
        array $recentHistory,
        array $options
    ): string {
        $parameters = (array) ($action['parameters'] ?? []);
        $instructions = trim((string) ($options['instructions'] ?? ($action['payload_extraction_instructions'] ?? '')));

        return implode("\n", array_filter([
            'ACTION_PAYLOAD_EXTRACTOR',
            'You extract structured payload patches for an action from a natural conversation.',
            'Return JSON only. Do not explain. Do not wrap in markdown.',
            'Use the current draft payload as context. Return only fields the user provided, corrected, or clearly implied in the latest message.',
            'Do not invent IDs, prices, tax, discounts, or relation IDs. If the user gives a relation name, use the available name field.',
            'For array fields, parse natural multi-item phrases into separate objects when the schema supports an array.',
            'For price-only or correction updates, return partial item patches and omit unchanged fields.',
            $instructions,
            'Action ID: ' . ($action['id'] ?? ''),
            'Action label: ' . ($action['label'] ?? ''),
            'Action description: ' . ($action['description'] ?? ''),
            'Required fields: ' . json_encode($action['required'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Parameters schema: ' . json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Current draft payload: ' . json_encode($currentPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Recent conversation: ' . json_encode(array_slice($recentHistory, -6), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Latest user message: ' . $message,
            'JSON shape: {"payload_patch": {"field": "value", "array_field": [{"field": "value"}]}, "confidence": 0.0}',
        ], static fn (string $line): bool => trim($line) !== ''));
    }

    protected function decodeJson(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    protected function sanitizePayload(array $payload, array $action): array
    {
        $allowed = $this->allowedFields((array) ($action['parameters'] ?? []));
        if ($allowed['top'] !== []) {
            $payload = Arr::only($payload, $allowed['top']);
        }

        foreach ($allowed['arrays'] as $arrayField => $itemFields) {
            if (!isset($payload[$arrayField]) || !is_array($payload[$arrayField])) {
                continue;
            }

            $payload[$arrayField] = collect($payload[$arrayField])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => $itemFields === [] ? $item : Arr::only($item, $itemFields))
                ->filter(fn (array $item): bool => $item !== [])
                ->values()
                ->all();
        }

        return $this->compact($payload);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{top: array<int, string>, arrays: array<string, array<int, string>>}
     */
    protected function allowedFields(array $parameters): array
    {
        $top = [];
        $arrays = [];

        foreach (array_keys($parameters) as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if (preg_match('/^([^.*]+)\.\*\.([^.*]+)$/', $field, $matches) === 1) {
                $top[] = $matches[1];
                $arrays[$matches[1]][] = $matches[2];
                continue;
            }

            if (!str_contains($field, '.')) {
                $top[] = $field;
            }
        }

        return [
            'top' => array_values(array_unique($top)),
            'arrays' => array_map(
                static fn (array $fields): array => array_values(array_unique($fields)),
                $arrays
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function compact(array $payload): array
    {
        return array_filter(array_map(function (mixed $value): mixed {
            if (is_array($value)) {
                return $this->compact($value);
            }

            return $value;
        }, $payload), fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function configValue(string $key, mixed $default = null): mixed
    {
        return config(
            "ai-agent.action_payload_extraction.{$key}",
            config("ai-agent.business_action_payload_extraction.{$key}", $default)
        );
    }
}
