<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;

/**
 * Runs a single prompt across several models ("council") and returns each
 * model's response side-by-side for comparison. Each member is isolated: one
 * member failing (provider error, bad model, exception) does not abort the rest.
 *
 * Members run sequentially; the value of a council is comparison, not latency.
 */
class ModelCouncilService
{
    public function __construct(
        protected AIEngineService $ai,
    ) {}

    /**
     * @param array<int, array{engine?: string|null, model: string, system_prompt?: string|null}> $members
     * @param array<string, mixed> $options engine-wide defaults: system_prompt, temperature, max_tokens
     * @return array<int, array<string, mixed>> one result row per member
     */
    public function run(string $prompt, array $members, array $options = [], ?string $userId = null): array
    {
        $results = [];

        foreach ($members as $member) {
            $model = is_array($member) ? trim((string) ($member['model'] ?? '')) : '';

            if ($model === '') {
                continue;
            }

            $engine = $member['engine'] ?? null;
            $engine = is_string($engine) && trim($engine) !== '' ? trim($engine) : null;
            $systemPrompt = $member['system_prompt'] ?? ($options['system_prompt'] ?? null);

            $results[] = $this->runMember($prompt, $engine, $model, $systemPrompt, $options, $userId);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function runMember(
        string $prompt,
        ?string $engine,
        string $model,
        ?string $systemPrompt,
        array $options,
        ?string $userId
    ): array {
        try {
            $request = new AIRequest(
                prompt: $prompt,
                engine: $engine,
                model: $model,
                userId: $userId,
                systemPrompt: is_string($systemPrompt) && trim($systemPrompt) !== '' ? $systemPrompt : null,
                maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
                temperature: isset($options['temperature']) ? (float) $options['temperature'] : null,
            );

            $response = $this->ai->generateText($request);
            $error = $response->getError();

            return [
                'engine' => $response->getEngine()->value,
                'model' => $response->getModel()->value,
                'success' => $error === null,
                'content' => $response->getContent(),
                'error' => $error,
                'usage' => $response->getUsage(),
                'credits_used' => $response->getCreditsUsed(),
                'metadata' => $response->getMetadata(),
            ];
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Model council member failed', [
                'engine' => $engine,
                'model' => $model,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return [
                'engine' => $engine,
                'model' => $model,
                'success' => false,
                'content' => '',
                'error' => $e->getMessage(),
                'usage' => null,
                'credits_used' => 0.0,
                'metadata' => [],
            ];
        }
    }
}
