<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts\RAG;

interface FederatedModelRouter
{
    public function routeForModel(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): ?array;
}
