<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\DynamicActionService;
use LaravelAIEngine\Services\ChatService;

class TestDynamicActionsCommand extends Command
{
    protected $signature = 'ai:test-dynamic-actions 
                            {--discover : Discover available actions}
                            {--query= : Test action recommendation with a query}
                            {--execute= : Execute an action by ID}
                            {--chat : Test with AI chat integration}';

    protected $description = 'Test dynamic action discovery and execution';

    public function handle(): int
    {
        $this->info('🎯 Dynamic Action System Test');
        $this->newLine();

        $dynamicActionService = app(DynamicActionService::class);

        if ($this->option('discover')) {
            return $this->discoverActions($dynamicActionService);
        }

        if ($query = $this->option('query')) {
            return $this->testRecommendations($dynamicActionService, $query);
        }

        if ($actionId = $this->option('execute')) {
            return $this->testExecution($dynamicActionService, $actionId);
        }

        if ($this->option('chat')) {
            return $this->testChatIntegration();
        }

        // Default: show all options
        $this->showMenu($dynamicActionService);

        return 0;
    }

    protected function discoverActions(DynamicActionService $service): int
    {
        $this->info('🔍 Discovering available actions...');
        $this->newLine();

        $actions = $service->discoverActions();

        if (empty($actions)) {
            $this->warn('No actions discovered.');
            $this->info('💡 Tip: Create models with API endpoints or configure actions in config/ai-actions.php');
            return 0;
        }

        $this->info("Found " . count($actions) . " actions:");
        $this->newLine();

        $tableData = [];
        foreach ($actions as $action) {
            $tableData[] = [
                'ID' => $action['id'],
                'Label' => $action['label'],
                'Type' => $action['type'],
                'Endpoint' => $action['endpoint'] ?? 'N/A',
                'Method' => $action['method'] ?? 'N/A',
            ];
        }

        $this->table(
            ['ID', 'Label', 'Type', 'Endpoint', 'Method'],
            $tableData
        );

        $this->newLine();
        $this->info('✅ Action discovery complete!');

        return 0;
    }

    protected function testRecommendations(DynamicActionService $service, string $query): int
    {
        $this->info("🤖 Finding actions for query: \"{$query}\"");
        $this->newLine();

        $recommended = $service->getRecommendedActions($query);

        if (empty($recommended)) {
            $this->warn('No matching actions found.');
            return 0;
        }

        $this->info("Found " . count($recommended) . " recommended actions:");
        $this->newLine();

        foreach ($recommended as $action) {
            $this->line("📌 {$action['label']}");
            $this->line("   ID: {$action['id']}");
            $this->line("   Description: {$action['description']}");
            
            if (isset($action['endpoint'])) {
                $this->line("   API: {$action['method']} {$action['endpoint']}");
            }
            
            if (!empty($action['required_fields'])) {
                $this->line("   Required: " . implode(', ', $action['required_fields']));
            }
            
            if (!empty($action['example_payload'])) {
                $this->line("   Example: " . json_encode($action['example_payload']));
            }
            
            if (isset($action['relevance_score'])) {
                $this->line("   Relevance: {$action['relevance_score']}/10");
            }
            
            $this->newLine();
        }

        return 0;
    }

    protected function testExecution(DynamicActionService $service, string $actionId): int
    {
        $this->info("⚡ Testing execution of action: {$actionId}");
        $this->newLine();

        // Find the action
        $actions = $service->discoverActions();
        $action = collect($actions)->firstWhere('id', $actionId);

        if (!$action) {
            $this->error("Action '{$actionId}' not found!");
            return 1;
        }

        $this->info("Action: {$action['label']}");
        $this->info("Description: {$action['description']}");
        $this->newLine();

        // Get parameters from user
        $parameters = [];
        
        if (!empty($action['required_fields'])) {
            $this->info('Required fields:');
            foreach ($action['required_fields'] as $field) {
                $example = $action['example_payload'][$field] ?? '';
                $value = $this->ask("Enter {$field}" . ($example ? " (example: {$example})" : ''));
                $parameters[$field] = $value;
            }
        }

        // Execute
        $this->newLine();
        $this->info('🚀 Executing action...');
        
        $result = $service->executeAction($action, $parameters);

        if ($result['success']) {
            $this->info('✅ Action prepared successfully!');
            $this->newLine();
            
            if (isset($result['curl_example'])) {
                $this->line('📋 cURL Example:');
                $this->line($result['curl_example']);
                $this->newLine();
            }
            
            $this->line('Response:');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->error('❌ Action failed: ' . $result['error']);
            
            if (isset($result['required_fields'])) {
                $this->newLine();
                $this->info('Required fields: ' . implode(', ', $result['required_fields']));
            }
        }

        return 0;
    }

    protected function testChatIntegration(): int
    {
        $this->info('💬 Testing AI Chat Integration with Dynamic Actions');
        $this->newLine();

        $chatService = app(ChatService::class);

        // Test scenarios
        $scenarios = [
            "I need to create a new blog post about Laravel",
            "Can you help me send an email to the team?",
            "Schedule a meeting for tomorrow at 2 PM",
            "Create a task to review the Q4 budget",
        ];

        foreach ($scenarios as $index => $query) {
            $this->info("Scenario " . ($index + 1) . ": {$query}");
            $this->line(str_repeat('─', 60));

            try {
                $response = $chatService->processMessage(
                    message: $query,
                    sessionId: 'dynamic-actions-test',
                    useMemory: false,
                    useActions: true
                );

                $this->line($response->getContent());
                $this->newLine();

            } catch (\Exception $e) {
                $this->error('Error: ' . $e->getMessage());
            }

            $this->newLine();
        }

        return 0;
    }

    protected function showMenu(DynamicActionService $service): void
    {
        $actions = $service->discoverActions();
        
        $this->info("📊 Dynamic Action System");
        $this->newLine();
        $this->info("Available actions: " . count($actions));
        $this->newLine();
        
        $this->info('Usage:');
        $this->line('  php artisan ai:test-dynamic-actions --discover');
        $this->line('  php artisan ai:test-dynamic-actions --query="create a blog post"');
        $this->line('  php artisan ai:test-dynamic-actions --execute=create_post');
        $this->line('  php artisan ai:test-dynamic-actions --chat');
        $this->newLine();
        
        if (!empty($actions)) {
            $this->info('Quick preview of available actions:');
            foreach (array_slice($actions, 0, 5) as $action) {
                $this->line("  • {$action['label']} ({$action['id']})");
            }
            if (count($actions) > 5) {
                $this->line("  ... and " . (count($actions) - 5) . " more");
            }
        }
    }
}
