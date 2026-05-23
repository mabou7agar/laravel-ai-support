<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentExecutionFacade
{
    public function __construct(
        protected AgentActionExecutionService $actionExecutionService,
        protected AgentConversationService $conversationService,
        protected NodeSessionManager $nodeSessionManager
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

    public function executeUseTool(
        string $toolName,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag
    ): AgentResponse {
        return $this->actionExecutionService->executeUseTool($toolName, $message, $context, $options, $searchRag);
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
