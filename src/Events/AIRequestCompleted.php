<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;

class AIRequestCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AIRequest $request,
        public AIResponse $response,
        public string $requestId,
        public float $executionTime,
        public array $metadata = []
    ) {}

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
