<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class SendMessageDTO
{
    public function __construct(
        public readonly string $message,
        public readonly string $sessionId,
        public readonly string $engine = 'openai',
        public readonly string $model = 'gpt-4o',
        public readonly bool $memory = true,
        public readonly bool $actions = true,
        public readonly bool $streaming = false,
        public readonly ?string $userId = null,
        public readonly bool $intelligentRag = false,
        public readonly bool $forceRag = false,
        public readonly ?array $ragCollections = null,
        public readonly ?string $searchInstructions = null,
        public readonly bool $agentGoal = false,
        public readonly ?string $target = null,
        public readonly ?array $subAgents = null,
        public readonly ?array $goalAgent = null,
        public readonly ?string $responsePointsFormat = null,
        public readonly ?bool $responseSuggestions = null,
        public readonly ?int $responseSuggestionLimit = null,
        public readonly ?array $collection = null
    ) {}

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'session_id' => $this->sessionId,
            'engine' => $this->engine,
            'model' => $this->model,
            'memory' => $this->memory,
            'actions' => $this->actions,
            'streaming' => $this->streaming,
            'user_id' => auth()->user()->id ?? $this->userId,
            'rag' => $this->intelligentRag,
            'force_rag' => $this->forceRag,
            'rag_collections' => $this->ragCollections,
            'search_instructions' => $this->searchInstructions,
            'agent_goal' => $this->agentGoal,
            'target' => $this->target,
            'sub_agents' => $this->subAgents,
            'goal_agent' => $this->goalAgent,
            'response_points_format' => $this->responsePointsFormat,
            'response_suggestions' => $this->responseSuggestions,
            'response_suggestion_limit' => $this->responseSuggestionLimit,
            'collection' => $this->collection,
        ];
    }

    public function agentOptions(): array
    {
        $options = array_filter([
            'agent_goal' => $this->agentGoal,
            'target' => $this->target,
            'sub_agents' => $this->subAgents,
            'goal_agent' => $this->goalAgent,
            'response_points_format' => $this->responsePointsFormat,
            'response_suggestion_limit' => $this->responseSuggestionLimit,
            'collection' => $this->collection,
        ], static fn ($value) => $value !== null && $value !== false && $value !== []);

        if ($this->responseSuggestions !== null) {
            $options['response_suggestions'] = $this->responseSuggestions;
        }

        return $options;
    }
}
