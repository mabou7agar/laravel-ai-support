<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolSchemaMapper;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativeNativeToolTransportTest extends UnitTestCase
{
    public function test_runtime_executes_native_tool_call_and_native_final_control(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $executed = [];
        $registry = new ToolRegistry();
        $registry->register('lookup_customer', new class($executed) extends AgentTool {
            public function __construct(private array &$executed)
            {
            }

            public function getName(): string
            {
                return 'lookup_customer';
            }

            public function getDescription(): string
            {
                return 'Find a customer by name.';
            }

            public function getParameters(): array
            {
                return [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Customer name.',
                        'required' => true,
                    ],
                ];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->executed[] = $parameters;

                return ActionResult::success('Customer found.', ['customer_id' => 501]);
            }
        });

        $responses = [
            $this->nativeResponse('lookup_customer', ['query' => 'Ahmed']),
            $this->nativeResponse(AiNativeToolSchemaMapper::FINAL_TOOL, [
                'message' => 'Ahmed exists.',
                'data' => ['customer_id' => 501],
            ]),
        ];

        $requests = [];
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->twice()
            ->andReturnUsing(function (AIRequest $request) use (&$requests, &$responses): AIResponse {
                $requests[] = $request;

                return array_shift($responses);
            });

        $runtime = new AiNativeRuntime(
            $ai,
            $registry,
            app(AgentSkillRegistry::class),
            app(IntentSignalService::class)
        );

        $response = $runtime->process(
            'Find Ahmed',
            new UnifiedActionContext('native-tools-runtime', 77),
            [
                'planner_transport' => 'native_tools',
                'native_tool_choice' => 'required',
            ]
        );

        $this->assertTrue($response->success);
        $this->assertSame('Ahmed exists.', $response->message);
        $this->assertSame([['query' => 'Ahmed']], $executed);
        $this->assertSame('required', $requests[0]->getFunctionCall());
        $this->assertFalse($requests[0]->getParameters()['parallel_tool_calls']);
        $this->assertStringContainsString('Native tool transport is active.', $requests[0]->getPrompt());
        $this->assertStringNotContainsString('Return JSON only.', $requests[0]->getPrompt());

        $lookup = collect($requests[0]->getFunctions())->firstWhere('name', 'lookup_customer');
        $this->assertSame(['query'], $lookup['parameters']['required'] ?? null);
        $this->assertNotNull(collect($requests[0]->getFunctions())->firstWhere(
            'name',
            AiNativeToolSchemaMapper::FINAL_TOOL
        ));
    }

    public function test_native_provider_error_retries_once_with_prompt_json_transport(): void
    {
        $requests = [];
        $responses = [
            AIResponse::error('Native tools unavailable.', 'openai', 'gpt-4o'),
            AIResponse::success(
                '{"action":"final","message":"Hello from the fallback."}',
                'openai',
                'gpt-4o'
            ),
        ];

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->twice()
            ->andReturnUsing(function (AIRequest $request) use (&$requests, &$responses): AIResponse {
                $requests[] = $request;

                return array_shift($responses);
            });

        $runtime = new AiNativeRuntime(
            $ai,
            new ToolRegistry(),
            app(AgentSkillRegistry::class),
            app(IntentSignalService::class)
        );

        $response = $runtime->process(
            'Hello',
            new UnifiedActionContext('native-tools-fallback', 77),
            ['planner_transport' => 'native_tools']
        );

        $this->assertTrue($response->success);
        $this->assertSame('Hello from the fallback.', $response->message);
        $this->assertNotEmpty($requests[0]->getFunctions());
        $this->assertSame([], $requests[1]->getFunctions());
        $this->assertStringContainsString('Return JSON only.', $requests[1]->getPrompt());
    }

    public function test_native_transport_can_send_functions_without_duplicating_them_in_prompt_text(): void
    {
        $registry = new ToolRegistry();
        $registry->register('lookup_customer', new class extends AgentTool {
            public function getName(): string
            {
                return 'lookup_customer';
            }

            public function getDescription(): string
            {
                return 'Find a customer by name.';
            }

            public function getParameters(): array
            {
                return [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Customer name.',
                        'required' => true,
                    ],
                ];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Customer found.');
            }
        });

        $captured = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (AIRequest $request) use (&$captured): AIResponse {
                $captured = $request;

                return $this->nativeResponse(AiNativeToolSchemaMapper::FINAL_TOOL, [
                    'message' => 'Done.',
                ]);
            });

        $runtime = new AiNativeRuntime(
            $ai,
            $registry,
            app(AgentSkillRegistry::class),
            app(IntentSignalService::class)
        );

        $response = $runtime->process(
            'Find Ahmed',
            new UnifiedActionContext('native-tools-no-duplicate', 77),
            [
                'planner_transport' => 'native_tools',
                'tool_selection' => ['embed_native_documents' => false],
            ]
        );

        $this->assertTrue($response->success);
        $this->assertStringNotContainsString('lookup_customer', $captured->getPrompt());
        $this->assertNotNull(collect($captured->getFunctions())->firstWhere(
            'name',
            'lookup_customer'
        ));
    }

    public function test_auto_transport_uses_native_tools_from_request_capabilities(): void
    {
        $captured = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (AIRequest $request) use (&$captured): AIResponse {
                $captured = $request;

                return $this->nativeResponse(AiNativeToolSchemaMapper::FINAL_TOOL, [
                    'message' => 'Auto selected native tools.',
                ]);
            });

        $runtime = new AiNativeRuntime(
            $ai,
            new ToolRegistry(),
            app(AgentSkillRegistry::class),
            app(IntentSignalService::class)
        );

        $response = $runtime->process(
            'Hello',
            new UnifiedActionContext('native-tools-auto', 77),
            [
                'planner_transport' => 'auto',
                'model_capabilities' => [
                    'supports_native_tools' => true,
                    'supported_parameters' => ['tools'],
                ],
            ]
        );

        $this->assertTrue($response->success);
        $this->assertSame('Auto selected native tools.', $response->message);
        $this->assertNotEmpty($captured->getFunctions());
        $this->assertNull($captured->getFunctionCall());
    }

    private function nativeResponse(string $name, array $arguments): AIResponse
    {
        $raw = [
            'id' => 'call_' . $name,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments),
            ],
        ];

        return AIResponse::success('', 'openai', 'gpt-4o', [
            'tool_calls' => [$raw],
        ])->withFunctionCall([
            'id' => $raw['id'],
            'name' => $name,
            'arguments' => $arguments,
            'raw' => $raw,
        ])->withFinishReason('tool_calls');
    }
}
