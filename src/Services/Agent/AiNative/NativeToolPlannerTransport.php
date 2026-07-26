<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;

class NativeToolPlannerTransport implements AiNativePlannerTransportContract
{
    public function __construct(
        private readonly AiNativePromptBuilder $promptBuilder,
        private readonly AiNativeToolSchemaMapper $schemaMapper,
        private readonly AiNativeResponseParser $fallbackParser
    ) {
    }

    public function name(): string
    {
        return 'native_tools';
    }

    public function prepare(
        AIRequest $request,
        string $message,
        array $state,
        array $options = []
    ): AIRequest {
        $documents = $this->promptBuilder->nativeToolDocuments($message, $state, $options);
        $choice = ($options['_planner_supports_tool_choice'] ?? null) === false
            ? null
            : ($options['native_tool_choice']
                ?? config('ai-agent.ai_native.planner_transport.native_tool_choice', 'auto'));

        if ($choice !== null && !is_array($choice) && !is_string($choice)) {
            $choice = 'auto';
        }

        $parallel = array_key_exists('parallel_tools', $options)
            ? (bool) $options['parallel_tools']
            : (bool) config('ai-agent.ai_native.parallel_tools', false);

        return $request
            ->withParameters(['parallel_tool_calls' => $parallel])
            ->withFunctions($this->schemaMapper->map($documents), $choice);
    }

    public function parse(AIResponse $response): array
    {
        $calls = $this->functionCalls($response);
        if ($calls === []) {
            return $this->fallbackParser->parse($response->getContent());
        }

        $control = count($calls) === 1 ? $this->controlPlan($calls) : null;
        if ($control !== null) {
            return $control;
        }

        $calls = array_values(array_filter(
            $calls,
            static fn (array $call): bool => !in_array(
                $call['name'],
                [AiNativeToolSchemaMapper::FINAL_TOOL, AiNativeToolSchemaMapper::ASK_USER_TOOL],
                true
            )
        ));

        if ($calls === []) {
            return $this->fallbackParser->parse($response->getContent());
        }

        $plans = array_values(array_map(
            static fn (array $call): array => [
                'tool' => $call['name'],
                'arguments' => $call['arguments'],
            ],
            $calls
        ));

        if (count($plans) === 1) {
            return [
                'action' => 'tool_call',
                'tool' => $plans[0]['tool'],
                'arguments' => $plans[0]['arguments'],
                'message' => trim($response->getContent()),
            ];
        }

        return [
            'action' => 'tool_call',
            'tool' => $plans[0]['tool'],
            'arguments' => $plans[0]['arguments'],
            'tool_calls' => $plans,
            'message' => trim($response->getContent()),
        ];
    }

    /**
     * @return array<int, array{name: string, arguments: array<string, mixed>}>
     */
    private function functionCalls(AIResponse $response): array
    {
        $rawCalls = (array) ($response->getMetadata()['tool_calls'] ?? []);
        $calls = [];

        foreach ($rawCalls as $rawCall) {
            if (!is_array($rawCall)) {
                continue;
            }

            $function = (array) ($rawCall['function'] ?? $rawCall);
            $name = trim((string) ($function['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $calls[] = [
                'name' => $name,
                'arguments' => $this->arguments($function['arguments'] ?? []),
            ];
        }

        if ($calls !== []) {
            return $calls;
        }

        $first = $response->getFunctionCall();
        $name = trim((string) ($first['name'] ?? ''));

        return $name === '' ? [] : [[
            'name' => $name,
            'arguments' => $this->arguments($first['arguments'] ?? []),
        ]];
    }

    /**
     * @param array<int, array{name: string, arguments: array<string, mixed>}> $calls
     * @return array<string, mixed>|null
     */
    private function controlPlan(array $calls): ?array
    {
        foreach ($calls as $call) {
            if ($call['name'] === AiNativeToolSchemaMapper::FINAL_TOOL) {
                return [
                    'action' => 'final',
                    'message' => trim((string) ($call['arguments']['message'] ?? '')),
                    'data' => (array) ($call['arguments']['data'] ?? []),
                ];
            }

            if ($call['name'] === AiNativeToolSchemaMapper::ASK_USER_TOOL) {
                return [
                    'action' => 'ask_user',
                    'message' => trim((string) ($call['arguments']['message'] ?? '')),
                    'required_inputs' => array_values((array) ($call['arguments']['required_inputs'] ?? [])),
                    'data' => (array) ($call['arguments']['data'] ?? []),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (!is_string($arguments) || trim($arguments) === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }
}
