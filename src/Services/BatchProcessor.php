<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Services;

use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Jobs\BatchProcessAIRequestsJob;

class BatchProcessor
{
    private array $requests = [];

    public function __construct(
        private AIEngineManager $manager
    ) {}

    /**
     * Add request to batch
     */
    public function add(string $engine, string $model, string $prompt, array $parameters = []): self
    {
        $engineEnum = EngineEnum::fromSlug($engine);
        $modelEnum = EntityEnum::fromSlug($model);

        $this->requests[] = AIRequest::make($prompt, $engineEnum, $modelEnum, $parameters);

        return $this;
    }

    /**
     * Add AIRequest to batch
     */
    public function addRequest(AIRequest $request): self
    {
        $this->requests[] = $request;
        return $this;
    }

    /**
     * Process all requests in batch
     */
    public function process(): array
    {
        $results = [];

        foreach ($this->requests as $index => $request) {
            try {
                $results[$index] = $this->manager->processRequest($request);
            } catch (\Exception $e) {
                $results[$index] = AIResponse::error(
                    $e->getMessage(),
                    $request->engine,
                    $request->model
                );
            }
        }

        return $results;
    }

    /**
     * Process requests asynchronously using Laravel queues
     */
    public function processAsync(
        ?string $callbackUrl = null,
        bool $stopOnError = false,
        ?string $queue = null
    ): string {
        if (empty($this->requests)) {
            throw new \InvalidArgumentException('No requests to process');
        }

        $batchId = uniqid('batch_');
        
        $job = new BatchProcessAIRequestsJob(
            requests: $this->requests,
            batchId: $batchId,
            callbackUrl: $callbackUrl,
            stopOnError: $stopOnError
        );

        if ($queue) {
            $job->onQueue($queue);
        }

        $job->dispatch();

        return $batchId;
    }

    /**
     * Process requests concurrently (legacy method - now uses queues)
     */
    public function processConcurrently(?string $callbackUrl = null): string
    {
        return $this->processAsync($callbackUrl);
    }

    /**
     * Get total estimated cost for batch
     */
    public function estimateTotalCost(): float
    {
        $totalCredits = 0.0;

        foreach ($this->requests as $request) {
            // This would use the credit manager to calculate costs
            $totalCredits += $request->model->creditIndex();
        }

        return $totalCredits;
    }

    /**
     * Clear all requests
     */
    public function clear(): self
    {
        $this->requests = [];
        return $this;
    }

    /**
     * Get request count
     */
    public function count(): int
    {
        return count($this->requests);
    }
}
