<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

use Illuminate\Support\Str;
use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\JobStatusTracker;

class FalAsyncVideoService
{
    public function __construct(
        private FalMediaWorkflowService $mediaWorkflowService,
        private DriverRegistry $driverRegistry,
        private JobStatusTracker $jobStatusTracker,
        private CreditManager $creditManager
    ) {}

    public function prepareRequest(string $prompt = '', array $options = [], ?string $userId = null): AIRequest
    {
        $request = $this->mediaWorkflowService->prepareRequest($prompt, $options, $userId);

        if ($request->getContentType() !== 'video') {
            throw new AIEngineException('Async FAL workflow only supports video models.');
        }

        if ($request->getEngine()->value !== EngineEnum::FAL_AI) {
            throw new AIEngineException('Async media workflow is only supported for the FAL engine.');
        }

        return $request;
    }

    public function submit(string $prompt = '', array $options = [], ?string $userId = null): array
    {
        $request = $this->prepareRequest($prompt, $options, $userId);
        $driver = $this->resolveDriver();
        $driver->validateRequest($request);

        $jobId = (string) Str::uuid();
        $webhookToken = Str::random(40);
        $webhookUrl = $this->buildWebhookUrl($jobId, $webhookToken, $options);
        $creditsEnabled = $this->shouldProcessCredits() && config('ai-engine.credits.enabled', false) && $request->getUserId();
        $creditsUsed = $this->creditManager->calculateCredits($request);

        if ($creditsEnabled && !$this->creditManager->hasCredits($request->getUserId(), $request)) {
            throw new InsufficientCreditsException('Insufficient credits for this request');
        }

        $submission = $driver->submitVideoAsync($request, $webhookUrl);

        if ($creditsEnabled) {
            $this->creditManager->deductCredits($request->getUserId(), $request, $creditsUsed);
        }

        $metadata = array_filter([
            'engine' => $request->getEngine()->value,
            'model' => $request->getModel()->value,
            'content_type' => $request->getContentType(),
            'request' => $this->serializeRequest($request),
            'provider' => [
                'name' => 'fal',
                'request_id' => $submission['request_id'] ?? null,
                'gateway_request_id' => $submission['gateway_request_id'] ?? null,
                'status_url' => $submission['status_url'] ?? null,
                'response_url' => $submission['response_url'] ?? null,
                'cancel_url' => $submission['cancel_url'] ?? null,
                'queue_position' => $submission['queue_position'] ?? null,
            ],
            'operation' => $submission['operation'] ?? [],
            'webhook' => [
                'url' => $webhookUrl,
                'token' => $webhookToken,
                'enabled' => $webhookUrl !== null,
            ],
            'credits' => [
                'charged' => (bool) $creditsEnabled,
                'amount' => $creditsEnabled ? $creditsUsed : 0.0,
            ],
        ], static fn ($value): bool => $value !== null);

        $this->jobStatusTracker->updateStatus($jobId, 'queued', $metadata);

        return [
            'job_id' => $jobId,
            'status' => $this->toPublicStatus($this->jobStatusTracker->getStatus($jobId)),
            'request' => $request,
            'webhook_url' => $webhookUrl,
        ];
    }

    public function getStatus(string $jobId, bool $refresh = false): ?array
    {
        $status = $this->jobStatusTracker->getStatus($jobId);

        if ($status === null) {
            return null;
        }

        if ($refresh && !in_array($status['status'], ['completed', 'failed', 'cancelled'], true)) {
            $status = $this->refresh($jobId);
        }

        return $this->toPublicStatus($status);
    }

