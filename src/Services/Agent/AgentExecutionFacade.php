<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;

class AgentExecutionFacade
{
    public function __construct(
        protected AgentActionExecutionService $actionExecutionService,
        protected AgentConversationService $conversationService,
        protected NodeSessionManager $nodeSessionManager,
        protected AutonomousCollectorRegistry $collectorRegistry,
        protected AutonomousCollectorHandler $collectorHandler
    ) {
    }

    public function shouldContinueRoutedSession(string $message, UnifiedActionContext $context): bool
    {
        return $this->nodeSessionManager->shouldContinueSession($message, $context);
    }

    public function continueRoutedSession(string $message, UnifiedActionContext $context, array $options): ?AgentResponse
    {
        return $this->nodeSessionManager->continueSession($message, $context, $options);
    }

    public function routeToNode(
        string $requestedResource,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->nodeSessionManager->routeToNode($requestedResource, $message, $context, $options);
    }

    public function continueCollectorSession(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->collectorHandler->handle($message, $context, array_merge($options, [
            'action' => 'continue_autonomous_collector',
        ]));
    }

    public function executeUseTool(
        string $toolName,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag
    ): AgentResponse {
        return $this->actionExecutionService->executeUseTool($toolName, $message, $context, $options, $searchRag);
    }

    public function executeStartCollector(
        string $collectorName,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $routeToNode
    ): AgentResponse {
        return $this->actionExecutionService->executeStartCollector($collectorName, $message, $context, $options, $routeToNode);
    }

    public function executeResumeSession(UnifiedActionContext $context): AgentResponse
    {
        return $this->actionExecutionService->executeResumeSession($context);
    }

    public function executePauseAndHandle(
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag
    ): AgentResponse {
        return $this->actionExecutionService->executePauseAndHandle($message, $context, $options, $searchRag);
    }

    public function executeSearchRag(
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $reroute
    ): AgentResponse {
        return $this->conversationService->executeSearchRAG($message, $context, $options, $reroute);
    }

    public function executeConversational(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->conversationService->executeConversational($message, $context, $options);
    }
}
