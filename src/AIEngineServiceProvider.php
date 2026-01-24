<?php

declare(strict_types=1);

namespace LaravelAIEngine;

use Illuminate\Support\ServiceProvider;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\CacheManager;
use LaravelAIEngine\Services\RateLimitManager;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\ActionManager;
use LaravelAIEngine\Services\AnalyticsManager;
use LaravelAIEngine\Services\ActionHandlers\ButtonActionHandler;
use LaravelAIEngine\Services\ActionHandlers\QuickReplyActionHandler;
use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Streaming\WebSocketManager;
use LaravelAIEngine\Services\Analytics\AnalyticsManager as NewAnalyticsManager;
use LaravelAIEngine\Services\Analytics\Metrics\MetricsCollector;
use LaravelAIEngine\Services\Analytics\Drivers\DatabaseAnalyticsDriver;
use LaravelAIEngine\Services\Analytics\Drivers\RedisAnalyticsDriver;

class AIEngineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/ai-engine.php',
            'ai-engine'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/ai-agent.php',
            'ai-agent'
        );

        // Register AI Engine log channel
        $this->registerLogChannel();

        // Core AI Engine Services
        $this->registerCoreServices();

        // Enterprise Features
        $this->registerEnterpriseServices();


        // Service Aliases
        $this->registerAliases();
    }

    /**
     * Register AI Engine log channel
     */
    protected function registerLogChannel(): void
    {
        $this->app->make('config')->set('logging.channels.ai-engine', [
            'driver' => 'single',
            'path' => storage_path('logs/ai-engine.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ]);
    }

    /**
     * Register core AI engine services
     */
    protected function registerCoreServices(): void
    {
        // Register OpenAI Client (lazy - only throws error when actually used)
        $this->app->singleton(\OpenAI\Client::class, function ($app) {
            $apiKey = config('ai-engine.engines.openai.api_key');
            if (empty($apiKey)) {
                // Return a null client or throw only when methods are called
                // This prevents boot-time failures
                throw new \RuntimeException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
            }
            return \OpenAI::client($apiKey);
        });

        // Register dependencies first
        $this->app->singleton(CreditManager::class, function ($app) {
            return new CreditManager($app);
        });

        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager($app);
        });

        $this->app->singleton(RateLimitManager::class, function ($app) {
            return new RateLimitManager($app);
        });

        $this->app->singleton(AnalyticsManager::class, function ($app) {
            return new AnalyticsManager($app);
        });

        // Register AIEngineManager with all dependencies
        $this->app->singleton(AIEngineManager::class, function ($app) {
            return new AIEngineManager(
                $app,
                $app->make(CreditManager::class),
                $app->make(CacheManager::class),
                $app->make(RateLimitManager::class),
                $app->make(AnalyticsManager::class),
                $app->bound(\LaravelAIEngine\Services\Memory\MemoryManager::class)
                    ? $app->make(\LaravelAIEngine\Services\Memory\MemoryManager::class)
                    : null,
                $app->bound(ActionManager::class)
                    ? $app->make(ActionManager::class)
                    : null,
                $app->bound(WebSocketManager::class)
                    ? $app->make(WebSocketManager::class)
                    : null
            );
        });

        // ConversationManager - no constructor dependencies (RAG service is lazy-loaded)
        $this->app->singleton(ConversationManager::class, function ($app) {
            return new ConversationManager();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Memory\MemoryManager::class, function ($app) {
            return new \LaravelAIEngine\Services\Memory\MemoryManager();
        });

        // Register services from legacy provider that are still needed
        $this->app->singleton(\LaravelAIEngine\Services\AIEngineService::class, function ($app) {
            return new \LaravelAIEngine\Services\AIEngineService($app->make(CreditManager::class));
        });

        $this->app->singleton(\LaravelAIEngine\Services\BrandVoiceManager::class, function ($app) {
            return new \LaravelAIEngine\Services\BrandVoiceManager();
        });

        $this->app->singleton(\LaravelAIEngine\Services\WebhookManager::class, function ($app) {
            return new \LaravelAIEngine\Services\WebhookManager();
        });

        $this->app->singleton(\LaravelAIEngine\Services\TemplateManager::class, function ($app) {
            return new \LaravelAIEngine\Services\TemplateManager();
        });

        $this->app->singleton(\LaravelAIEngine\Services\ContentModerationService::class, function ($app) {
            return new \LaravelAIEngine\Services\ContentModerationService();
        });

        $this->app->singleton(\LaravelAIEngine\Services\JobStatusTracker::class, function ($app) {
            return new \LaravelAIEngine\Services\JobStatusTracker();
        });

        $this->app->singleton(\LaravelAIEngine\Services\QueuedAIProcessor::class, function ($app) {
            return new \LaravelAIEngine\Services\QueuedAIProcessor($app->make(\LaravelAIEngine\Services\JobStatusTracker::class));
        });

        $this->app->singleton(\LaravelAIEngine\Services\RAG\IntelligentRAGService::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\IntelligentRAGService(
                $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class),
                $app->make(AIEngineManager::class),
                $app->make(\LaravelAIEngine\Services\ConversationService::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\AIModelRegistry::class, function ($app) {
            return new \LaravelAIEngine\Services\AIModelRegistry();
        });

        $this->app->singleton(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery();
        });

        $this->app->singleton(\LaravelAIEngine\Services\DuplicateDetectionService::class, function ($app) {
            return new \LaravelAIEngine\Services\DuplicateDetectionService();
        });

        $this->app->singleton(\LaravelAIEngine\Services\DataCollector\DataCollectorService::class, function ($app) {
            return new \LaravelAIEngine\Services\DataCollector\DataCollectorService(
                $app->make(AIEngineManager::class),
                $app->make(\LaravelAIEngine\Services\ConversationService::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\DataCollector\DataCollectorChatService::class, function ($app) {
            return new \LaravelAIEngine\Services\DataCollector\DataCollectorChatService(
                $app->make(\LaravelAIEngine\Services\DataCollector\DataCollectorService::class),
                $app->make(\LaravelAIEngine\Services\ChatService::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\ModelResolver::class, function ($app) {
            return new \LaravelAIEngine\Services\ModelResolver();
        });

        $this->app->singleton(\LaravelAIEngine\Services\PendingActionService::class, function ($app) {
            return new \LaravelAIEngine\Services\PendingActionService();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class, function ($app) {
            return new \LaravelAIEngine\Services\Agent\AgentCollectionAdapter(
                $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class),
                $app->make(\LaravelAIEngine\Services\ModelAnalyzer::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\Agent\AgentMode::class, function ($app) {
            return new \LaravelAIEngine\Services\Agent\AgentMode();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class, function ($app) {
            $registry = new \LaravelAIEngine\Services\Agent\Tools\ToolRegistry();
            $registry->discoverFromConfig();
            return $registry;
        });

        $this->app->singleton(\LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class);
        $this->app->singleton(\LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class);
        $this->app->singleton(\LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class);
        $this->app->singleton(\LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class);

        // Register Agent Services (Intelligent Routing)
        $this->app->singleton(\LaravelAIEngine\Services\Agent\MessageAnalyzer::class, function ($app) {
            return new \LaravelAIEngine\Services\Agent\MessageAnalyzer(
                $app->make(\LaravelAIEngine\Services\IntentAnalysisService::class),
                $app->make(\LaravelAIEngine\Services\AIEngineService::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\Agent\ContextManager::class, function ($app) {
            return new \LaravelAIEngine\Services\Agent\ContextManager();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Agent\WorkflowDiscoveryService::class, function ($app) {
            return new \LaravelAIEngine\Services\Agent\WorkflowDiscoveryService();
        });

        // Register AgentOrchestrator (handlers instantiated per-request)
        $this->app->singleton(\LaravelAIEngine\Services\Agent\AgentOrchestrator::class, function ($app) {
            $orchestrator = new \LaravelAIEngine\Services\Agent\AgentOrchestrator(
                $app->make(\LaravelAIEngine\Services\Agent\MessageAnalyzer::class),
                $app->make(\LaravelAIEngine\Services\Agent\ContextManager::class)
            );
            
            // Register handlers (instantiated fresh each time orchestrator is created)
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\ContinueWorkflowHandler::class));
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\AnswerQuestionHandler::class));
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\SubWorkflowHandler::class));
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\CancelWorkflowHandler::class));
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\DirectAnswerHandler::class));
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\KnowledgeSearchHandler::class));
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\StartWorkflowHandler::class));
            $orchestrator->registerHandler($app->make(\LaravelAIEngine\Services\Agent\Handlers\ConversationalHandler::class));
            
            return $orchestrator;
        });
    }

    /**
     * Register enterprise services
     */
    protected function registerEnterpriseServices(): void
    {
        // Interactive Actions System
        $this->app->singleton(ActionManager::class, function ($app) {
            $manager = new ActionManager();

            // Register default action handlers
            $manager->registerHandler(new ButtonActionHandler());
            $manager->registerHandler(new QuickReplyActionHandler());

            return $manager;
        });

        // Automatic Failover System
        $this->app->singleton(FailoverManager::class, function ($app) {
            return new FailoverManager();
        });

        // WebSocket Streaming System (only if Ratchet is installed)
        if (interface_exists('Ratchet\MessageComponentInterface')) {
            $this->app->singleton(WebSocketManager::class, function ($app) {
                return new WebSocketManager();
            });
        }

        // Analytics and Monitoring System
        $this->app->singleton(MetricsCollector::class, function ($app) {
            return new MetricsCollector();
        });

        $this->app->singleton(DatabaseAnalyticsDriver::class, function ($app) {
            return new DatabaseAnalyticsDriver();
        });

        $this->app->singleton(RedisAnalyticsDriver::class, function ($app) {
            return new RedisAnalyticsDriver();
        });

        $this->app->singleton(NewAnalyticsManager::class, function ($app) {
            return new NewAnalyticsManager($app->make(MetricsCollector::class));
        });

        // Vector Search & Embeddings System
        $this->app->singleton(\LaravelAIEngine\Services\Vector\VectorDriverManager::class, function ($app) {
            return new \LaravelAIEngine\Services\Vector\VectorDriverManager();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Vector\EmbeddingService::class, function ($app) {
            return new \LaravelAIEngine\Services\Vector\EmbeddingService(
                $app->make(\OpenAI\Client::class),
                $app->make(CreditManager::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\Vector\VectorAccessControl::class, function ($app) {
            return new \LaravelAIEngine\Services\Vector\VectorAccessControl();
        });

        // Multi-Tenant Vector Service (for multi-database tenancy)
        $this->app->singleton(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class, function ($app) {
            return new \LaravelAIEngine\Services\Tenant\MultiTenantVectorService();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Vector\VectorSearchService::class, function ($app) {
            return new \LaravelAIEngine\Services\Vector\VectorSearchService(
                $app->make(\LaravelAIEngine\Services\Vector\VectorDriverManager::class),
                $app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class),
                $app->make(\LaravelAIEngine\Services\Vector\VectorAccessControl::class)
            );
        });

        // Media Processing Services
        $this->app->singleton(\LaravelAIEngine\Services\Media\VisionService::class, function ($app) {
            return new \LaravelAIEngine\Services\Media\VisionService(
                $app->make(\OpenAI\Client::class),
                $app->make(CreditManager::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\Media\AudioService::class, function ($app) {
            return new \LaravelAIEngine\Services\Media\AudioService(
                $app->make(\OpenAI\Client::class),
                $app->make(CreditManager::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\Media\VideoService::class, function ($app) {
            return new \LaravelAIEngine\Services\Media\VideoService(
                $app->make(\LaravelAIEngine\Services\Media\AudioService::class),
                $app->make(\LaravelAIEngine\Services\Media\VisionService::class)
            );
        });

        $this->app->singleton(\LaravelAIEngine\Services\Media\DocumentService::class, function ($app) {
            return new \LaravelAIEngine\Services\Media\DocumentService();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Media\MediaEmbeddingService::class, function ($app) {
            return new \LaravelAIEngine\Services\Media\MediaEmbeddingService(
                $app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class)
            );
        });

        // Vector Enterprise Services
        $this->app->singleton(\LaravelAIEngine\Services\Vector\VectorAuthorizationService::class, function ($app) {
            return new \LaravelAIEngine\Services\Vector\VectorAuthorizationService();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Vector\ChunkingService::class, function ($app) {
            return new \LaravelAIEngine\Services\Vector\ChunkingService();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Vector\VectorAnalyticsService::class, function ($app) {
            return new \LaravelAIEngine\Services\Vector\VectorAnalyticsService();
        });

        // Node Management Services
        $this->registerNodeServices();
    }

    /**
     * Register node management services
     */
    protected function registerNodeServices(): void
    {
        if (!config('ai-engine.nodes.enabled', true)) {
            return;
        }

        // Validate node configuration
        $this->validateNodeConfiguration();

        // Connection Pool Service
        $this->app->singleton(\LaravelAIEngine\Services\Node\NodeConnectionPool::class);

        // Auth Service
        $this->app->singleton(\LaravelAIEngine\Services\Node\NodeAuthService::class);

        // Circuit Breaker
        $this->app->singleton(\LaravelAIEngine\Services\Node\CircuitBreakerService::class);

        // Cache Service
        $this->app->singleton(\LaravelAIEngine\Services\Node\NodeCacheService::class);

        // Load Balancer
        $this->app->singleton(\LaravelAIEngine\Services\Node\LoadBalancerService::class);

        // Search Result Merger
        $this->app->singleton(\LaravelAIEngine\Services\Node\SearchResultMerger::class);

        // Registry Service
        $this->app->singleton(\LaravelAIEngine\Services\Node\NodeRegistryService::class, function ($app) {
            return new \LaravelAIEngine\Services\Node\NodeRegistryService(
                $app->make(\LaravelAIEngine\Services\Node\CircuitBreakerService::class),
                $app->make(\LaravelAIEngine\Services\Node\NodeAuthService::class)
            );
        });

        // Federated Search Service
        $this->app->singleton(\LaravelAIEngine\Services\Node\FederatedSearchService::class, function ($app) {
            return new \LaravelAIEngine\Services\Node\FederatedSearchService(
                $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class),
                $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class),
                $app->make(\LaravelAIEngine\Services\Node\NodeCacheService::class),
                $app->make(\LaravelAIEngine\Services\Node\CircuitBreakerService::class),
                $app->make(\LaravelAIEngine\Services\Node\LoadBalancerService::class),
                $app->make(\LaravelAIEngine\Services\Node\SearchResultMerger::class)
            );
        });

        // Remote Action Service
        $this->app->singleton(\LaravelAIEngine\Services\Node\RemoteActionService::class, function ($app) {
            return new \LaravelAIEngine\Services\Node\RemoteActionService(
                $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class),
                $app->make(\LaravelAIEngine\Services\Node\CircuitBreakerService::class),
                $app->make(\LaravelAIEngine\Services\Node\NodeAuthService::class)
            );
        });

        // Action Execution Service
        $this->app->singleton(\LaravelAIEngine\Services\ActionExecutionService::class, function ($app) {
            return new \LaravelAIEngine\Services\ActionExecutionService(
                $app->bound(\LaravelAIEngine\Services\ChatService::class)
                    ? $app->make(\LaravelAIEngine\Services\ChatService::class)
                    : null,
                $app->bound(\LaravelAIEngine\Services\AIEngineService::class)
                    ? $app->make(\LaravelAIEngine\Services\AIEngineService::class)
                    : null,
                $app->bound(\LaravelAIEngine\Services\Node\RemoteActionService::class)
                    ? $app->make(\LaravelAIEngine\Services\Node\RemoteActionService::class)
                    : null
            );
        });

        // Action System (Unified)
        $this->app->singleton(\LaravelAIEngine\Services\Actions\ActionRegistry::class);
        
        $this->app->singleton(\LaravelAIEngine\Services\Actions\ActionParameterExtractor::class, function ($app) {
            return new \LaravelAIEngine\Services\Actions\ActionParameterExtractor();
        });
        
        $this->app->singleton(\LaravelAIEngine\Services\Actions\ActionExecutionPipeline::class, function ($app) {
            return new \LaravelAIEngine\Services\Actions\ActionExecutionPipeline(
                $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
                $app->make(\LaravelAIEngine\Services\Actions\ActionParameterExtractor::class)
            );
        });
        
        $this->app->singleton(\LaravelAIEngine\Services\Actions\ActionManager::class, function ($app) {
            return new \LaravelAIEngine\Services\Actions\ActionManager(
                $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
                $app->make(\LaravelAIEngine\Services\Actions\ActionParameterExtractor::class),
                $app->make(\LaravelAIEngine\Services\Actions\ActionExecutionPipeline::class)
            );
        });

        // Template Engine
        $this->app->singleton(\LaravelAIEngine\Services\TemplateEngine::class, function ($app) {
            return new \LaravelAIEngine\Services\TemplateEngine(
                $app->bound(\LaravelAIEngine\Services\AIEngineService::class)
                    ? $app->make(\LaravelAIEngine\Services\AIEngineService::class)
                    : null
            );
        });
    }

    /**
     * Validate node configuration
     */
    protected function validateNodeConfiguration(): void
    {
        // Check JWT secret
        if (!config('ai-engine.nodes.jwt_secret')) {
            throw new \RuntimeException(
                'AI_ENGINE_JWT_SECRET is required when nodes are enabled. ' .
                'Set it in your .env file or disable nodes with AI_ENGINE_NODES_ENABLED=false'
            );
        }

        // Warn if master node doesn't have master_url configured
        if (config('ai-engine.nodes.is_master', true)) {
            if (!config('ai-engine.nodes.master_url')) {
                \Log::channel('ai-engine')->warning(
                    'Master node should have AI_ENGINE_MASTER_URL configured for child nodes to register'
                );
            }
        }

        // Validate connection pool settings
        if (config('ai-engine.nodes.connection_pool.enabled', true)) {
            $maxPerNode = config('ai-engine.nodes.connection_pool.max_per_node', 5);
            if ($maxPerNode < 1 || $maxPerNode > 100) {
                throw new \RuntimeException(
                    'AI_ENGINE_CONNECTION_POOL_MAX_PER_NODE must be between 1 and 100'
                );
            }

            $ttl = config('ai-engine.nodes.connection_pool.ttl', 300);
            if ($ttl < 60 || $ttl > 3600) {
                throw new \RuntimeException(
                    'AI_ENGINE_CONNECTION_POOL_TTL must be between 60 and 3600 seconds'
                );
            }
        }

        // Validate rate limiting settings
        if (config('ai-engine.nodes.rate_limit.enabled', true)) {
            $maxAttempts = config('ai-engine.nodes.rate_limit.max_attempts', 60);
            if ($maxAttempts < 1 || $maxAttempts > 10000) {
                throw new \RuntimeException(
                    'AI_ENGINE_RATE_LIMIT_MAX must be between 1 and 10000'
                );
            }

            $decayMinutes = config('ai-engine.nodes.rate_limit.decay_minutes', 1);
            if ($decayMinutes < 1 || $decayMinutes > 1440) {
                throw new \RuntimeException(
                    'AI_ENGINE_RATE_LIMIT_DECAY must be between 1 and 1440 minutes'
                );
            }
        }

        // Validate circuit breaker settings
        $failureThreshold = config('ai-engine.nodes.circuit_breaker.failure_threshold', 5);
        if ($failureThreshold < 1 || $failureThreshold > 100) {
            throw new \RuntimeException(
                'AI_ENGINE_CB_FAILURE_THRESHOLD must be between 1 and 100'
            );
        }

        $successThreshold = config('ai-engine.nodes.circuit_breaker.success_threshold', 2);
        if ($successThreshold < 1 || $successThreshold > 50) {
            throw new \RuntimeException(
                'AI_ENGINE_CB_SUCCESS_THRESHOLD must be between 1 and 50'
            );
        }

        // Validate timeout settings
        $timeout = config('ai-engine.nodes.request_timeout', 30);
        if ($timeout < 1 || $timeout > 300) {
            throw new \RuntimeException(
                'AI_ENGINE_REQUEST_TIMEOUT must be between 1 and 300 seconds'
            );
        }

        \Log::channel('ai-engine')->info('Node configuration validated successfully');
    }

    /**
     * Register service aliases
     */
    protected function registerAliases(): void
    {
        // Core aliases
        $this->app->alias(AIEngineManager::class, 'ai-engine');

        // Enterprise aliases
        $this->app->alias(ActionManager::class, 'ai-actions');
        $this->app->alias(FailoverManager::class, 'ai-failover');

        // Only alias WebSocketManager if it's registered
        if ($this->app->bound(WebSocketManager::class)) {
            $this->app->alias(WebSocketManager::class, 'ai-streaming');
        }

        $this->app->alias(NewAnalyticsManager::class, 'ai-analytics');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-engine.php' => config_path('ai-engine.php'),
            ], 'ai-engine-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-engine-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/ai-engine'),
            ], 'ai-engine-views');

            // Laravel 8 specific: publish components to app/View/Components for manual registration
            $this->publishes([
                __DIR__.'/../resources/views/components' => resource_path('views/components/ai-engine'),
            ], 'ai-engine-components');

            $this->publishes([
                __DIR__.'/../resources/js' => public_path('vendor/ai-engine/js'),
            ], 'ai-engine-assets');

            $this->publishes([
                __DIR__.'/../routes/api.php' => base_path('routes/ai-engine-api.php'),
            ], 'ai-engine-routes');

            $this->publishes([
                __DIR__.'/../routes/node-api.php' => base_path('routes/ai-engine-node-api.php'),
            ], 'ai-engine-node-routes');

            $this->commands([
                Console\Commands\TestEnginesCommand::class,
                Console\Commands\SyncModelsCommand::class,
                Console\Commands\UsageReportCommand::class,
                Console\Commands\ClearCacheCommand::class,
                Console\Commands\AnalyticsReportCommand::class,
                Console\Commands\FailoverStatusCommand::class,
                Console\Commands\StreamingServerCommand::class,
                Console\Commands\SystemHealthCommand::class,
                Console\Commands\TestPackageCommand::class,
                Console\Commands\VectorIndexCommand::class,
                Console\Commands\VectorSearchCommand::class,
                Console\Commands\VectorAnalyticsCommand::class,
                Console\Commands\VectorCleanCommand::class,
                Console\Commands\AnalyzeModelCommand::class,
                Console\Commands\VectorStatusCommand::class,
                Console\Commands\ListVectorizableModelsCommand::class,
                Console\Commands\GenerateVectorConfigCommand::class,
                Console\Commands\TestVectorJourneyCommand::class,
                Console\Commands\ConfigureAllModelsCommand::class,
                Console\Commands\TestMediaEmbeddingsCommand::class,
                Console\Commands\TestChunkingCommand::class,
                Console\Commands\TestLargeMediaCommand::class,
                Console\Commands\CreateQdrantIndexesCommand::class,
                Console\Commands\TestIntentAnalysisCommand::class,
                Console\Commands\TestDataCollectorCommand::class,
            ]);

            // Node Management Commands
            if (config('ai-engine.nodes.enabled', true)) {
                $this->commands([
                    Console\Commands\Node\MonitorNodesCommand::class,
                    Console\Commands\Node\RegisterNodeCommand::class,
                    Console\Commands\Node\UpdateNodeCommand::class,
                    Console\Commands\Node\ListNodesCommand::class,
                    Console\Commands\Node\PingNodesCommand::class,
                    Console\Commands\Node\NodeStatsCommand::class,
                    Console\Commands\Node\TestNodeSystemCommand::class,
                    Console\Commands\Node\DemoNodesCommand::class,
                    Console\Commands\Node\NodeLogsCommand::class,
                    Console\Commands\Node\DiscoverCollectionsCommand::class,
                ]);
            }
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');

        // Load demo routes conditionally (safe for config cache)
        if (config('ai-engine.enable_demo_routes', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Load API routes (check for published version first)
        $publishedApiRoutes = base_path('routes/ai-engine-api.php');
        if (file_exists($publishedApiRoutes)) {
            $this->loadRoutesFrom($publishedApiRoutes);
        } else {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        // Load node API routes (check for published version first)
        if (config('ai-engine.nodes.enabled', true)) {
            $publishedNodeApiRoutes = base_path('routes/ai-engine-node-api.php');
            if (file_exists($publishedNodeApiRoutes)) {
                $this->loadRoutesFrom($publishedNodeApiRoutes);
            } else {
                $this->loadRoutesFrom(__DIR__.'/../routes/node-api.php');
            }

            // Register middleware
            $router = $this->app['router'];
            $router->aliasMiddleware('node.auth', \LaravelAIEngine\Http\Middleware\NodeAuthMiddleware::class);
            $router->aliasMiddleware('node.rate_limit', \LaravelAIEngine\Http\Middleware\NodeRateLimitMiddleware::class);
        }

        // Register views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-engine');

        // Register Blade components
        $this->registerBladeComponents();

        // Register event listeners
        $this->registerEventListeners();

        // Register scheduled tasks
        $this->registerScheduledTasks();
    }

    /**
     * Register scheduled tasks for health monitoring
     */
    protected function registerScheduledTasks(): void
    {
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            // Ping nodes every 5 minutes (if nodes are enabled)
            if (config('ai-engine.nodes.enabled', false)) {
                $schedule->command('ai-engine:node-ping --all')
                    ->everyFiveMinutes()
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->appendOutputTo(storage_path('logs/ai-engine-ping.log'));
            }

            // Vector database health check every 10 minutes
            if (config('ai-engine.vector.health_check.enabled', true)) {
                $schedule->call(function () {
                    try {
                        $driver = app(\LaravelAIEngine\Services\Vector\VectorDriverManager::class)->driver();
                        // Use a simple collection check as health ping
                        $testCollection = config('ai-engine.vector.collection_prefix', 'vec_') . 'health_check';
                        $exists = $driver->collectionExists($testCollection);
                        \Log::channel('ai-engine')->debug('Vector DB health check passed', [
                            'connection' => 'ok',
                            'test_collection_exists' => $exists,
                        ]);
                    } catch (\Exception $e) {
                        \Log::channel('ai-engine')->error('Vector DB health check failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                })
                ->everyTenMinutes()
                ->name('ai-engine:vector-health-check')
                ->withoutOverlapping();
            }
        });
    }

    /**
     * Register event listeners for enterprise features
     */
    protected function registerEventListeners(): void
    {
        $events = $this->app['events'];

        // Analytics tracking listeners
        $events->listen(
            \LaravelAIEngine\Events\AIResponseChunk::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleResponseChunk']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIResponseComplete::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleResponseComplete']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIActionTriggered::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleActionTriggered']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIStreamingError::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleStreamingError']
        );

        $events->listen(
            \LaravelAIEngine\Events\AISessionStarted::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleSessionStarted']
        );

        $events->listen(
            \LaravelAIEngine\Events\AISessionEnded::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleSessionEnded']
        );

        // Logging listeners
        $events->listen(
            \LaravelAIEngine\Events\AIStreamingError::class,
            [\LaravelAIEngine\Listeners\StreamingLoggingListener::class, 'handleStreamingError']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIFailoverTriggered::class,
            [\LaravelAIEngine\Listeners\StreamingLoggingListener::class, 'handleFailoverTriggered']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIProviderHealthChanged::class,
            [\LaravelAIEngine\Listeners\StreamingLoggingListener::class, 'handleProviderHealthChanged']
        );

        // Notification listeners for critical events
        $events->listen(
            \LaravelAIEngine\Events\AIStreamingError::class,
            [\LaravelAIEngine\Listeners\StreamingNotificationListener::class, 'handleStreamingError']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIFailoverTriggered::class,
            [\LaravelAIEngine\Listeners\StreamingNotificationListener::class, 'handleFailoverTriggered']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIProviderHealthChanged::class,
            [\LaravelAIEngine\Listeners\StreamingNotificationListener::class, 'handleProviderHealthChanged']
        );
    }

    /**
     * Register Blade components
     */
    protected function registerBladeComponents(): void
    {
        $compiler = $this->app->make('blade.compiler');

        // Register anonymous components (Laravel 9+ only)
        // anonymousComponentPath was introduced in Laravel 9
        if (method_exists($compiler, 'anonymousComponentPath')) {
            $compiler->anonymousComponentPath(__DIR__.'/../resources/views/components', 'ai-engine');
        }

        // Register class-based components (if they exist)
        if (class_exists(\LaravelAIEngine\View\Components\AiChat::class)) {
            $compiler->component('ai-chat', \LaravelAIEngine\View\Components\AiChat::class);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            AIEngineManager::class,
            CreditManager::class,
            CacheManager::class,
            RateLimitManager::class,
            AnalyticsManager::class,
            'ai-engine',
        ];
    }
}
