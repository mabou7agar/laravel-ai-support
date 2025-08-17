<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;

class AIRequestStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AIRequest $request,
        public string $requestId,
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