    public function refresh(string $jobId): array
    {
        $status = $this->jobStatusTracker->getStatus($jobId);
        if ($status === null) {
            throw new AIEngineException("Async video job [{$jobId}] was not found.");
        }

        if (in_array($status['status'], ['completed', 'failed', 'cancelled'], true)) {
            return $status;
        }

        $metadata = is_array($status['metadata'] ?? null) ? $status['metadata'] : [];
        $provider = is_array($metadata['provider'] ?? null) ? $metadata['provider'] : [];
        $statusUrl = $provider['status_url'] ?? null;
        if (!is_string($statusUrl) || trim($statusUrl) === '') {
            throw new AIEngineException("Async video job [{$jobId}] is missing a provider status URL.");
        }

        $driver = $this->resolveDriver();
        $providerStatus = $driver->getAsyncStatus($statusUrl, true);
        $queueStatus = strtoupper((string) ($providerStatus['status'] ?? 'IN_QUEUE'));

        $metadata['provider'] = array_merge($provider, [
            'status' => $queueStatus,
            'queue_position' => $providerStatus['queue_position'] ?? ($provider['queue_position'] ?? null),
            'response_url' => $providerStatus['response_url'] ?? ($provider['response_url'] ?? null),
            'logs' => $providerStatus['logs'] ?? ($provider['logs'] ?? []),
            'metrics' => $providerStatus['metrics'] ?? ($provider['metrics'] ?? []),
            'error' => $providerStatus['error'] ?? null,
            'error_type' => $providerStatus['error_type'] ?? null,
        ]);

        if ($queueStatus === 'COMPLETED' && !empty($providerStatus['error'])) {
            $this->jobStatusTracker->updateStatus($jobId, 'failed', $metadata);

            return $this->jobStatusTracker->getStatus($jobId);
        }

        if ($queueStatus === 'COMPLETED') {
            return $this->finalizeFromResponseUrl($jobId, $metadata);
        }

        $this->jobStatusTracker->updateStatus($jobId, $this->mapQueueStatus($queueStatus), $metadata);

        return $this->jobStatusTracker->getStatus($jobId);
    }

    public function waitForCompletion(string $jobId, int $timeoutSeconds = 180, int $pollIntervalSeconds = 5): array
    {
        $deadline = time() + max(1, $timeoutSeconds);
        $interval = max(1, $pollIntervalSeconds);

        do {
            $status = $this->refresh($jobId);

            if (in_array($status['status'], ['completed', 'failed', 'cancelled'], true)) {
                return $status;
            }

            sleep($interval);
        } while (time() < $deadline);

        throw new AIEngineException("Async video job [{$jobId}] did not complete within {$timeoutSeconds} seconds.");
    }

    public function toPublicStatus(?array $status): ?array
    {
        if ($status === null) {
            return null;
        }

        $public = $status;
        unset($public['metadata']['webhook']['token']);

        return $public;
    }

    public function handleWebhook(string $jobId, string $token, array $payload): array
    {
        $status = $this->jobStatusTracker->getStatus($jobId);
        if ($status === null) {
            throw new AIEngineException("Async video job [{$jobId}] was not found.");
        }

        $metadata = is_array($status['metadata'] ?? null) ? $status['metadata'] : [];
        $expectedToken = $metadata['webhook']['token'] ?? null;

        if (!is_string($expectedToken) || !hash_equals($expectedToken, $token)) {
            throw new AIEngineException('Invalid async video webhook token.');
        }

        $webhookStatus = strtoupper((string) ($payload['status'] ?? 'ERROR'));
        $provider = is_array($metadata['provider'] ?? null) ? $metadata['provider'] : [];
        $metadata['provider'] = array_merge($provider, [
            'webhook_status' => $webhookStatus,
            'request_id' => $payload['request_id'] ?? ($provider['request_id'] ?? null),
            'gateway_request_id' => $payload['gateway_request_id'] ?? ($provider['gateway_request_id'] ?? null),
        ]);

        if ($webhookStatus !== 'OK') {
            $metadata['provider']['error'] = $payload['error'] ?? 'FAL async job failed.';
            $metadata['provider']['payload'] = $payload['payload'] ?? null;
            $this->jobStatusTracker->updateStatus($jobId, 'failed', $metadata);

            return $this->jobStatusTracker->getStatus($jobId);
        }

        if (!is_array($payload['payload'] ?? null) || $payload['payload'] === []) {
            $metadata['provider']['payload_error'] = $payload['payload_error'] ?? null;

            return $this->finalizeFromResponseUrl($jobId, $metadata);
        }

        return $this->finalize($jobId, $metadata, $payload['payload']);
    }

