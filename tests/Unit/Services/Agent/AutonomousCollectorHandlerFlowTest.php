<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\Collectors\AutonomousCollectorTurnProcessor;
use LaravelAIEngine\Services\Agent\Collectors\CollectorConfirmationService;
use LaravelAIEngine\Services\Agent\Collectors\CollectorConfigResolver;
use LaravelAIEngine\Services\Agent\Collectors\CollectorInputSchemaBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorPromptBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorReroutePolicy;
use LaravelAIEngine\Services\Agent\Collectors\CollectorSummaryRenderer;
use LaravelAIEngine\Services\Agent\Collectors\CollectorToolCallParser;
use LaravelAIEngine\Services\Agent\Collectors\CollectorToolExecutionService;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AutonomousCollectorHandlerFlowTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        AutonomousCollectorRegistry::clear();

        parent::tearDown();
    }

    public function test_handler_runs_tool_collects_final_output_and_completes_after_confirmation(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->twice()
            ->andReturn(
                AIResponse::success("```tool\n{\"tool\":\"find_account\",\"arguments\":{\"query\":\"Acme\"}}\n```"),
                AIResponse::success("Ready.\n```json\n{\"account_id\":7,\"items\":[{\"name\":\"Subscription\",\"quantity\":2,\"unit_price\":50,\"total\":100}],\"total\":100}\n```")
            );

        $completed = false;
        $config = new AutonomousCollectorConfig(
            name: 'create_order_flow',
            goal: 'Create order',
            tools: [
                'find_account' => [
                    'description' => 'Find an account',
                    'handler' => fn (array $arguments): array => [
                        'success' => true,
                        'id' => 7,
                        'name' => $arguments['query'] ?? 'Acme',
                    ],
                ],
            ],
            outputSchema: [
                'account_id' => 'integer|required',
                'items' => ['type' => 'array', 'required' => true],
                'total' => 'numeric|required',
            ],
            onComplete: function (array $data) use (&$completed): array {
                $completed = true;

                return ['id' => 99, 'total' => $data['total']];
            },
        );

        $service = new AutonomousCollectorSessionService($ai);
        $handler = $this->handler($service, $ai);
        $context = new UnifiedActionContext('handler-flow-test');

        $review = $handler->handle('create order for Acme', $context, [
            'action' => 'start_autonomous_collector',
            'collector_match' => [
                'name' => 'create_order_flow',
                'config' => $config,
            ],
        ]);

        $this->assertTrue($review->needsUserInput);
        $this->assertStringContainsString('Please Review', $review->message);
        $this->assertFalse($completed);
        $this->assertSame('confirming', $context->get('autonomous_collector')['status'] ?? null);

        $done = $handler->handle('yes', $context);

        $this->assertTrue($done->success);
        $this->assertTrue($done->isComplete);
        $this->assertTrue($completed);
        $this->assertNull($context->get('autonomous_collector'));
        $this->assertSame(99, $done->data['result']['id'] ?? null);
    }

    protected function handler(AutonomousCollectorSessionService $service, AIEngineService $ai): AutonomousCollectorHandler
    {
        $locale = app(LocaleResourceService::class);
        $processor = new AutonomousCollectorTurnProcessor(
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

        return new AutonomousCollectorHandler(
            collectorService: $service,
            localeResources: $locale,
            configResolver: new CollectorConfigResolver($service),
            turnProcessor: $processor,
        );
    }
}
