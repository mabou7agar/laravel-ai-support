<?php

declare(strict_types=1);

namespace LaravelAIEngine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;

class AIRequestCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AIRequest $request,
        public AIResponse $response,
        public string $requestId,
        public float $executionTime,
        public ?string $userId = null,
        public array $metadata = []
    ) {
        // Set convenience properties from request and response
        $this->engine = $request->getEngine()->value;
        $this->model = $request->getModel()->value;

        // Set token usage properties
        $usage = $response->usage ?? [];
        $this->inputTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0;
        $this->outputTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
        $this->totalTokens = $usage['total_tokens'] ?? ($this->inputTokens + $this->outputTokens);

        // Set timing properties
        $this->responseTime = $executionTime;

        // Set cost and credits properties (use property_exists to avoid errors)
        $this->creditsUsed = property_exists($response, 'creditsUsed') ? ($response->creditsUsed ?? 0) : 0;
        $this->cost = $this->creditsUsed * 0.001; // Calculate cost from credits

        // Set status properties
        $this->success = method_exists($response, 'isSuccess') ? $response->isSuccess() : true;
        $this->finishReason = property_exists($response, 'finishReason') ? ($response->finishReason ?? 'complete') : 'complete';
        $this->errorMessage = property_exists($response, 'error') ? $response->error : null;

        // Set content properties
        $this->content = method_exists($response, 'getContent') ? ($response->getContent() ?? '') : '';
        $this->contentLength = strlen($this->content);
    }

    public string $engine;
    public string $model;
    public int $inputTokens;
    public int $outputTokens;
    public int $totalTokens;
    public float $responseTime;
    public float $cost;
    public float $creditsUsed;  // Changed from int to float
    public bool $success;
    public string $finishReason;
    public ?string $errorMessage;
    public string $content;
    public int $contentLength;

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [];
    }

    /**
     * Get event data for logging and analytics
     */
    public function getEventData(): array
    {
        return [
            'request_id' => $this->requestId,
            'engine' => $this->request->engine->value,
            'model' => $this->request->model->value,
            'success' => $this->response->isSuccess(),
            'execution_time' => $this->executionTime,
            'tokens_used' => $this->response->tokensUsed,
            'credits_used' => $this->response->creditsUsed,
            'content_length' => strlen($this->response->content),
            'has_files' => !empty($this->response->files),
            'finish_reason' => $this->response->finishReason,
            'error_message' => $this->response->errorMessage,
            'detailed_usage' => $this->response->detailedUsage,
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Check if this was a successful request
     */
    public function wasSuccessful(): bool
    {
        return $this->response->isSuccess();
    }

    /**
     * Get cost information
     */
    public function getCostInfo(): array
    {
        return [
            'credits_used' => $this->response->creditsUsed,
            'tokens_used' => $this->response->tokensUsed,
            'model_cost_index' => $this->request->model->creditIndex(),
            'estimated_cost' => $this->response->creditsUsed * 0.001, // Example cost calculation
        ];
    }
}
