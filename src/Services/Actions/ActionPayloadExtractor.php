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
        $fallback = $this->inferBareValuePatch($message, $action, $currentPayload);

        if (!$this->configValue('enabled', true)) {
            return $fallback !== [] ? $fallback : null;
        }

        $actionId = (string) ($action['id'] ?? '');
        if ($actionId === '') {
            return $fallback !== [] ? $fallback : null;
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

            return $fallback !== [] ? $fallback : null;
        }

        if (!$response->isSuccessful()) {
            return $fallback !== [] ? $fallback : null;
        }

        $decoded = $this->decodeJson($response->getContent());
        if (!is_array($decoded)) {
            return $fallback !== [] ? $fallback : null;
        }

        $payload = Arr::get($decoded, 'payload_patch', Arr::get($decoded, 'payload'));
        if (!is_array($payload)) {
            return $fallback !== [] ? $fallback : null;
        }

        $payload = $this->normalizeNumericDateValues(
            $this->guardRelationApprovalPatch(
                $this->sanitizePayload($payload, $action),
                $message
            ),
            $action,
            $message
        );

        return $payload !== []
            ? array_replace($fallback, $payload)
            : $fallback;
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
            'If the latest message is a bare email, phone, date, number, name, SKU, or short value, use the current draft and schema to place it into the most relevant missing parameter field.',
            'If the user says "change date" and the schema has both a general date field and a due/end date field, update the general date unless the user explicitly says due, end, deadline, or expiry.',
            'Interpret numeric dates using this date order unless the user is explicit: ' . $this->numericDateOrder() . '.',
            'If a related record name is already present and the user sends only an email, assign that email to the matching related email parameter rather than asking for the name again.',
            'For array fields, parse natural multi-item phrases into separate objects when the schema supports an array.',
            'For array fields, use _array_ops when the latest message means append, prepend, update, or remove rather than replace.',
            'Examples: "add iPhone" => {"_array_ops":[{"op":"append","path":"items","value":{"product_name":"iPhone","quantity":1}}]}; "also add" means append; "replace items with" means a normal array field patch; "remove Macbook" means op remove with match.',
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
            'JSON shape: {"payload_patch": {"field": "value", "array_field": [{"field": "value"}], "_array_ops": [{"op": "append|prepend|update|remove|replace", "path": "array_field", "value": {"field": "value"}, "match": {"field": "value"}, "index": 0}]}, "confidence": 0.0}',
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
            $payload = Arr::only($payload, array_merge($allowed['top'], ['_array_ops']));
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

        if (isset($payload['_array_ops']) && is_array($payload['_array_ops'])) {
            $payload['_array_ops'] = $this->sanitizeArrayOperations($payload['_array_ops'], $allowed['arrays']);
        }

        return $this->compact($payload);
    }

    /**
     * @param array<int, mixed> $operations
     * @param array<string, array<int, string>> $allowedArrays
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeArrayOperations(array $operations, array $allowedArrays): array
    {
        $allowedOps = ['append', 'add', 'prepend', 'update', 'remove', 'delete', 'replace'];

        return collect($operations)
            ->filter(fn (mixed $operation): bool => is_array($operation))
            ->map(function (array $operation) use ($allowedArrays, $allowedOps): array {
                $op = strtolower(trim((string) ($operation['op'] ?? '')));
                $path = trim((string) ($operation['path'] ?? ''));
                if (!in_array($op, $allowedOps, true) || $path === '' || !array_key_exists($path, $allowedArrays)) {
                    return [];
                }

                $itemFields = $allowedArrays[$path];
                $sanitized = [
                    'op' => $op,
                    'path' => $path,
                ];

                if (isset($operation['index']) && is_numeric($operation['index'])) {
                    $sanitized['index'] = max(0, (int) $operation['index']);
                }

                if (isset($operation['match']) && is_array($operation['match'])) {
                    $sanitized['match'] = $itemFields === []
                        ? $operation['match']
                        : Arr::only($operation['match'], $itemFields);
                }

                if (array_key_exists('value', $operation)) {
                    $sanitized['value'] = is_array($operation['value']) && $itemFields !== []
                        ? Arr::only($operation['value'], $itemFields)
                        : $operation['value'];
                }

                if (isset($operation['values']) && is_array($operation['values']) && array_is_list($operation['values'])) {
                    $sanitized['values'] = collect($operation['values'])
                        ->map(fn (mixed $value): mixed => is_array($value) && $itemFields !== [] ? Arr::only($value, $itemFields) : $value)
                        ->filter(fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [])
                        ->values()
                        ->all();
                }

                return $sanitized;
            })
            ->filter(fn (array $operation): bool => $operation !== [])
            ->values()
            ->all();
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
     * @param array<string, mixed> $action
     * @param array<string, mixed> $currentPayload
     * @return array<string, mixed>
     */
    protected function inferBareValuePatch(string $message, array $action, array $currentPayload): array
    {
        $message = trim($message);
        if ($message === '') {
            return [];
        }

        if (filter_var($message, FILTER_VALIDATE_EMAIL)) {
            return $this->inferBareEmailPatch($message, (array) ($action['parameters'] ?? []), $currentPayload);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function guardRelationApprovalPatch(array $payload, string $message): array
    {
        if (!array_key_exists('approved_missing_relations', $payload) || $this->looksLikeApproval($message)) {
            return $payload;
        }

        unset($payload['approved_missing_relations']);

        return $payload;
    }

    protected function looksLikeApproval(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\b(no|not|don\'t|do not|cancel|stop|instead)\b/u', $normalized) === 1) {
            return false;
        }

        return preg_match('/\b(yes|approve|approved|confirm|create|add|go ahead|proceed|ok|okay|sure)\b/u', $normalized) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    protected function normalizeNumericDateValues(array $payload, array $action, string $message): array
    {
        $date = $this->dateFromMessage($message);
        if ($date === null) {
            return $payload;
        }

        foreach ($this->dateFields((array) ($action['parameters'] ?? [])) as $field) {
            if (data_get($payload, $field) === null) {
                continue;
            }

            data_set($payload, $field, $date);
        }

        return $payload;
    }

    protected function dateFromMessage(string $message): ?string
    {
        if (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})\b/u', $message, $matches) !== 1) {
            return null;
        }

        $first = (int) $matches[1];
        $second = (int) $matches[2];
        $year = (int) $matches[3];
        $order = $this->numericDateOrder();

        [$day, $month] = $order === 'mdy'
            ? [$second, $first]
            : [$first, $second];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<int, string>
     */
    protected function dateFields(array $parameters): array
    {
        $fields = [];
        foreach ($parameters as $field => $schema) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $type = is_array($schema) ? strtolower((string) ($schema['type'] ?? '')) : '';
            $normalized = strtolower($field);
            if ($type === 'date' || str_ends_with($normalized, '_date') || $normalized === 'date') {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    protected function numericDateOrder(): string
    {
        $order = strtolower((string) $this->configValue('numeric_date_order', 'dmy'));

        return in_array($order, ['dmy', 'mdy'], true) ? $order : 'dmy';
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $currentPayload
     * @return array<string, mixed>
     */
    protected function inferBareEmailPatch(string $email, array $parameters, array $currentPayload): array
    {
        $candidates = [];

        foreach ($parameters as $field => $schema) {
            if (!is_string($field) || str_contains($field, '.') || data_get($currentPayload, $field) !== null) {
                continue;
            }

            $type = is_array($schema) ? strtolower((string) ($schema['type'] ?? '')) : '';
            if ($type !== 'email' && !str_contains(strtolower($field), 'email')) {
                continue;
            }

            $prefix = preg_replace('/_?email$/i', '', $field) ?: $field;
            $score = 1;
            if (is_string($prefix) && $prefix !== $field) {
                foreach ([$prefix . '_name', $prefix . '_id', $prefix] as $relatedField) {
                    if (data_get($currentPayload, $relatedField) !== null) {
                        $score += 10;
                    }
                }
            }

            $candidates[] = ['field' => $field, 'score' => $score];
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return [(string) $candidates[0]['field'] => $email];
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
        return config("ai-agent.action_payload_extraction.{$key}", $default);
    }
}
