<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Tests\UnitTestCase;

class LegacyActionCleanupTest extends UnitTestCase
{
    public function test_legacy_root_action_services_are_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\ActionManager'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\ActionExecutionService'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\ActionHandlers\\ButtonActionHandler'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\ActionHandlers\\QuickReplyActionHandler'));
        $this->assertFalse(interface_exists('LaravelAIEngine\\Contracts\\ActionHandlerInterface'));
        $this->assertFalse(interface_exists('LaravelAIEngine\\Contracts\\AIActionResponse'));
        $this->assertFalse(class_exists('LaravelAIEngine\\DTOs\\ActionResponse'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Resources\\AIActionResource'));
    }

    public function test_legacy_action_execution_routes_are_removed(): void
    {
        $this->postJson('/api/v1/actions/execute', [
            'action_type' => 'view_source',
            'data' => ['model_id' => 1],
        ])->assertNotFound();
    }

    public function test_legacy_rag_chat_service_is_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\RAG\\RAGChatService'));
    }

    public function test_deprecated_ai_engine_facade_is_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Facades\\AIEngine'));
    }

    public function test_unused_placeholder_services_are_removed(): void
    {
        foreach ([
            'LaravelAIEngine\\Repositories\\UserRepository',
            'LaravelAIEngine\\Services\\Chat\\ChatResponseFormatter',
            'LaravelAIEngine\\Services\\DataLoaderService',
            'LaravelAIEngine\\Services\\DocumentProcessor',
            'LaravelAIEngine\\Services\\MemoryOptimizationService',
            'LaravelAIEngine\\Services\\RAG\\ContextEnhancementService',
            'LaravelAIEngine\\Services\\RAG\\RAGAnalyzer',
            'LaravelAIEngine\\Services\\StorageManager',
        ] as $class) {
            $this->assertFalse(class_exists($class), "{$class} should not remain as an unused package surface.");
        }
    }

    public function test_legacy_chat_action_services_are_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\ActionService'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\DynamicActionService'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\PendingActionService'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\Chat\\ChatActionHandler'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Models\\PendingAction'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\TestDynamicActionsCommand'));
    }

    public function test_legacy_intelligent_crud_entity_resolver_services_are_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\IntelligentCRUDHandler'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\IntelligentPromptGenerator'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\IntentAnalysisService'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\GenericEntityResolver'));
    }

    public function test_ai_config_builder_does_not_default_to_legacy_entity_resolver(): void
    {
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\HasAIConfigBuilder'));
    }

    public function test_legacy_model_magic_ai_api_is_removed(): void
    {
        $this->assertFalse(interface_exists('LaravelAIEngine\\Contracts\\AIConfigurable'));
        $this->assertFalse(class_exists('LaravelAIEngine\\DTOs\\EntityFieldConfig'));
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\HasAIFeatures'));
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\HasAIActions'));
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\HasSimpleAIConfig'));
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\AutoResolvesRelationships'));
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\ResolvesAIRelationships'));
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\HasVectorChat'));
        $this->assertFalse(trait_exists('LaravelAIEngine\\Traits\\RAGgable'));
    }

    public function test_legacy_intent_analysis_command_and_config_are_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\TestIntentAnalysisCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\TestAgentCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\TestAiChatCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\TestDataCollectorHallucinationCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\TestEmailAssistantCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\TestIntelligentSearchCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\AddAIModelCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\DiscoverModelsForAgentCommand'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Console\\Commands\\ListRAGCollectionsCommand'));

        $provider = file_get_contents(__DIR__ . '/../../../src/AIEngineServiceProvider.php');
        $config = file_get_contents(__DIR__ . '/../../../src/Support/Config/AIEngineConfigDefaults.php');

        $this->assertStringNotContainsString('TestIntentAnalysisCommand', $provider);
        $this->assertStringNotContainsString('ai:test-agent', file_get_contents(__DIR__ . '/../../../src/Console/Commands/ScaffoldAgentArtifactCommand.php'));
        $this->assertStringNotContainsString('intent_analysis', $config);
        $this->assertStringNotContainsString('intent_model', $config);
    }

    public function test_dead_intelligent_entity_and_document_type_services_are_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\IntelligentEntityService'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\Actions\\DocumentTypeAnalyzer'));
    }

    public function test_legacy_demo_chat_and_auth_surfaces_are_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Controllers\\AIChatController'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Controllers\\AuthController'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Controllers\\Api\\ModelRecommendationController'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Services\\Auth\\AuthTokenService'));
        $this->assertFalse(class_exists('LaravelAIEngine\\View\\Components\\AiChat'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Requests\\ExecuteActionRequest'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Requests\\ExecuteDynamicActionRequest'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Requests\\ClearHistoryRequest'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Requests\\UploadFileRequest'));

        foreach ([
            'routes/chat.php',
            'routes/auth.php',
            'routes/web.php',
            'resources/views/components/ai-chat.blade.php',
            'resources/views/components/ai-chat-enhanced.blade.php',
            'resources/views/components/rag-chat.blade.php',
            'resources/views/demo/chat-enhanced.blade.php',
            'resources/views/demo/rag-chat-demo.blade.php',
            'resources/views/examples/chat-demo.blade.php',
            'resources/js/async-chat-example.js',
        ] as $path) {
            $this->assertFileDoesNotExist(__DIR__ . '/../../../' . $path);
        }
    }

    public function test_provider_and_config_do_not_reference_removed_demo_surfaces(): void
    {
        $provider = file_get_contents(__DIR__ . '/../../../src/AIEngineServiceProvider.php');
        $config = file_get_contents(__DIR__ . '/../../../src/Support/Config/AIEngineConfigDefaults.php');
        $routes = file_get_contents(__DIR__ . '/../../../routes/api.php');

        foreach ([
            'legacy_chat_routes',
            'auth_routes',
            'enable_demo_routes',
            'routes/chat.php',
            'routes/auth.php',
            'routes/web.php',
            'resources/js',
            'ai-engine-assets',
            'View\\Components\\AiChat',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $provider);
        }

        foreach ([
            'demo_user_id',
            'enable_demo_routes',
            'demo_route_prefix',
            'demo_route_middleware',
            'legacy_chat_routes',
            'auth_routes',
            'AI_ENGINE_API_DEMO_MIDDLEWARE',
            'AI_ENGINE_NODE_ROLE',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $config);
        }

        $this->assertStringNotContainsString('AIChatController', $routes);
        $this->assertStringNotContainsString('ai-demo', $routes);
    }

    public function test_stale_intelligent_prompt_resources_are_removed(): void
    {
        foreach ([
            'resources/prompts/en/ai_enhanced_workflow',
            'resources/prompts/en/entity',
            'resources/prompts/en/intelligent_crud',
            'resources/prompts/en/intelligent_prompt',
            'resources/prompts/en/workflow',
            'resources/prompts/ar/ai_enhanced_workflow',
            'resources/prompts/ar/entity',
            'resources/prompts/ar/intelligent_crud',
            'resources/prompts/ar/intelligent_prompt',
            'resources/prompts/ar/workflow',
        ] as $path) {
            $this->assertDirectoryDoesNotExist(__DIR__ . '/../../../' . $path);
        }

        foreach ([
            __DIR__ . '/../../../resources/lang/en/runtime.php',
            __DIR__ . '/../../../resources/lang/ar/runtime.php',
        ] as $langFile) {
            $contents = file_get_contents($langFile);
            $this->assertStringNotContainsString('intelligent_prompt', $contents);
            $this->assertStringNotContainsString('ai_enhanced_workflow', $contents);
            $this->assertStringNotContainsString('intelligent_crud', $contents);
        }
    }

    public function test_docs_and_request_collections_do_not_advertise_removed_legacy_endpoints(): void
    {
        $files = [
            __DIR__ . '/../../../docs-site/reference/api-reference.mdx',
            __DIR__ . '/../../../docs-site/reference/env-reference.mdx',
            __DIR__ . '/../../../docs-site/guides/troubleshooting.mdx',
            __DIR__ . '/../../../postman/Laravel-AI-Engine.postman_collection.json',
            __DIR__ . '/../../../bruno/laravel-ai-engine/README.md',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('/api/ai-chat', $contents, $file);
            $this->assertStringNotContainsString('/ai-demo', $contents, $file);
            $this->assertStringNotContainsString('/api/v1/actions', $contents, $file);
            $this->assertStringNotContainsString('AI_ENGINE_API_DEMO_MIDDLEWARE', $contents, $file);
            $this->assertStringNotContainsString('AI_ENGINE_LEGACY_CHAT_ROUTES_ENABLED', $contents, $file);
            $this->assertStringNotContainsString('AI_ENGINE_API_RESPONSE_PRESERVE_LEGACY', $contents, $file);
            $this->assertStringNotContainsString('AI_ENGINE_NODE_ROLE', $contents, $file);
            $this->assertStringNotContainsString('routes/chat.php', $contents, $file);
            $this->assertStringNotContainsString('routes/auth.php', $contents, $file);
            $this->assertStringNotContainsString('routes/web.php', $contents, $file);
        }

        foreach ([
            'bruno/laravel-ai-engine/AI Chat',
            'bruno/laravel-ai-engine/Auth',
            'bruno/laravel-ai-engine/Legacy AI Demo Chat',
            'bruno/laravel-ai-engine/Legacy Models',
            'bruno/laravel-ai-engine/Legacy Workflow',
            'bruno/laravel-ai-engine/V1 Actions',
        ] as $path) {
            $this->assertDirectoryDoesNotExist(__DIR__ . '/../../../' . $path);
        }
    }
}
