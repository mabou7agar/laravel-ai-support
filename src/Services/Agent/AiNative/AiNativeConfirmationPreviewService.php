<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class AiNativeConfirmationPreviewService
{
    /**
     * @param array<string, mixed> $arguments
     * @return array{arguments: array<string, mixed>, summary: array<string, mixed>, result: ActionResult|null}
     */
    public function preview(AgentTool $tool, array $arguments, UnifiedActionContext $context): array
    {
        $result = $tool->previewConfirmation($arguments, $context);
        if (!$result instanceof ActionResult || !$result->success || $result->requiresUserInput()) {
            return [
                'arguments' => $arguments,
                'summary' => $arguments,
                'result' => $result,
            ];
        }

        $data = is_array($result->data) ? $result->data : [];
        $draft = is_array($data['draft'] ?? null) ? $data['draft'] : [];
        $payload = is_array($draft['payload'] ?? null)
            ? (array) $draft['payload']
            : (is_array($data['payload'] ?? null) ? (array) $data['payload'] : $arguments);
        $summary = is_array($draft['summary'] ?? null)
            ? (array) $draft['summary']
            : (is_array($data['summary'] ?? null) ? (array) $data['summary'] : $payload);

        return [
            'arguments' => $payload,
            'summary' => $summary,
            'result' => $result,
        ];
    }
}
