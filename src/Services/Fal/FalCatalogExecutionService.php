<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Repositories\AIModelRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;

class FalCatalogExecutionService
{
    public function __construct(
        private readonly AIModelRepository $models,
        private readonly ProviderToolRunRepository $runs,
        private readonly HostedArtifactService $artifacts
    ) {}

    public function executeRequest(AIRequest $request): AIResponse
    {
        $modelId = $request->getModel()->value;
        $model = $this->models->findActiveByProviderAndModel('fal_ai', $modelId)
            ?? $this->models->findActiveByProviderAndModel('fal', $modelId);

        if ($model === null) {
            throw new \InvalidArgumentException("FAL catalog model {$modelId} was not found in the active model registry.");
        }

        $input = $this->buildPayload($request, $model);
        $run = $this->runs->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'fal_ai',
            'engine' => EngineEnum::FAL_AI,
            'ai_model' => $modelId,
            'status' => 'running',
            'request_id' => $request->getMetadata()['request_id'] ?? null,
            'conversation_id' => $request->getMetadata()['conversation_id'] ?? null,
            'user_id' => $request->getMetadata()['user_id'] ?? null,
            'tool_names' => ['fal_catalog_execute'],
            'request_payload' => config('ai-engine.provider_tools.lifecycle.store_payloads', true) ? $input : [],
            'metadata' => $request->getMetadata(),
            'started_at' => now(),
        ]);

        try {
            $response = Http::timeout((int) config('ai-engine.engines.fal_ai.timeout', 180))
                ->withHeaders($this->headers())
                ->post($this->endpointUrl($modelId), $input);

            if (!$response->successful()) {
                $this->runs->update($run, [
                    'status' => 'failed',
                    'error' => $response->body(),
                    'failed_at' => now(),
                ]);

                return AIResponse::error(
                    'FAL catalog request failed: ' . $response->body(),
                    EngineEnum::FAL_AI,
                    $request->getModel()
                )->withMetadata(['provider_tool_run_id' => $run->uuid]);
            }

            $data = $response->json() ?? [];
            $run = $this->runs->update($run, [
                'status' => 'completed',
                'response_payload' => $data,
                'provider_request_id' => $data['request_id'] ?? $data['id'] ?? null,
                'completed_at' => now(),
            ]);

            $artifactRecords = $this->artifacts->recordFromProviderResponse($run, $data, [
                'executor' => 'fal_catalog',
                'model_provider' => $model->provider,
            ]);
            $files = $this->artifactUrls($artifactRecords);

            return AIResponse::success(
                json_encode($data, JSON_UNESCAPED_SLASHES),
                EngineEnum::FAL_AI,
                $request->getModel()
            )->withFiles($files)->withMetadata([
                'provider_tool_run_id' => $run->uuid,
                'fal_catalog_model' => $modelId,
                'fal_catalog_payload' => $input,
                'hosted_artifacts' => array_map(fn ($artifact): array => $artifact->toArray(), $artifactRecords),
            ]);
        } catch (\Throwable $e) {
            $this->runs->update($run, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            return AIResponse::error(
                'FAL catalog request failed: ' . $e->getMessage(),
                EngineEnum::FAL_AI,
                $request->getModel(),
                ['provider_tool_run_id' => $run->uuid]
            );
        }
    }

    public function submitAsync(AIRequest $request, ?string $webhookUrl = null): array
    {
        $modelId = $request->getModel()->value;
        $model = $this->models->findActiveByProviderAndModel('fal_ai', $modelId)
            ?? $this->models->findActiveByProviderAndModel('fal', $modelId);

        if ($model === null) {
            throw new \InvalidArgumentException("FAL catalog model {$modelId} was not found in the active model registry.");
        }

        $payload = $this->buildPayload($request, $model);
        $run = $this->runs->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'fal_ai',
            'engine' => EngineEnum::FAL_AI,
            'ai_model' => $modelId,
            'status' => 'queued',
            'request_id' => $request->getMetadata()['request_id'] ?? null,
            'conversation_id' => $request->getMetadata()['conversation_id'] ?? null,
            'user_id' => $request->getMetadata()['user_id'] ?? null,
            'tool_names' => ['fal_catalog_queue'],
            'request_payload' => config('ai-engine.provider_tools.lifecycle.store_payloads', true) ? array_merge($payload, ['_endpoint' => $modelId]) : [],
            'metadata' => $request->getMetadata(),
        ]);

        $query = [];
        if (is_string($webhookUrl) && trim($webhookUrl) !== '') {
            $query['fal_webhook'] = trim($webhookUrl);
        }

        $response = Http::timeout((int) config('ai-engine.engines.fal_ai.timeout', 180))
            ->withHeaders($this->headers())
            ->withQueryParameters($query)
            ->post($this->queueEndpointUrl($modelId), $payload);

        if (!$response->successful()) {
            $this->runs->update($run, [
                'status' => 'failed',
                'error' => $response->body(),
                'failed_at' => now(),
            ]);

            throw new \RuntimeException('FAL catalog queue request failed: ' . $response->body());
        }

        $data = $response->json() ?? [];
        $run = $this->runs->update($run, [
            'provider_request_id' => $data['request_id'] ?? $data['id'] ?? null,
            'response_payload' => $data,
            'metadata' => array_merge($run->metadata ?? [], [
                'provider' => [
                    'request_id' => $data['request_id'] ?? null,
                    'gateway_request_id' => $data['gateway_request_id'] ?? null,
                    'status_url' => $data['status_url'] ?? null,
                    'response_url' => $data['response_url'] ?? null,
                    'cancel_url' => $data['cancel_url'] ?? null,
                    'queue_position' => $data['queue_position'] ?? null,
                ],
                'webhook_url' => $webhookUrl,
            ]),
        ]);

        return [
            'provider_tool_run_id' => $run->uuid,
            'request_id' => $data['request_id'] ?? null,
            'gateway_request_id' => $data['gateway_request_id'] ?? null,
            'status_url' => $data['status_url'] ?? null,
            'response_url' => $data['response_url'] ?? null,
            'cancel_url' => $data['cancel_url'] ?? null,
            'queue_position' => $data['queue_position'] ?? null,
            'provider_response' => $data,
        ];
    }

    public function completeQueuedRun(int|string $runId, array $payload): AIResponse
    {
        $run = $this->runs->findOrFail($runId);
        $run = $this->runs->update($run, [
            'status' => 'completed',
            'response_payload' => $payload,
            'provider_request_id' => $payload['request_id'] ?? $run->provider_request_id,
            'completed_at' => now(),
        ]);

        $artifactRecords = $this->artifacts->recordFromProviderResponse($run, $payload, [
            'executor' => 'fal_catalog_queue',
        ]);

        return AIResponse::success(
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            EngineEnum::FAL_AI,
            $run->ai_model
        )->withFiles($this->artifactUrls($artifactRecords))->withMetadata([
            'provider_tool_run_id' => $run->uuid,
            'hosted_artifacts' => array_map(fn ($artifact): array => $artifact->toArray(), $artifactRecords),
        ]);
    }

    private function buildPayload(AIRequest $request, AIModel $model): array
    {
        $parameters = $request->getParameters();
        $payload = $parameters['input'] ?? $parameters;

        if (!isset($payload['prompt']) && trim($request->getPrompt()) !== '') {
            $promptKey = $this->promptKey($model);
            $payload[$promptKey] = $request->getPrompt();
        }

        unset($payload['input']);

        $this->validatePayloadAgainstSchema($payload, $model);

        return $payload;
    }

    private function promptKey(AIModel $model): string
    {
        $schema = $model->metadata['schema'] ?? $model->metadata['input_schema'] ?? [];
        $properties = $schema['properties'] ?? $schema['schema']['properties'] ?? [];

        foreach (['prompt', 'text', 'input'] as $key) {
            if (array_key_exists($key, $properties)) {
                return $key;
            }
        }

        return 'prompt';
    }

    private function validatePayloadAgainstSchema(array $payload, AIModel $model): void
    {
        if ((bool) config('ai-engine.engines.fal_ai.catalog_execution.validate_schema', true) !== true) {
            return;
        }

        $schema = $model->metadata['schema'] ?? $model->metadata['input_schema'] ?? [];
        $required = $schema['required'] ?? $schema['schema']['required'] ?? [];
        if (!is_array($required) || $required === []) {
            return;
        }

        $missing = array_values(array_filter(
            array_map('strval', $required),
            static fn (string $field): bool => !array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === ''
        ));

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'FAL catalog model %s is missing required input field(s): %s.',
                $model->model_id,
                implode(', ', $missing)
            ));
        }
    }

    private function endpointUrl(string $modelId): string
    {
        $baseUrl = rtrim((string) config('ai-engine.engines.fal_ai.base_url', 'https://fal.run'), '/');

        return $baseUrl . '/' . ltrim($modelId, '/');
    }

    private function queueEndpointUrl(string $modelId): string
    {
        $baseUrl = rtrim((string) config('ai-engine.engines.fal_ai.queue_base_url', 'https://queue.fal.run'), '/');

        return $baseUrl . '/' . ltrim($modelId, '/');
    }

    private function headers(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];

        $apiKey = (string) config('ai-engine.engines.fal_ai.api_key', '');
        if ($apiKey !== '') {
            $headers['Authorization'] = str_starts_with($apiKey, 'Key ') ? $apiKey : 'Key ' . $apiKey;
        }

        return $headers;
    }

    private function artifactUrls(array $artifacts): array
    {
        return array_values(array_filter(array_map(
            static fn ($artifact): ?string => $artifact->download_url ?? $artifact->source_url ?? $artifact->citation_url,
            $artifacts
        )));
    }
}
