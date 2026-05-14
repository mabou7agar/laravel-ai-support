<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\CollectorToolCall;

class CollectorToolCallParser
{
    public function extractToolCallFromResponse(AIResponse $response): ?CollectorToolCall
    {
        $functionCall = $response->getFunctionCall();
        if (is_array($functionCall)) {
            $toolCall = $this->extractToolCallFromPayload($functionCall);
            if ($toolCall instanceof CollectorToolCall) {
                return $toolCall;
            }
        }

        foreach (['tool_calls', 'function_calls', 'calls'] as $key) {
            $calls = $response->getMetadata()[$key] ?? null;
            if (!is_array($calls)) {
                continue;
            }

            foreach (array_is_list($calls) ? $calls : [$calls] as $call) {
                if (!is_array($call)) {
                    continue;
                }

                $toolCall = $this->extractToolCallFromPayload($call);
                if ($toolCall instanceof CollectorToolCall) {
                    return $toolCall;
                }
            }
        }

        return $this->extractToolCall($response->getContent());
    }

    public function extractToolCall(string $content): ?CollectorToolCall
    {
        if (!preg_match('/```tool\s*\n?(.*?)\n?```/s', $content, $matches)) {
            return null;
        }

        $payload = json_decode(trim($matches[1]), true);
        if (!is_array($payload)) {
            return null;
        }

        return CollectorToolCall::fromArray($payload);
    }

    public function extractFinalOutput(string $content): ?array
    {
        if (!preg_match('/```json\s*\n?(.*?)\n?```/s', $content, $matches)) {
            return null;
        }

        $payload = json_decode(trim($matches[1]), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($payload) ? $payload : null;
    }

    protected function extractToolCallFromPayload(array $payload): ?CollectorToolCall
    {
        $function = $payload['function'] ?? $payload['tool'] ?? null;
        $name = null;
        $arguments = [];

        if (is_array($function)) {
            $name = $function['name'] ?? null;
            $arguments = $function['arguments'] ?? [];
        } else {
            $name = $payload['name'] ?? $payload['tool'] ?? $payload['tool_name'] ?? $function;
            $arguments = $payload['arguments'] ?? $payload['args'] ?? [];
        }

        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        return new CollectorToolCall(trim($name), is_array($arguments) ? $arguments : []);
    }
}
