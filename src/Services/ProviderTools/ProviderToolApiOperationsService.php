<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Jobs\ContinueProviderToolRunJob;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Repositories\ProviderToolArtifactRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\Fal\FalCatalogExecutionService;
use LaravelAIEngine\Services\JobStatusTracker;

class ProviderToolApiOperationsService
{
    public function __construct(
        private readonly ProviderToolRunRepository $runs,
        private readonly ProviderToolApprovalRepository $approvals,
        private readonly ProviderToolArtifactRepository $artifacts,
        private readonly ProviderToolApprovalService $approvalService,
        private readonly ProviderToolContinuationService $continuations,
        private readonly ProviderFileDownloadService $downloads,
        private readonly FalCatalogExecutionService $falCatalog,
        private readonly JobStatusTracker $jobs
    ) {}

    public function runs(array $filters): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Provider tool runs loaded.',
            'data' => $this->runs->paginate($filters, (int) ($filters['per_page'] ?? 25))->toArray(),
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function showRun(string $run): JsonResponse
    {
        $record = $this->runs->findOrFail($run);

        return response()->json([
            'success' => true,
            'message' => 'Provider tool run loaded.',
            'data' => [
                'run' => $record->load(['approvals', 'artifacts'])->toArray(),
            ],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function approvals(array $filters): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Provider tool approvals loaded.',
            'data' => $this->approvals->paginate($filters, (int) ($filters['per_page'] ?? 25))->toArray(),
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function approve(string $approvalKey, array $validated): JsonResponse
    {
        $approval = $this->approvalService->approve(
            $approvalKey,
            $this->actorId($validated),
            $validated['reason'] ?? null,
            $validated['metadata'] ?? []
        );

        $continuation = $this->maybeDispatchContinuation($approval->tool_run_id, (bool) ($validated['continue'] ?? false));

        return response()->json([
            'success' => true,
            'message' => 'Provider tool approval approved.',
            'data' => [
                'approval' => $approval->toArray(),
                'continuation' => $continuation,
            ],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function reject(string $approvalKey, array $validated): JsonResponse
    {
        $approval = $this->approvalService->reject(
            $approvalKey,
            $this->actorId($validated),
            $validated['reason'] ?? null,
            $validated['metadata'] ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'Provider tool approval rejected.',
            'data' => ['approval' => $approval->toArray()],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function continueRun(string $run, array $validated): JsonResponse
    {
        if ((bool) ($validated['async'] ?? false)) {
            $jobId = (string) Str::uuid();
            ContinueProviderToolRunJob::dispatch($jobId, $run, $validated['options'] ?? []);
            $this->jobs->updateStatus($jobId, 'queued', [
                'provider_tool_run_id' => $run,
                'queued_at' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Provider tool continuation queued.',
                'data' => ['job_id' => $jobId, 'status' => $this->jobs->getStatus($jobId)],
                'error' => null,
                'meta' => ['schema' => 'ai-engine.v1'],
            ], 202);
        }

        $record = $this->continuations->continueRun($run, $validated['options'] ?? []);

        return response()->json([
            'success' => true,
            'message' => 'Provider tool run continued.',
            'data' => ['run' => $record->toArray()],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function artifacts(array $filters): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Provider tool artifacts loaded.',
            'data' => $this->artifacts->paginate($filters, (int) ($filters['per_page'] ?? 25))->toArray(),
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function downloadArtifact(string $artifact): Response
    {
        $record = $this->artifacts->findOrFail($artifact);
        $download = $this->downloads->download($record);

        return response($download['contents'], 200, [
            'Content-Type' => $download['mime_type'] ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . addslashes((string) ($download['file_name'] ?? 'artifact.bin')) . '"',
        ]);
    }

    public function executeFalCatalog(array $validated): JsonResponse
    {
        $parameters = $validated['input'] ?? $validated['parameters'] ?? [];
        $aiRequest = new AIRequest(
            prompt: (string) ($validated['prompt'] ?? ''),
            engine: EngineEnum::FalAI,
            model: (string) $validated['model'],
            parameters: is_array($parameters) ? $parameters : [],
            metadata: is_array($validated['metadata'] ?? null) ? $validated['metadata'] : []
        );

        try {
            if ((bool) ($validated['async'] ?? false)) {
                $result = $this->falCatalog->submitAsync($aiRequest, $validated['webhook_url'] ?? null);

                return response()->json([
                    'success' => true,
                    'message' => 'FAL catalog execution queued.',
                    'data' => $result,
                    'error' => null,
                    'meta' => ['schema' => 'ai-engine.v1'],
                ], 202);
            }

            $response = $this->falCatalog->executeRequest($aiRequest);
        } catch (\InvalidArgumentException $exception) {
            return $this->falError($exception->getMessage(), $exception->getMessage());
        } catch (\RuntimeException $exception) {
            return $this->falError('FAL catalog execution failed.', $exception->getMessage());
        }

        return response()->json([
            'success' => $response->isSuccessful(),
            'message' => $response->isSuccessful() ? 'FAL catalog execution completed.' : 'FAL catalog execution failed.',
            'data' => [
                'content' => $response->getContent(),
                'files' => $response->getFiles(),
                'metadata' => $response->getMetadata(),
            ],
            'error' => $response->isSuccessful() ? null : ['message' => $response->getError()],
            'meta' => ['schema' => 'ai-engine.v1'],
        ], $response->isSuccessful() ? 200 : 422);
    }

    public function falCatalogWebhook(array $validated, array $payload, mixed $queryRunId): JsonResponse
    {
        $webhookPayload = is_array($validated['payload'] ?? null) ? $validated['payload'] : $payload;
        $runId = $validated['provider_tool_run_id'] ?? $queryRunId;

        if (!is_string($runId) || $runId === '') {
            return response()->json([
                'success' => false,
                'message' => 'provider_tool_run_id is required.',
                'data' => null,
                'error' => ['message' => 'provider_tool_run_id is required.'],
                'meta' => ['schema' => 'ai-engine.v1'],
            ], 422);
        }

        $status = strtoupper((string) ($validated['status'] ?? 'OK'));
        $failed = !empty($validated['error'])
            || ($status !== '' && !in_array($status, ['OK', 'COMPLETED', 'SUCCESS'], true));

        if ($failed) {
            $record = $this->runs->findOrFail($runId);
            $error = $validated['error'] ?? ('FAL catalog run finished with status [' . $status . '].');
            $this->runs->update($record, [
                'status' => 'failed',
                'error' => is_string($error) ? $error : json_encode($error),
                'response_payload' => $webhookPayload,
                'failed_at' => now(),
            ]);
        } else {
            $this->falCatalog->completeQueuedRun($runId, $webhookPayload);
        }

        return response()->json([
            'success' => true,
            'message' => 'FAL catalog webhook processed.',
            'data' => ['provider_tool_run_id' => $runId],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    private function maybeDispatchContinuation(int|string|null $runId, bool $shouldContinue): ?array
    {
        if (!$shouldContinue || $runId === null) {
            return null;
        }

        $jobId = (string) Str::uuid();
        ContinueProviderToolRunJob::dispatch($jobId, $runId);
        $this->jobs->updateStatus($jobId, 'queued', [
            'provider_tool_run_id' => $runId,
            'queued_at' => now()->toISOString(),
        ]);

        return [
            'job_id' => $jobId,
            'status' => $this->jobs->getStatus($jobId),
        ];
    }

    private function actorId(array $validated): ?string
    {
        return isset($validated['actor_id']) && $validated['actor_id'] !== ''
            ? (string) $validated['actor_id']
            : (auth()->id() !== null ? (string) auth()->id() : null);
    }

    private function falError(string $message, string $error): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => ['message' => $error],
            'meta' => ['schema' => 'ai-engine.v1'],
        ], 422);
    }
}
