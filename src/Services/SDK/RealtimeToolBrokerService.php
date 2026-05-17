<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use Throwable;

class RealtimeToolBrokerService
{
    public function __construct(
        protected ToolRegistry $tools
    ) {
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function dispatch(array $event, UnifiedActionContext $context, bool $approved = false): array
    {
        $call = $this->normalizeToolCall($event);
        $toolName = $call['name'];
        $tool = $this->tools->get($toolName);

        if ($tool === null) {
            return [
                'success' => false,
                'status' => 'tool_not_found',
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'error' => "Realtime tool [{$toolName}] is not registered.",
            ];
        }

        if ($tool->requiresConfirmation() && !$approved) {
            return [
                'success' => false,
                'status' => 'approval_required',
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'message' => $tool->getConfirmationMessage() ?? "Approve realtime tool [{$toolName}] before execution.",
            ];
        }

        try {
            $result = $tool->execute($call['arguments'], $context);

            return [
                'success' => $result->success,
                'status' => $result->success ? 'completed' : 'failed',
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'output' => $result->toArray(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'failed',
                'tool_call_id' => $call['id'],
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array{id:string|null,name:string,arguments:array<string,mixed>}
     */
    protected function normalizeToolCall(array $event): array
    {
        $functionCall = $event['toolCall']['functionCalls'][0] ?? $event['functionCall'] ?? $event;
        $arguments = $functionCall['arguments'] ?? $functionCall['args'] ?? [];

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => isset($functionCall['call_id']) ? (string) $functionCall['call_id'] : (isset($functionCall['id']) ? (string) $functionCall['id'] : null),
            'name' => (string) ($functionCall['name'] ?? $functionCall['function']['name'] ?? ''),
            'arguments' => is_array($arguments) ? $arguments : [],
        ];
    }
}
