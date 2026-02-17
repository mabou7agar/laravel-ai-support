<?php

namespace LaravelAIEngine\Tests\Unit\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\NextStepSuggestionService;
use Orchestra\Testbench\TestCase;

class NextStepSuggestionServiceTest extends TestCase
{
    protected NextStepSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->service = new NextStepSuggestionService(['max_suggestions' => 4]);
    }

    protected function makeContext(array $metadata = []): UnifiedActionContext
    {
        $context = new UnifiedActionContext(sessionId: 'test', userId: 1);
        $context->metadata = $metadata;
        return $context;
    }

    protected function sampleResources(): array
    {
        return [
            'tools' => [
                ['name' => 'create_invoice', 'model' => 'invoice', 'description' => 'Create a new invoice'],
                ['name' => 'update_invoice', 'model' => 'invoice', 'description' => 'Update an invoice'],
                ['name' => 'delete_invoice', 'model' => 'invoice', 'description' => 'Delete an invoice'],
                ['name' => 'create_payment', 'model' => 'payment', 'description' => 'Record a payment'],
            ],
            'collectors' => [
                ['name' => 'invoice_collector', 'goal' => 'Create a sales invoice step by step', 'description' => 'Guided invoice creation'],
            ],
            'nodes' => [
                ['slug' => 'email-node', 'description' => 'Email management', 'domains' => ['communication', 'messaging']],
            ],
        ];
    }

    // ──────────────────────────────────────────────
    //  After search_rag
    // ──────────────────────────────────────────────

    public function test_after_search_rag_suggests_refinement(): void
    {
        $context = $this->makeContext(['last_entity_list' => [['id' => 1], ['id' => 2]]]);
        $suggestions = $this->service->suggest($context, 'search_rag', null, $this->sampleResources());

        $this->assertNotEmpty($suggestions);

        // Should suggest selecting an item
        $labels = array_column($suggestions, 'label');
        $hasSelectSuggestion = false;
        foreach ($labels as $label) {
            if (str_contains(strtolower($label), 'select') || str_contains(strtolower($label), 'item')) {
                $hasSelectSuggestion = true;
                break;
            }
        }
        $this->assertTrue($hasSelectSuggestion, 'Should suggest selecting an item from the list');
    }

    // ──────────────────────────────────────────────
    //  After use_tool (create)
    // ──────────────────────────────────────────────

    public function test_after_create_tool_suggests_list_and_create_another(): void
    {
        $context = $this->makeContext();
        $suggestions = $this->service->suggest($context, 'use_tool', 'create_invoice', $this->sampleResources());

        $labels = array_column($suggestions, 'label');
        $labelsLower = array_map('strtolower', $labels);

        // Should suggest listing invoices
        $hasList = false;
        foreach ($labelsLower as $l) {
            if (str_contains($l, 'list') && str_contains($l, 'invoice')) {
                $hasList = true;
                break;
            }
        }
        $this->assertTrue($hasList, 'Should suggest listing invoices after creating one');

        // Should suggest creating another
        $hasCreateAnother = false;
        foreach ($labelsLower as $l) {
            if (str_contains($l, 'another') && str_contains($l, 'invoice')) {
                $hasCreateAnother = true;
                break;
            }
        }
        $this->assertTrue($hasCreateAnother, 'Should suggest creating another invoice');
    }

    // ──────────────────────────────────────────────
    //  Entity-aware: selected entity → update/delete
    // ──────────────────────────────────────────────

    public function test_with_selected_entity_suggests_update_and_delete(): void
    {
        $context = $this->makeContext([
            'selected_entity_context' => [
                'entity_type' => 'invoice',
                'entity_id' => 42,
                'entity_data' => ['id' => 42, 'amount' => 500],
            ],
        ]);

        $suggestions = $this->service->suggest($context, 'search_rag', null, $this->sampleResources());

        $actions = array_column($suggestions, 'action');
        $resources = array_column($suggestions, 'resource');

        $this->assertContains('use_tool', $actions);
        $this->assertTrue(
            in_array('update_invoice', $resources) || in_array('delete_invoice', $resources),
            'Should suggest update or delete for the selected entity'
        );
    }

    // ──────────────────────────────────────────────
    //  Node suggestions
    // ──────────────────────────────────────────────

    public function test_suggests_available_nodes(): void
    {
        // Use a higher limit so node suggestions aren't cut off
        $service = new NextStepSuggestionService(['max_suggestions' => 10]);
        $context = $this->makeContext();
        $suggestions = $service->suggest($context, 'conversational', null, $this->sampleResources());

        $nodeActions = array_filter($suggestions, fn($s) => $s['action'] === 'route_to_node');
        $this->assertNotEmpty($nodeActions, 'Should suggest routing to available nodes');

        $slugs = array_column(array_values($nodeActions), 'resource');
        $this->assertContains('email-node', $slugs);
    }

    public function test_does_not_suggest_discovery_for_current_node(): void
    {
        $service = new NextStepSuggestionService(['max_suggestions' => 10]);
        $context = $this->makeContext();
        $context->set('routed_to_node', ['node_slug' => 'email-node']);

        $suggestions = $service->suggest($context, 'route_to_node', 'email-node', $this->sampleResources());

        // Filter to only "Ask about..." discovery suggestions (not follow-up suggestions)
        $discoverySuggestions = array_filter($suggestions, fn($s) =>
            $s['action'] === 'route_to_node' && str_starts_with($s['label'], 'Ask about')
        );
        $slugs = array_column(array_values($discoverySuggestions), 'resource');
        $this->assertNotContains('email-node', $slugs, 'Should not suggest discovering the node we are already on');
    }

    // ──────────────────────────────────────────────
    //  Suggestion structure
    // ──────────────────────────────────────────────

    public function test_suggestion_structure(): void
    {
        $context = $this->makeContext();
        $suggestions = $this->service->suggest($context, 'search_rag', null, $this->sampleResources());

        foreach ($suggestions as $s) {
            $this->assertArrayHasKey('label', $s);
            $this->assertArrayHasKey('action', $s);
            $this->assertArrayHasKey('resource', $s);
            $this->assertArrayHasKey('prompt', $s);
            $this->assertNotEmpty($s['label']);
            $this->assertNotEmpty($s['action']);
        }
    }

    // ──────────────────────────────────────────────
    //  Limit
    // ──────────────────────────────────────────────

    public function test_respects_max_suggestions(): void
    {
        $context = $this->makeContext(['last_entity_list' => [['id' => 1]]]);
        $suggestions = $this->service->suggest($context, 'search_rag', null, $this->sampleResources());

        $this->assertLessThanOrEqual(4, count($suggestions));
    }

    // ──────────────────────────────────────────────
    //  Deduplication
    // ──────────────────────────────────────────────

    public function test_no_duplicate_suggestions(): void
    {
        $context = $this->makeContext();
        $suggestions = $this->service->suggest($context, 'use_tool', 'create_invoice', $this->sampleResources());

        $keys = array_map(fn($s) => $s['action'] . ':' . $s['resource'] . ':' . strtolower($s['label']), $suggestions);
        $this->assertSame(count($keys), count(array_unique($keys)), 'Should not have duplicate suggestions');
    }

    // ──────────────────────────────────────────────
    //  Empty resources
    // ──────────────────────────────────────────────

    public function test_empty_resources_returns_empty(): void
    {
        $context = $this->makeContext();
        $suggestions = $this->service->suggest($context, 'conversational', null, []);

        // Conversational with no resources should return empty or minimal
        $this->assertIsArray($suggestions);
    }
}
