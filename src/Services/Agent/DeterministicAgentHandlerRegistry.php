<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Contracts\DeterministicAgentHandler;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

class DeterministicAgentHandlerRegistry
{
    /**
     * @var array<int, class-string<DeterministicAgentHandler>|DeterministicAgentHandler>
     */
    protected array $handlers = [];

    public function __construct(protected Container $container)
    {
        $this->registerBatch((array) config('ai-agent.deterministic_handlers', []));
    }

    /**
     * @param class-string<DeterministicAgentHandler>|DeterministicAgentHandler $handler
     */
    public function register(string|DeterministicAgentHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * @param array<int, class-string<DeterministicAgentHandler>|DeterministicAgentHandler> $handlers
     */
    public function registerBatch(array $handlers): void
    {
        foreach ($handlers as $handler) {
            if (is_string($handler) || $handler instanceof DeterministicAgentHandler) {
                $this->register($handler);
            }
        }
    }

    public function handle(string $message, UnifiedActionContext $context, array $options = []): ?AgentResponse
    {
        foreach ($this->resolvedHandlers() as $handler) {
            try {
                $response = $handler->handle($message, $context, $options);
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Deterministic agent handler failed', [
                    'handler' => $handler::class,
                    'session_id' => $context->sessionId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($response instanceof AgentResponse) {
                $response->context ??= $context;

                return $response;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function candidates(): array
    {
        return collect($this->resolvedHandlers())
            ->map(static fn (DeterministicAgentHandler $handler): string => $handler::class)
            ->values()
            ->all();
    }

    /**
     * @return array<int, DeterministicAgentHandler>
     */
    protected function resolvedHandlers(): array
    {
        $configured = collect($this->handlers)
            ->map(fn (string|DeterministicAgentHandler $handler): ?DeterministicAgentHandler => $this->resolve($handler))
            ->filter()
            ->values();

        $tagged = collect($this->container->tagged(DeterministicAgentHandler::class))
            ->filter(fn (mixed $handler): bool => $handler instanceof DeterministicAgentHandler);

        return $configured
            ->merge($tagged)
            ->unique(fn (DeterministicAgentHandler $handler): string => $handler::class)
            ->values()
            ->all();
    }

    /**
     * @param class-string<DeterministicAgentHandler>|DeterministicAgentHandler $handler
     */
    protected function resolve(string|DeterministicAgentHandler $handler): ?DeterministicAgentHandler
    {
        if ($handler instanceof DeterministicAgentHandler) {
            return $handler;
        }

        if (!class_exists($handler)) {
            return null;
        }

        $resolved = $this->container->make($handler);

        return $resolved instanceof DeterministicAgentHandler ? $resolved : null;
    }
}