    private function finalizeFromResponseUrl(string $jobId, array $metadata): array
    {
        $responseUrl = $metadata['provider']['response_url'] ?? null;
        if (!is_string($responseUrl) || trim($responseUrl) === '') {
            throw new AIEngineException("Async video job [{$jobId}] is missing a provider response URL.");
        }

        $driver = $this->resolveDriver();
        $payload = $driver->getAsyncResult($responseUrl);

        return $this->finalize($jobId, $metadata, $payload);
    }

    private function finalize(string $jobId, array $metadata, array $payload): array
    {
        $request = $this->rebuildRequest($metadata['request'] ?? []);
        $operation = is_array($metadata['operation'] ?? null) ? $metadata['operation'] : [];
        $driver = $this->resolveDriver();
        $response = $driver->buildVideoResponseFromOperation($request, $operation, $payload);

        $metadata['provider']['payload'] = $payload;
        $metadata['response'] = $this->serializeResponse($response);
        $this->jobStatusTracker->updateStatus($jobId, 'completed', $metadata);

        return $this->jobStatusTracker->getStatus($jobId);
    }

    private function rebuildRequest(array $request): AIRequest
    {
        return new AIRequest(
            prompt: (string) ($request['prompt'] ?? ''),
            engine: (string) ($request['engine'] ?? EngineEnum::FAL_AI),
            model: (string) ($request['model'] ?? ''),
            parameters: is_array($request['parameters'] ?? null) ? $request['parameters'] : []
        );
    }

    private function serializeRequest(AIRequest $request): array
    {
        return [
            'prompt' => $request->getPrompt(),
            'engine' => $request->getEngine()->value,
            'model' => $request->getModel()->value,
            'parameters' => $request->getParameters(),
        ];
    }

    private function serializeResponse(AIResponse $response): array
    {
        return [
            'content' => $response->getContent(),
            'files' => $response->getFiles(),
            'usage' => $response->getUsage(),
            'metadata' => $response->getMetadata(),
            'engine' => $response->getEngine()->value,
            'model' => $response->getModel()->value,
        ];
    }

    private function buildWebhookUrl(string $jobId, string $token, array $options): ?string
    {
        if (($options['use_webhook'] ?? true) !== true) {
            return null;
        }

        $configured = config('ai-engine.engines.fal_ai.async.webhook_url');
        if (is_string($configured) && trim($configured) !== '') {
            return $this->appendQuery(trim($configured), [
                'job_id' => $jobId,
                'token' => $token,
            ]);
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl === '') {
            return null;
        }

        $prefix = trim((string) config('ai-engine.api.generate.prefix', 'api/v1/ai/generate'), '/');

        return $this->appendQuery($appUrl . '/' . $prefix . '/video/fal/webhook', [
            'job_id' => $jobId,
            'token' => $token,
        ]);
    }

    private function appendQuery(string $url, array $query): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }

    private function mapQueueStatus(string $queueStatus): string
    {
        return match ($queueStatus) {
            'IN_PROGRESS' => 'processing',
            'COMPLETED' => 'completed',
            default => 'queued',
        };
    }

    private function shouldProcessCredits(): bool
    {
        if (!config('ai-engine.nodes.enabled', false)) {
            return true;
        }

        return config('ai-engine.nodes.is_master', true);
    }

    private function resolveDriver(): FalAIEngineDriver
    {
        $driver = $this->driverRegistry->resolve(EngineEnum::FAL_AI);

        if (!$driver instanceof FalAIEngineDriver) {
            throw new AIEngineException('FAL async video service requires the concrete FAL engine driver.');
        }

        return $driver;
    }
}
