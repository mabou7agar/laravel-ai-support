<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AutonomousCollectorSessionState;
use LaravelAIEngine\DTOs\CollectorToolCall;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\Collectors\AutonomousCollectorTurnProcessor;
use LaravelAIEngine\Services\Agent\Collectors\CollectorConfirmationService;
use LaravelAIEngine\Services\Agent\Collectors\CollectorInputSchemaBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorPromptBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorReroutePolicy;
use LaravelAIEngine\Services\Agent\Collectors\CollectorSummaryRenderer;
use LaravelAIEngine\Services\Agent\Collectors\CollectorToolCallParser;
use LaravelAIEngine\Services\Agent\Collectors\CollectorToolExecutionService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use LaravelAIEngine\Tests\UnitTestCase;

class AutonomousCollectorTurnProcessorTest extends UnitTestCase
{
    public function test_pending_tool_confirmation_is_not_dropped_on_unclear_reply(): void
    {
        $ai = $this->createMock(AIEngineService::class);
        $ai->expects($this->never())->method('generate');

        $processor = $this->processor($ai);
        $context = new UnifiedActionContext('collector-test');
        $state = new AutonomousCollectorSessionState(
            configName: 'customer',
            pendingToolConfirmation: [
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Mohamed'],
            ],
        );

        $executed = false;
        $config = new AutonomousCollectorConfig(
            goal: 'Create customer',
            tools: [
                'create_customer' => [
                    'description' => 'Create customer',
                    'requires_confirmation' => true,
                    'handler' => function () use (&$executed): array {
                        $executed = true;

                        return ['success' => true];
                    },
                ],
            ],
        );

        $response = $processor->process('collector-test', 'use mohamed@example.test', $config, $state, $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertFalse($executed);
        $this->assertSame('create_customer', $context->get('autonomous_collector')['pending_tool_confirmation']['tool'] ?? null);
    }

    public function test_tool_execution_result_preserves_domain_failure(): void
    {
        $executor = new CollectorToolExecutionService();
        $context = new UnifiedActionContext('collector-tool-test');
        $config = new AutonomousCollectorConfig(
            goal: 'Create customer',
            tools: [
                'create_customer' => [
                    'description' => 'Create customer',
                    'handler' => fn (): array => ['success' => false, 'error' => 'Email is required'],
                ],
            ],
        );

        $result = $executor->execute(new CollectorToolCall('create_customer'), $config, $context);

        $this->assertFalse($result->success);
        $this->assertFalse($result->domainSuccess);
        $this->assertSame('Email is required', $result->result['error'] ?? null);
    }

    public function test_tool_loop_is_bounded(): void
    {
        $ai = $this->createMock(AIEngineService::class);
        $ai->method('generate')->willReturn(AIResponse::success(
            "```tool\n{\"tool\":\"find_customer\",\"arguments\":{\"query\":\"Acme\"}}\n```"
        ));

        $processor = $this->processor($ai);
        $context = new UnifiedActionContext('collector-loop-test');
        $state = new AutonomousCollectorSessionState(configName: 'invoice');
        $config = new AutonomousCollectorConfig(
            goal: 'Create invoice',
            tools: [
                'find_customer' => [
                    'description' => 'Find customer',
                    'handler' => fn (): array => ['success' => true, 'customers' => []],
                ],
            ],
            maxTurns: 50,
        );

        $response = $processor->process('collector-loop-test', 'create invoice for Acme', $config, $state, $context);

        $this->assertFalse($response->success);
        $this->assertStringContainsString('tool loop limit', $response->message);
        $this->assertCount(8, $context->get('autonomous_collector')['tool_results'] ?? []);
    }

    public function test_provider_native_function_call_executes_collector_tool(): void
    {
        $ai = $this->createMock(AIEngineService::class);
        $ai->expects($this->exactly(2))
            ->method('generate')
            ->willReturnOnConsecutiveCalls(
                new AIResponse(
                    content: '',
                    engine: 'openai',
                    model: 'gpt-4o',
                    functionCall: [
                        'name' => 'find_customer',
                        'arguments' => '{"query":"Acme"}',
                    ],
                ),
                AIResponse::success("```json\n{\"customer_id\":5}\n```")
            );

        $processor = $this->processor($ai);
        $context = new UnifiedActionContext('collector-native-tool-test');
        $state = new AutonomousCollectorSessionState(configName: 'invoice');
        $config = new AutonomousCollectorConfig(
            goal: 'Create invoice',
            tools: [
                'find_customer' => [
                    'description' => 'Find customer',
                    'parameters' => ['query' => 'required|string'],
                    'handler' => fn (string $query): array => ['success' => true, 'id' => 5, 'name' => $query],
                ],
            ],
            outputSchema: ['customer_id' => 'integer|required'],
            confirmBeforeComplete: true,
        );

        $response = $processor->process('collector-native-tool-test', 'create invoice for Acme', $config, $state, $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame(5, $response->data['collected_data']['customer_id'] ?? null);
        $this->assertSame('find_customer', $context->get('autonomous_collector')['tool_results'][0]['tool'] ?? null);
    }

    protected function processor(AIEngineService $ai): AutonomousCollectorTurnProcessor
    {
        $locale = app(LocaleResourceService::class);

        return new AutonomousCollectorTurnProcessor(
            ai: $ai,
            promptBuilder: new CollectorPromptBuilder($locale),
            parser: new CollectorToolCallParser(),
            toolExecution: new CollectorToolExecutionService(),
            confirmation: new CollectorConfirmationService($locale),
            summaryRenderer: new CollectorSummaryRenderer(),
            inputSchemaBuilder: new CollectorInputSchemaBuilder($locale),
            reroutePolicy: new CollectorReroutePolicy($locale),
            localeResources: $locale,
        );
    }
}
