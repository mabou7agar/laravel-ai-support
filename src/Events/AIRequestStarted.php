<?php

declare(strict_types=1);

namespace LaravelAIEngine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelAIEngine\DTOs\AIRequest;

class AIRequestStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AIRequest $request,
        public string $requestId,
        public ?string $userId = null,
        public array $metadata = []
    ) {
        // Set convenience properties from request
        $this->engine = $request->getEngine()->value;
        $this->model = $request->getModel()->value;
    }
    
    public string $engine;
    public string $model;

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [];
    }

    /**
     * Get event data for logging
     */
    public function getEventData(): array
    {
        return [
            'request_id' => $this->requestId,
            'engine' => $this->request->engine->value,
            'model' => $this->request->model->value,
            'prompt_length' => strlen($this->request->prompt),
            'has_system_prompt' => !empty($this->request->systemPrompt),
            'has_files' => !empty($this->request->files),
            'temperature' => $this->request->temperature,
            'max_tokens' => $this->request->maxTokens,
            'parameters' => $this->request->parameters,
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}
