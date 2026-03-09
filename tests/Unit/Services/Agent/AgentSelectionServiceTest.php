<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentSelectionServiceTest extends UnitTestCase
{
    public function test_detects_numbered_option_selection_from_last_assistant_message(): void
    {
        $service = new AgentSelectionService($this->app->make(AgentResponseFinalizer::class));
        $context = new UnifiedActionContext('selection-session', 1, [
            ['role' => 'assistant', 'content' => "1. First\n2. Second"],
        ]);

        $this->assertTrue($service->detectsOptionSelection('2', $context));
        $this->assertFalse($service->detectsOptionSelection('11', $context));
    }

    public function test_capture_selection_state_builds_fallback_map_from_entity_ids(): void
    {
        Cache::put('rag_query_state:selection-map', [
            'start_position' => 3,
            'end_position' => 4,
            'entity_ids' => [41, 42],
            'model' => 'invoice',
            'model_class' => 'App\\Models\\Invoice',
            'entity_data' => [],
        ], now()->addMinutes(5));

        $service = new AgentSelectionService($this->app->make(AgentResponseFinalizer::class));
        $context = new UnifiedActionContext('selection-map', 1);

        $service->captureSelectionStateFromResult([
            'entity_ids' => [41, 42],
            'entity_type' => 'invoice',
            'metadata' => [],
        ], $context);

        $this->assertSame(41, $context->metadata['selection_map']['options']['3']['entity_id']);
        $this->assertSame(42, $context->metadata['selection_map']['options']['4']['entity_id']);
    }

    public function test_handle_positional_reference_routes_directly_to_source_node_when_known(): void
    {
        $service = new AgentSelectionService($this->app->make(AgentResponseFinalizer::class));
        $context = new UnifiedActionContext('selection-node-route', 1, metadata: [
            'selection_map' => [
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'options' => [
                    '1' => [
                        'entity_id' => 77,
                        'entity_type' => 'invoice',
                        'model_class' => 'App\\Models\\Invoice',
                        'source_node' => 'inbusiness',
                    ],
                ],
            ],
        ]);

        $searchCalls = 0;
        $routeCalls = 0;

        $response = $service->handlePositionalReference(
            'more info about 1',
            $context,
            [],
            function (string $message, UnifiedActionContext $ctx, array $options) use (&$searchCalls) {
                $searchCalls++;

                return AgentResponse::conversational('rag fallback response', $ctx);
            },
            function (string $node, string $message, UnifiedActionContext $ctx, array $options) use (&$routeCalls) {
                $routeCalls++;
                $this->assertSame('inbusiness', $node);
                $this->assertSame('show full details for invoice id 77', $message);

                return AgentResponse::conversational('node detail response', $ctx);
            }
        );

        $this->assertSame('node detail response', $response->message);
        $this->assertSame(1, $routeCalls);
        $this->assertSame(0, $searchCalls);
        $this->assertSame(77, $context->metadata['selected_entity_context']['entity_id']);
        $this->assertSame('inbusiness', $context->metadata['selected_entity_context']['source_node']);
    }

    public function test_handle_positional_reference_falls_back_to_rag_when_local_fetch_fails(): void
    {
        $service = new AgentSelectionService($this->app->make(AgentResponseFinalizer::class));
        $context = new UnifiedActionContext('selection-rag-fallback', 1, metadata: [
            'selection_map' => [
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'options' => [
                    '1' => [
                        'entity_id' => 88,
                        'entity_type' => 'invoice',
                        'model_class' => 'App\\Models\\Invoice',
                        'source_node' => null,
                    ],
                ],
            ],
        ]);

        $response = $service->handlePositionalReference(
            'more info about 1',
            $context,
            [],
            fn (string $message, UnifiedActionContext $ctx, array $options) => AgentResponse::conversational(
                "resolved by rag for {$message}",
                $ctx
            )
        );

        $this->assertStringContainsString('resolved by rag for show full details for invoice id 88', $response->message);
        $this->assertStringNotContainsString("couldn't retrieve its details", $response->message);
        $this->assertSame(88, $context->metadata['selected_entity_context']['entity_id']);
    }
}
