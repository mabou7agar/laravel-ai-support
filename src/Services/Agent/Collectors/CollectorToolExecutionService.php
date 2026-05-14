<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\CollectorToolCall;
use LaravelAIEngine\DTOs\CollectorToolResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class CollectorToolExecutionService
{
    public function execute(
        CollectorToolCall $toolCall,
        AutonomousCollectorConfig $config,
        UnifiedActionContext $context
    ): CollectorToolResult {
        try {
            $result = $this->normalizePayload(
                $config->executeTool($toolCall->tool, $toolCall->arguments, $context)
            );

            Log::channel('ai-engine')->info('Autonomous collector tool executed', [
                'tool' => $toolCall->tool,
                'arguments' => $toolCall->arguments,
            ]);

            return new CollectorToolResult(
                tool: $toolCall->tool,
                arguments: $toolCall->arguments,
                success: $this->transportSucceeded($result),
                result: $result,
                domainSuccess: $this->domainSuccess($result),
            );
        } catch (\Throwable $exception) {
            Log::channel('ai-engine')->warning('Autonomous collector tool failed', [
                'tool' => $toolCall->tool,
                'arguments' => $toolCall->arguments,
                'error' => $exception->getMessage(),
            ]);

            return new CollectorToolResult(
                tool: $toolCall->tool,
                arguments: $toolCall->arguments,
                success: false,
                error: $exception->getMessage(),
                domainSuccess: false,
            );
        }
    }

    protected function normalizePayload(mixed $payload): mixed
    {
        if ($payload instanceof Collection) {
            return $payload->map(fn (mixed $item): mixed => $this->normalizePayload($item))->toArray();
        }

        if ($payload instanceof Model) {
            return $payload->toArray();
        }

        return $payload;
    }

    protected function transportSucceeded(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return true;
        }

        if (array_key_exists('success', $payload)) {
            return (bool) $payload['success'];
        }

        if (array_key_exists('ok', $payload)) {
            return (bool) $payload['ok'];
        }

        if (array_key_exists('error', $payload) && $payload['error']) {
            return false;
        }

        return true;
    }

    protected function domainSuccess(mixed $payload): ?bool
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach (['success', 'ok', 'found', 'created', 'updated'] as $key) {
            if (array_key_exists($key, $payload)) {
                return (bool) $payload[$key];
            }
        }

        return null;
    }
}
