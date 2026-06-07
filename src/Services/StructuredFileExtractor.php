<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;

/**
 * Generic, resource-agnostic field extraction: given a document's text and the field names a
 * create action expects, ask the model to extract exactly those fields as JSON — so the
 * result can pre-fill a create_<entity> payload. Driven entirely by the field list (derived
 * from the create tool's own parameters), so adding a new resource needs no extra code.
 *
 * Deterministic to test: the only model call is generateDirect(), which is mocked in CI.
 */
class StructuredFileExtractor
{
    public function __construct(protected ?AIEngineService $ai = null)
    {
        $this->ai = $ai ?? app(AIEngineService::class);
    }

    /**
     * @param array<int, string>   $fields   field names to extract (the create tool's params)
     * @param array<string, mixed> $options  engine/model overrides
     * @return array<string, mixed> extracted values, restricted to $fields
     */
    public function extract(string $content, array $fields, array $options = []): array
    {
        $content = trim($content);
        $fields = array_values(array_unique(array_filter(
            $fields,
            static fn ($f): bool => is_string($f) && $f !== '' && $f !== 'confirmed'
        )));

        if ($content === '' || $fields === []) {
            return [];
        }

        $maxContent = (int) config('ai-engine.file_analysis.max_content_chars', 12000);
        $prompt = "Extract structured data from the DOCUMENT below.\n"
            . 'Return ONLY a JSON object containing these keys when their value is present: '
            . implode(', ', $fields) . ".\n"
            . "Use null (or omit the key) when a value is not in the document. For a list field "
            . "(e.g. items / line items), return an array of objects. Never invent values.\n\n"
            . "DOCUMENT:\n" . mb_substr($content, 0, $maxContent);

        try {
            $response = $this->ai->generateDirect(new AIRequest(
                prompt: $prompt,
                engine: (string) ($options['engine'] ?? config('ai-engine.default', 'openai')),
                model: (string) ($options['model'] ?? config('ai-engine.orchestration_model', config('ai-engine.default_model', 'gpt-4o-mini'))),
                maxTokens: (int) config('ai-engine.file_analysis.extraction_max_tokens', 1500),
                temperature: 0.0,
                metadata: ['context' => 'file_field_extraction']
            ));
        } catch (\Throwable) {
            return [];
        }

        if (!$response->isSuccessful()) {
            return [];
        }

        $data = $this->parseJson($response->getContent());
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $out[$field] = $data[$field];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $content, $m) === 1) {
            $content = trim($m[1]);
        }

        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $m2) === 1) {
            $data = json_decode($m2[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}
