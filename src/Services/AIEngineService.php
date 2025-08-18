<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\ConversationManager;
use Illuminate\Support\Facades\Event;

class AIEngineService
{
    public function __construct(
        protected CreditManager $creditManager,
        protected ?ConversationManager $conversationManager = null
    ) {
        $this->conversationManager = $conversationManager ?? app(ConversationManager::class);
    }

    /**
     * Generate AI content using the specified request.
     */
    public function generate(AIRequest $request): AIResponse
    {
        $startTime = microtime(true);
        $requestId = uniqid('ai_req_');

        try {
            // Check credits before processing
            if ($request->userId && !$this->creditManager->hasCredits($request->userId, $request)) {
                throw new InsufficientCreditsException('Insufficient credits for this request');
            }

            // Dispatch request started event
            Event::dispatch(new AIRequestStarted(
                request: $request,
                requestId: $requestId,
                metadata: $request->metadata
            ));

            // Get the appropriate driver
            $driver = $this->getDriver($request->engine);

            // Validate the request
            $driver->validateRequest($request);

            // Generate the response
            $response = $driver->generate($request);

            // Deduct credits if successful
            if ($response->success && $request->userId) {
                $this->creditManager->deductCredits($request->userId, $request);
            }

            $processingTime = microtime(true) - $startTime;

            // Dispatch request completed event
            Event::dispatch(new AIRequestCompleted(
                request: $request,
                response: $response,
                requestId: $requestId,
                executionTime: $processingTime,
                metadata: array_merge($request->metadata, $response->metadata)
            ));

            return $response;

        } catch (\Exception $e) {
            $processingTime = microtime(true) - $startTime;

            // Create error response
            $errorResponse = new AIResponse(
                content: '',
                engine: $request->engine,
                model: $request->model,
                metadata: [],
                success: false,
                error: $e->getMessage()
            );

            // Dispatch error event
            Event::dispatch(new AIRequestCompleted(
                request: $request,
                response: $errorResponse,
                requestId: $requestId,
                executionTime: $processingTime,
                metadata: $request->metadata
            ));

            return new AIResponse(
                content: '',
                engine: $request->engine,
                model: $request->model,
                metadata: [],
                success: false,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Generate AI content with conversation context.
     */
    public function generateWithConversation(
        string $message,
        string $conversationId,
        EngineEnum $engine,
        EntityEnum $model,
        ?string $userId = null,
        array $parameters = []
    ): AIResponse {
        // Add user message to conversation
        $this->conversationManager->addUserMessage($conversationId, $message);

        // Create base request
        $baseRequest = new AIRequest(
            prompt: $message,
            engine: $engine,
            model: $model,
            parameters: $parameters,
            userId: $userId,
            metadata: ['conversation_id' => $conversationId]
        );

        // Enhance request with conversation context
        $contextualRequest = $this->conversationManager->enhanceRequestWithContext(
            $baseRequest,
            $conversationId
        );

        // Generate response
        $response = $this->generate($contextualRequest);

        // Add assistant message to conversation if successful
        if ($response->success) {
            $this->conversationManager->addAssistantMessage(
                $conversationId,
                $response->content,
                $response
            );
        }

        return $response;
    }

    /**
     * Stream AI content generation.
     */
    public function stream(AIRequest $request): \Generator
    {
        // Check credits before processing
        if ($request->userId && !$this->creditManager->hasCredits($request->userId, $request)) {
            throw new InsufficientCreditsException('Insufficient credits for this request');
        }

        // Get the appropriate driver
        $driver = $this->getDriver($request->engine);

        // Validate the request
        $driver->validateRequest($request);

        // Stream the response
        yield from $driver->stream($request);

        // Deduct credits after streaming
        if ($request->userId) {
            $this->creditManager->deductCredits($request->userId, $request);
        }
    }

    /**
     * Get the appropriate engine driver.
     */
    protected function getDriver(EngineEnum $engine): EngineDriverInterface
    {
        $driverClass = $engine->driverClass();

        if (!class_exists($driverClass)) {
            throw new AIEngineException("Driver class {$driverClass} not found for engine {$engine->value}");
        }

        $config = config("ai-engine.engines.{$engine->value}", []);
        
        // For OpenAI and Anthropic drivers, inject HTTP client if available in container
        if ($driverClass === \LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver::class ||
            $driverClass === \LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver::class) {
            try {
                $httpClient = app(\GuzzleHttp\Client::class);
                return new $driverClass($config, $httpClient);
            } catch (\Exception $e) {
                return new $driverClass($config);
            }
        }
        
        return new $driverClass($config);
    }

    /**
     * Get available engines.
     */
    public function getAvailableEngines(): array
    {
        return array_map(fn($engine) => [
            'name' => $engine->value,
            'capabilities' => $engine->capabilities(),
            'models' => $engine->getDefaultModels(),
        ], EngineEnum::cases());
    }

    /**
     * Test an engine with a simple request.
     */
    public function testEngine(EngineEnum $engine, string $prompt = 'Hello, world!'): AIResponse
    {
        $defaultModels = $engine->getDefaultModels();
        if (empty($defaultModels)) {
            throw new AIEngineException("No default models available for engine {$engine->value}");
        }

        $model = array_key_first($defaultModels);
        $entityEnum = \LaravelAIEngine\Enums\EntityEnum::from($model);

        $request = new AIRequest(
            prompt: $prompt,
            engine: $engine,
            model: $entityEnum
        );

        return $this->generate($request);
    }

    /**
     * Generate text content using the specified request.
     * This is a convenience method for text-specific generation.
     */
    public function generateText(AIRequest $request): AIResponse
    {
        // Ensure the request is for text generation
        if ($request->model->getContentType() !== 'text') {
            throw new AIEngineException('The specified model is not suitable for text generation');
        }

        return $this->generate($request);
    }
}
