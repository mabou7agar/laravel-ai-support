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

class FalAsyncReferencePackGenerationService
{
    public function __construct(
        private FalReferencePackGenerationService $referencePackGenerationService,
        private DriverRegistry $driverRegistry,
        private JobStatusTracker $jobStatusTracker,
        private CreditManager $creditManager
    ) {}

    public function submit(string $prompt, array $options = [], ?string $userId = null): array
    {
        $resolvedUserId = $this->resolveUserId($userId, $options);
        $workflow = $this->referencePackGenerationService->prepareWorkflow($prompt, $options, $resolvedUserId);

        if ($workflow === []) {
            throw new AIEngineException('Reference pack workflow is empty.');
        }

        $jobId = (string) Str::uuid();
        $webhookToken = Str::random(40);
        $webhookUrl = $this->buildWebhookUrl($jobId, $webhookToken, $options);
        $metadata = [
            'type' => 'fal_reference_pack',
            'prompt' => $prompt,
            'user_id' => $resolvedUserId,
            'options' => $options,
            'workflow' => $workflow,
            'total_steps' => count($workflow),
            'generated_images' => $this->referencePackGenerationService->initializeGeneratedImages($options),
            'credits' => [
                'charged_steps' => [],
                'total_charged' => 0.0,
            ],
            'webhook' => [
                'url' => $webhookUrl,
                'token' => $webhookToken,
                'enabled' => $webhookUrl !== null,
            ],
            'steps' => [],
            'progress_percentage' => 0,
            'progress_message' => 'Queued reference pack workflow.',
        ];
        $metadata = $this->mergeWorkflowSummary($metadata);

        $status = $this->submitStep($jobId, $metadata, 0);

        return [
            'job_id' => $jobId,
            'status' => $this->toPublicStatus($status),
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
            throw new AIEngineException("Reference pack job [{$jobId}] was not found.");
        }

        if (in_array($status['status'], ['completed', 'failed', 'cancelled'], true)) {
            return $status;
        }

        $metadata = is_array($status['metadata'] ?? null) ? $status['metadata'] : [];
        $metadata = $this->mergeWorkflowSummary($metadata);
        $provider = is_array($metadata['provider'] ?? null) ? $metadata['provider'] : [];
        $statusUrl = $provider['status_url'] ?? null;
        if (!is_string($statusUrl) || trim($statusUrl) === '') {
            throw new AIEngineException("Reference pack job [{$jobId}] is missing a provider status URL.");
        }

        try {
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
                $fallbackStatus = $this->retryCurrentStepWithProviderFallback($jobId, $metadata, (string) $providerStatus['error']);

                return $fallbackStatus ?? $this->failJob(
                    $jobId,
                    $metadata,
                    (string) $providerStatus['error']
                );
            }

            if ($queueStatus === 'COMPLETED') {
                return $this->finalizeCurrentStepFromResponseUrl($jobId, $metadata);
            }

            $statusName = $this->mapQueueStatus($queueStatus);
            $metadata = $this->updateCurrentStepStatus($metadata, $statusName, [
                'provider' => $metadata['provider'],
            ]);
            $this->jobStatusTracker->updateStatus($jobId, $statusName, $metadata);

            return $this->jobStatusTracker->getStatus($jobId);
        } catch (\Throwable $e) {
            $fallbackStatus = $this->retryCurrentStepWithProviderFallback($jobId, $metadata, $e->getMessage());

            return $fallbackStatus ?? $this->failJob($jobId, $metadata, $e->getMessage());
        }
    }

    public function waitForCompletion(string $jobId, int $timeoutSeconds = 900, int $pollIntervalSeconds = 5): array
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

        throw new AIEngineException("Reference pack job [{$jobId}] did not complete within {$timeoutSeconds} seconds.");
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
            throw new AIEngineException("Reference pack job [{$jobId}] was not found.");
        }

        if (in_array($status['status'], ['completed', 'failed', 'cancelled'], true)) {
            return $status;
        }

        $metadata = is_array($status['metadata'] ?? null) ? $status['metadata'] : [];
        $metadata = $this->mergeWorkflowSummary($metadata);
        $expectedToken = $metadata['webhook']['token'] ?? null;

        if (!is_string($expectedToken) || !hash_equals($expectedToken, $token)) {
            throw new AIEngineException('Invalid async reference pack webhook token.');
        }

        $webhookStatus = strtoupper((string) ($payload['status'] ?? 'ERROR'));
        $provider = is_array($metadata['provider'] ?? null) ? $metadata['provider'] : [];
        $metadata['provider'] = array_merge($provider, [
            'webhook_status' => $webhookStatus,
            'request_id' => $payload['request_id'] ?? ($provider['request_id'] ?? null),
            'gateway_request_id' => $payload['gateway_request_id'] ?? ($provider['gateway_request_id'] ?? null),
        ]);

        if ($webhookStatus !== 'OK') {
            $metadata['provider']['error'] = $payload['error'] ?? 'FAL async image job failed.';
            $metadata['provider']['payload'] = $payload['payload'] ?? null;

            $error = (string) ($metadata['provider']['error'] ?? 'FAL async image job failed.');
            $fallbackStatus = $this->retryCurrentStepWithProviderFallback($jobId, $metadata, $error);

            return $fallbackStatus ?? $this->failJob(
                $jobId,
                $metadata,
                $error
            );
        }

        try {
            if (!is_array($payload['payload'] ?? null) || $payload['payload'] === []) {
                $metadata['provider']['payload_error'] = $payload['payload_error'] ?? null;

                return $this->finalizeCurrentStepFromResponseUrl($jobId, $metadata);
            }

            return $this->finalizeCurrentStep($jobId, $metadata, $payload['payload']);
        } catch (\Throwable $e) {
            $fallbackStatus = $this->retryCurrentStepWithProviderFallback($jobId, $metadata, $e->getMessage());

            return $fallbackStatus ?? $this->failJob($jobId, $metadata, $e->getMessage());
        }
    }

    private function submitStep(string $jobId, array $metadata, int $stepIndex, string $urlStrategy = 'stored', bool $skipCreditCharge = false): array
    {
        $metadata = $this->mergeWorkflowSummary($metadata);
        $workflow = is_array($metadata['workflow'] ?? null) ? $metadata['workflow'] : [];
        $step = $workflow[$stepIndex] ?? null;
        if (!is_array($step)) {
            throw new AIEngineException("Reference pack step [{$stepIndex}] was not found.");
        }

        $generatedImages = is_array($metadata['generated_images'] ?? null) ? $metadata['generated_images'] : [];
        $prompt = (string) ($metadata['prompt'] ?? '');
        $options = is_array($metadata['options'] ?? null) ? $metadata['options'] : [];
        $resolvedUserId = is_string($metadata['user_id'] ?? null) ? $metadata['user_id'] : null;
        $request = $urlStrategy === 'provider'
            ? $this->referencePackGenerationService->prepareStepRequest($prompt, $options, $resolvedUserId, $step, $generatedImages, 'provider')
            : $this->referencePackGenerationService->prepareStepRequest($prompt, $options, $resolvedUserId, $step, $generatedImages);

        $driver = $this->resolveDriver();
        $driver->validateRequest($request);

        $credits = $this->chargeStepCredits($request, $stepIndex + 1, $metadata, $skipCreditCharge);
        $submission = $driver->submitImageAsync(
            $request,
            isset($metadata['webhook']['url']) && is_string($metadata['webhook']['url']) ? $metadata['webhook']['url'] : null
        );

        $metadata['current_step_index'] = $stepIndex;
        $metadata['current_step'] = $step['step'] ?? ($stepIndex + 1);
        $metadata['current_step_label'] = $step['label'] ?? ('Step ' . ($stepIndex + 1));
        $metadata['current_url_strategy'] = $urlStrategy;
        $metadata['current_request'] = $this->serializeRequest($request);
        $metadata['operation'] = $submission['operation'] ?? [];
        $metadata['provider'] = array_filter([
            'name' => 'fal',
            'request_id' => $submission['request_id'] ?? null,
            'gateway_request_id' => $submission['gateway_request_id'] ?? null,
            'status_url' => $submission['status_url'] ?? null,
            'response_url' => $submission['response_url'] ?? null,
            'cancel_url' => $submission['cancel_url'] ?? null,
            'queue_position' => $submission['queue_position'] ?? null,
        ], static fn ($value): bool => $value !== null);
        $metadata['progress_percentage'] = (int) floor(($stepIndex / max(1, (int) ($metadata['total_steps'] ?? 1))) * 100);
        $metadata['progress_message'] = 'Generating ' . ($step['label'] ?? ('step ' . ($stepIndex + 1)));
        $metadata['steps'][$stepIndex] = array_merge(
            is_array($metadata['steps'][$stepIndex] ?? null) ? $metadata['steps'][$stepIndex] : [],
            [
                'step' => $step['step'] ?? ($stepIndex + 1),
                'label' => $step['label'] ?? ('Step ' . ($stepIndex + 1)),
                'status' => 'queued',
                'url_strategy' => $urlStrategy,
                'request' => $metadata['current_request'],
                'provider' => $metadata['provider'],
                'credits' => $credits,
                'submitted_at' => now()->toISOString(),
            ]
        );

        $this->jobStatusTracker->updateStatus($jobId, $stepIndex === 0 ? 'queued' : 'processing', $metadata);

        return $this->jobStatusTracker->getStatus($jobId);
    }

    private function finalizeCurrentStepFromResponseUrl(string $jobId, array $metadata): array
    {
        $responseUrl = $metadata['provider']['response_url'] ?? null;
        if (!is_string($responseUrl) || trim($responseUrl) === '') {
            throw new AIEngineException("Reference pack job [{$jobId}] is missing a provider response URL.");
        }

        $payload = $this->resolveDriver()->getAsyncResult($responseUrl);

        return $this->finalizeCurrentStep($jobId, $metadata, $payload);
    }

    private function finalizeCurrentStep(string $jobId, array $metadata, array $payload): array
    {
        $metadata = $this->mergeWorkflowSummary($metadata);
        $workflow = is_array($metadata['workflow'] ?? null) ? $metadata['workflow'] : [];
        $stepIndex = (int) ($metadata['current_step_index'] ?? 0);
        $step = $workflow[$stepIndex] ?? null;
        if (!is_array($step)) {
            throw new AIEngineException("Reference pack step [{$stepIndex}] was not found.");
        }

        $request = $this->rebuildRequest(is_array($metadata['current_request'] ?? null) ? $metadata['current_request'] : []);
        $operation = is_array($metadata['operation'] ?? null) ? $metadata['operation'] : [];
        $driver = $this->resolveDriver();
        $response = $driver->buildImageResponseFromOperation($request, $operation, $payload);
        $image = $this->referencePackGenerationService->extractGeneratedImageRecord($response, $step, $stepIndex + 1);
        $generatedImages = is_array($metadata['generated_images'] ?? null) ? $metadata['generated_images'] : [];
        $generatedImages[] = $image;
        $metadata['generated_images'] = $generatedImages;
        $metadata['provider']['payload'] = $payload;
        $metadata['steps'][$stepIndex] = array_merge(
            is_array($metadata['steps'][$stepIndex] ?? null) ? $metadata['steps'][$stepIndex] : [],
            [
                'status' => 'completed',
                'response' => $this->serializeResponse($response),
                'image' => $image,
                'completed_at' => now()->toISOString(),
            ]
        );

        $completedSteps = $stepIndex + 1;
        $totalSteps = max(1, (int) ($metadata['total_steps'] ?? count($workflow)));
        $metadata['progress_percentage'] = (int) floor(($completedSteps / $totalSteps) * 100);
        $metadata['progress_message'] = 'Completed ' . ($step['label'] ?? ('step ' . $completedSteps));

        if ($completedSteps >= count($workflow)) {
            return $this->completeWorkflow($jobId, $metadata);
        }

        return $this->submitStep($jobId, $metadata, $completedSteps);
    }

    private function completeWorkflow(string $jobId, array $metadata): array
    {
        $metadata = $this->mergeWorkflowSummary($metadata);
        $result = $this->referencePackGenerationService->finalizeStoredResult(
            is_array($metadata['generated_images'] ?? null) ? $metadata['generated_images'] : [],
            is_array($metadata['options'] ?? null) ? $metadata['options'] : [],
            (float) ($metadata['credits']['total_charged'] ?? 0.0)
        );

        /** @var AIResponse $response */
        $response = $result['response'];

        $metadata['completed_at'] = now()->toISOString();
        $metadata['progress_percentage'] = 100;
        $metadata['progress_message'] = 'Reference pack generated successfully';
        $metadata['alias'] = $result['alias'];
        $metadata['reference_pack'] = $result['reference_pack'] ?? $result['character'] ?? null;
        $metadata['files'] = $response->getFiles();
        $metadata['usage'] = $response->getUsage();
        $metadata['response'] = $this->serializeResponse($response);

        $this->jobStatusTracker->updateStatus($jobId, 'completed', $metadata);

        return $this->jobStatusTracker->getStatus($jobId);
    }

    private function chargeStepCredits(AIRequest $request, int $stepNumber, array &$metadata, bool $skipCharge = false): array
    {
        if ($skipCharge) {
            return [
                'charged' => false,
                'amount' => 0.0,
                'skipped_retry_charge' => true,
            ];
        }

        $creditsEnabled = $this->shouldProcessCredits() && config('ai-engine.credits.enabled', false) && $request->getUserId();
        $creditsUsed = $this->creditManager->calculateCredits($request);

        if ($creditsEnabled && !$this->creditManager->hasCredits($request->getUserId(), $request)) {
            throw new InsufficientCreditsException('Insufficient credits for this request');
        }

        if ($creditsEnabled) {
            $this->creditManager->deductCredits($request->getUserId(), $request, $creditsUsed);
        }

        $metadata['credits']['charged_steps'][] = [
            'step' => $stepNumber,
            'model' => $request->getModel()->value,
            'amount' => $creditsEnabled ? $creditsUsed : 0.0,
            'charged' => (bool) $creditsEnabled,
        ];
        $metadata['credits']['total_charged'] = (float) ($metadata['credits']['total_charged'] ?? 0.0)
            + ($creditsEnabled ? $creditsUsed : 0.0);

        return [
            'charged' => (bool) $creditsEnabled,
            'amount' => $creditsEnabled ? $creditsUsed : 0.0,
        ];
    }

    private function retryCurrentStepWithProviderFallback(string $jobId, array $metadata, string $error): ?array
    {
        $stepIndex = (int) ($metadata['current_step_index'] ?? -1);
        if ($stepIndex < 0 || ($metadata['current_url_strategy'] ?? 'stored') === 'provider') {
            return null;
        }

        $workflow = is_array($metadata['workflow'] ?? null) ? $metadata['workflow'] : [];
        $step = $workflow[$stepIndex] ?? null;
        if (!is_array($step)) {
            return null;
        }

        $prompt = (string) ($metadata['prompt'] ?? '');
        $options = is_array($metadata['options'] ?? null) ? $metadata['options'] : [];
        $resolvedUserId = is_string($metadata['user_id'] ?? null) ? $metadata['user_id'] : null;
        $generatedImages = is_array($metadata['generated_images'] ?? null) ? $metadata['generated_images'] : [];

        if (!$this->referencePackGenerationService->hasProviderFallbackForStep(
            $prompt,
            $options,
            $resolvedUserId,
            $step,
            $generatedImages
        )) {
            return null;
        }

        $metadata = $this->updateCurrentStepStatus($metadata, 'retrying', [
            'stored_url_error' => $error,
            'retrying_with_provider_url' => true,
            'failed_at' => now()->toISOString(),
        ]);

        return $this->submitStep($jobId, $metadata, $stepIndex, 'provider', true);
    }

    private function failJob(string $jobId, array $metadata, string $error): array
    {
        $metadata = $this->mergeWorkflowSummary($metadata);
        $metadata['failed_at'] = now()->toISOString();
        $metadata['error'] = $error;
        $metadata = $this->updateCurrentStepStatus($metadata, 'failed', ['error' => $error]);

        $this->jobStatusTracker->updateStatus($jobId, 'failed', $metadata);

        return $this->jobStatusTracker->getStatus($jobId);
    }

    private function updateCurrentStepStatus(array $metadata, string $status, array $extra = []): array
    {
        $stepIndex = (int) ($metadata['current_step_index'] ?? -1);
        if ($stepIndex < 0) {
            return $metadata;
        }

        $metadata['steps'][$stepIndex] = array_merge(
            is_array($metadata['steps'][$stepIndex] ?? null) ? $metadata['steps'][$stepIndex] : [],
            ['status' => $status],
            $extra
        );

        return $metadata;
    }

    private function mergeWorkflowSummary(array $metadata): array
    {
        $workflow = is_array($metadata['workflow'] ?? null) ? $metadata['workflow'] : [];
        $options = is_array($metadata['options'] ?? null) ? $metadata['options'] : [];
        $summary = $this->buildWorkflowSummary($workflow, $options);

        return array_merge($metadata, $summary);
    }

    private function buildWorkflowSummary(array $workflow, array $options = []): array
    {
        return [
            'look_mode' => $this->resolveWorkflowLookMode($workflow, $options),
            'look_count' => $this->resolveWorkflowLookCount($workflow, $options),
            'frames_per_look' => $this->resolveWorkflowFramesPerLook($workflow, $options),
            'selected_look_ids' => $this->resolveSelectedLookIds($workflow, $options),
        ];
    }

    private function resolveWorkflowLookMode(array $workflow, array $options = []): ?string
    {
        foreach ($workflow as $step) {
            if (is_array($step) && is_string($step['look_mode'] ?? null) && trim($step['look_mode']) !== '') {
                return trim($step['look_mode']);
            }
        }

        if (is_string($options['look_mode'] ?? null) && trim((string) $options['look_mode']) !== '') {
            return trim((string) $options['look_mode']);
        }

        if (($options['strict_stored_looks'] ?? false) === true) {
            return 'strict_stored';
        }

        return null;
    }

    private function resolveWorkflowLookCount(array $workflow, array $options = []): int
    {
        $selectedLookIds = $this->resolveSelectedLookIds($workflow, $options);
        if ($selectedLookIds !== []) {
            return count($selectedLookIds);
        }

        $lookIndexes = [];
        foreach ($workflow as $step) {
            if (!is_array($step) || !isset($step['look_index'])) {
                continue;
            }

            $lookIndex = (int) $step['look_index'];
            if ($lookIndex > 0) {
                $lookIndexes[$lookIndex] = true;
            }
        }

        if ($lookIndexes !== []) {
            return count($lookIndexes);
        }

        return max(1, count($workflow));
    }

    private function resolveWorkflowFramesPerLook(array $workflow, array $options = []): int
    {
        $countsByLook = [];
        foreach ($workflow as $step) {
            if (!is_array($step)) {
                continue;
            }

            $lookIndex = (int) ($step['look_index'] ?? 1);
            $lookKey = $lookIndex > 0 ? $lookIndex : 1;
            $countsByLook[$lookKey] = ($countsByLook[$lookKey] ?? 0) + 1;
        }

        if ($countsByLook !== []) {
            return max($countsByLook);
        }

        return max(1, (int) ($options['frame_count'] ?? 1));
    }

    private function resolveSelectedLookIds(array $workflow, array $options = []): array
    {
        $selectedLookIds = [];

        $appendId = static function (mixed $id) use (&$selectedLookIds): void {
            if (!is_string($id) || trim($id) === '') {
                return;
            }

            $normalized = trim($id);
            if (!in_array($normalized, $selectedLookIds, true)) {
                $selectedLookIds[] = $normalized;
            }
        };

        foreach ($workflow as $step) {
            if (!is_array($step)) {
                continue;
            }

            $selectedLooks = is_array($step['selected_looks'] ?? null) ? $step['selected_looks'] : [];
            foreach ($selectedLooks as $selectedLook) {
                if (is_array($selectedLook)) {
                    $appendId($selectedLook['id'] ?? null);
                }
            }

            $selectedLook = is_array($step['selected_look'] ?? null) ? $step['selected_look'] : null;
            if ($selectedLook !== null) {
                $appendId($selectedLook['id'] ?? null);
            }
        }

        $optionSelectedLooks = is_array($options['selected_looks'] ?? null) ? $options['selected_looks'] : [];
        foreach ($optionSelectedLooks as $selectedLook) {
            if (is_array($selectedLook)) {
                $appendId($selectedLook['id'] ?? null);
            }
        }

        $appendId($options['look_id'] ?? null);

        return $selectedLookIds;
    }

    private function resolveUserId(?string $userId, array $options): ?string
    {
        if (is_string($userId) && trim($userId) !== '') {
            return trim($userId);
        }

        $configuredUserId = $options['user_id'] ?? null;

        return is_string($configuredUserId) && trim($configuredUserId) !== '' ? trim($configuredUserId) : null;
    }

    private function rebuildRequest(array $request): AIRequest
    {
        return new AIRequest(
            prompt: (string) ($request['prompt'] ?? ''),
            engine: (string) ($request['engine'] ?? EngineEnum::FAL_AI),
            model: (string) ($request['model'] ?? ''),
            parameters: is_array($request['parameters'] ?? null) ? $request['parameters'] : [],
            userId: is_string($request['user_id'] ?? null) ? $request['user_id'] : null
        );
    }

    private function serializeRequest(AIRequest $request): array
    {
        return [
            'prompt' => $request->getPrompt(),
            'engine' => $request->getEngine()->value,
            'model' => $request->getModel()->value,
            'parameters' => $request->getParameters(),
            'user_id' => $request->getUserId(),
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

        $configured = config('ai-engine.engines.fal_ai.async.reference_pack_webhook_url')
            ?? config('ai-engine.engines.fal_ai.async.webhook_url');
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
        $endpoint = (($options['preview_only'] ?? false) === true && ($options['entity_type'] ?? 'character') === 'character')
            ? 'preview'
            : 'reference-pack';

        return $this->appendQuery($appUrl . '/' . $prefix . '/' . $endpoint . '/fal/webhook', [
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
            throw new AIEngineException('FAL async reference pack service requires the concrete FAL engine driver.');
        }

        return $driver;
    }
}
