<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\FollowUpStateService;
use LaravelAIEngine\Services\Agent\OrchestratorPromptBuilder;
use LaravelAIEngine\Services\Agent\UserProfileResolver;
use LaravelAIEngine\Services\Node\NodeMetadataDiscovery;
use Mockery;
use PHPUnit\Framework\TestCase;

class OrchestratorPromptBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_build_contains_resources_and_action_format(): void
    {
        $stateService = new FollowUpStateService();
        $profileResolver = new UserProfileResolver([
            'user_model' => null,
            'fields' => ['name', 'email'],
        ]);

        $discovery = Mockery::mock(NodeMetadataDiscovery::class);
        $discovery->shouldReceive('discover')->andReturn([
            'slug' => 'local',
            'description' => 'Local node',
            'domains' => ['billing'],
            'collections' => [['name' => 'Invoice', 'description' => 'Invoices']],
        ]);

        $builder = new OrchestratorPromptBuilder(
            $stateService,
            $profileResolver,
            $discovery,
            [
                'allowed_actions' => ['search_rag', 'conversational'],
                'default_action' => 'conversational',
                'instructions' => ['Test instruction'],
                'action_descriptions' => [
                    'search_rag' => 'Search local data',
                    'conversational' => 'General response',
                ],
            ]
        );

        $context = new UnifiedActionContext('session-1', null);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => '1. Invoice #1'],
        ];
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [1, 2],
            'entity_type' => 'invoice',
        ];

        $resources = [
            'collectors' => [['name' => 'invoice_collector', 'goal' => 'Create invoice', 'description' => 'Invoice flow', 'node' => 'local']],
            'tools' => [['name' => 'invoice.create', 'model' => 'Invoice', 'description' => 'Create invoice']],
            'nodes' => [['slug' => 'billing-node', 'description' => 'Billing operations', 'domains' => ['billing']]],
        ];

        $prompt = $builder->build('what is the total?', $resources, $context);

        $this->assertStringContainsString('ACTION: <search_rag|conversational>', $prompt);
        $this->assertStringContainsString('RESOURCE: <name or "none">', $prompt);
        $this->assertStringContainsString('Autonomous Collectors:', $prompt);
        $this->assertStringContainsString('REMOTE NODES:', $prompt);
    }
}
