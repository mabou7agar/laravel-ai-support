<?php

declare(strict_types=1);

namespace LaravelAIEngine;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LaravelAIEngine\Services\ActionManager;
use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Streaming\WebSocketManager;
use LaravelAIEngine\Services\Analytics\AnalyticsManager as NewAnalyticsManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Support\Providers\AgentServiceRegistrar;
use LaravelAIEngine\Support\Providers\CoreServiceRegistrar;
use LaravelAIEngine\Support\Providers\EnterpriseServiceRegistrar;
use LaravelAIEngine\Support\Providers\NodeServiceRegistrar;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class AIEngineServiceProvider extends ServiceProvider
{
    protected static bool $startupGateChecked = false;

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

        $this->mergeNestedConfig(
            'ai-engine',
            \LaravelAIEngine\Support\Config\AIEngineConfigDefaults::defaults()
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
     * Laravel's mergeConfigFrom is shallow, so older published config files
     * would miss newer nested keys like engines.fal_ai. Merge recursively here
     * to preserve backward compatibility.
     */
    protected function mergeNestedConfig(string $key, array $defaults): void
    {
        $existing = $this->app->make('config')->get($key, []);
        $existing = is_array($existing) ? $existing : [];

        $this->app->make('config')->set(
            $key,
            array_replace_recursive($defaults, $existing)
        );
    }

    /**
     * Register AI Engine log channel
     */
    protected function registerLogChannel(): void
    {
        if ($this->app->make('config')->has('logging.channels.ai-engine')) {
            return;
        }

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
        CoreServiceRegistrar::register($this->app);
        $this->app->singleton(\LaravelAIEngine\Services\SDK\RerankingService::class);
        $this->app->singleton(\LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper::class);
        $this->app->singleton(\LaravelAIEngine\Services\SDK\FileStoreService::class);
        $this->app->singleton(\LaravelAIEngine\Services\SDK\RealtimeSessionService::class);
        $this->app->singleton(\LaravelAIEngine\Services\SDK\TraceRecorderService::class);
        $this->app->singleton(\LaravelAIEngine\Services\SDK\EvaluationService::class);
        $this->app->singleton(\LaravelAIEngine\Services\SDK\VectorStoreService::class);
        $this->registerDriverRegistry();
        AgentServiceRegistrar::register($this->app);
    }

    protected function registerDriverRegistry(): void
    {
        $this->app->afterResolving(DriverRegistry::class, function (DriverRegistry $registry): void {
            foreach ([
                \LaravelAIEngine\Enums\EngineEnum::OPENAI,
                \LaravelAIEngine\Enums\EngineEnum::ANTHROPIC,
                \LaravelAIEngine\Enums\EngineEnum::GEMINI,
                \LaravelAIEngine\Enums\EngineEnum::STABLE_DIFFUSION,
                \LaravelAIEngine\Enums\EngineEnum::ELEVEN_LABS,
                \LaravelAIEngine\Enums\EngineEnum::FAL_AI,
                \LaravelAIEngine\Enums\EngineEnum::OPENROUTER,
                \LaravelAIEngine\Enums\EngineEnum::NVIDIA_NIM,
                \LaravelAIEngine\Enums\EngineEnum::OLLAMA,
            ] as $engine) {
                $registry->register($engine, function () use ($engine) {
                    $engineEnum = new \LaravelAIEngine\Enums\EngineEnum($engine);
                    $driverClass = $engineEnum->driverClass();
                    $config = config("ai-engine.engines.{$engine}", []);

                    return new $driverClass($config);
                });
            }
        });
    }

    /**
     * Register enterprise services
     */
    protected function registerEnterpriseServices(): void
    {
        EnterpriseServiceRegistrar::register($this->app);
        $this->registerNodeServices();
    }

    /**
     * Register node management services
     */
    protected function registerNodeServices(): void
    {
        NodeServiceRegistrar::register($this->app);
    }

    /**
     * Register service aliases
     */
    protected function registerAliases(): void
    {
        // Core aliases
        $this->app->alias(\LaravelAIEngine\Services\UnifiedEngineManager::class, 'ai-engine');
        $this->app->alias(\LaravelAIEngine\Services\UnifiedEngineManager::class, 'unified-engine');

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

            $this->publishes([
                __DIR__.'/../resources/lang' => lang_path('vendor/ai-engine'),
            ], 'ai-engine-lang');

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
                Console\Commands\GenerateFalReferencePackCommand::class,
                Console\Commands\GenerateFalCharacterCommand::class,
                Console\Commands\TestAIMediaCommand::class,
                Console\Commands\TestEnginesCommand::class,
                Console\Commands\TestFalMediaCommand::class,
                Console\Commands\SyncAIModelsCommand::class,
                Console\Commands\ListAIModelsCommand::class,
                Console\Commands\UsageReportCommand::class,
                Console\Commands\ClearCacheCommand::class,
                Console\Commands\AnalyticsReportCommand::class,
                Console\Commands\FailoverStatusCommand::class,
                Console\Commands\StreamingServerCommand::class,
                Console\Commands\SystemHealthCommand::class,
                Console\Commands\InfrastructureHealthCommand::class,
                Console\Commands\TestPackageCommand::class,
                Console\Commands\TestEverythingCommand::class,
                Console\Commands\TestAgentOrchestrationCommand::class,
                Console\Commands\EvaluateRoutingFixturesCommand::class,
                Console\Commands\EvaluateRagFixturesCommand::class,
                Console\Commands\EvaluateRuntimeFixturesCommand::class,
                Console\Commands\BenchmarkOrchestrationV2Command::class,
                Console\Commands\AgentRuntimeCapabilitiesCommand::class,
                Console\Commands\ValidateAgentRuntimeConfigCommand::class,
                Console\Commands\RecoverStuckAgentRunsCommand::class,
                Console\Commands\ReplayFailedAgentRunStepCommand::class,
                Console\Commands\CleanupExpiredAgentRunsCommand::class,
                Console\Commands\AgentRunRetentionCleanupCommand::class,
                Console\Commands\BackendStatusCommand::class,
                Console\Commands\ModelStatusCommand::class,
                Console\Commands\VectorIndexCommand::class,
                Console\Commands\VectorSearchCommand::class,
                Console\Commands\VectorAnalyticsCommand::class,
                Console\Commands\VectorCleanCommand::class,
                Console\Commands\VectorFixIndexesCommand::class,
                Console\Commands\AnalyzeModelCommand::class,
                Console\Commands\VectorStatusCommand::class,
                Console\Commands\ListVectorizableModelsCommand::class,
                Console\Commands\GenerateVectorConfigCommand::class,
                Console\Commands\TestVectorJourneyCommand::class,
                Console\Commands\TestRAGFeaturesCommand::class,
                Console\Commands\ConfigureAllModelsCommand::class,
                Console\Commands\TestMediaEmbeddingsCommand::class,
                Console\Commands\TestChunkingCommand::class,
                Console\Commands\TestLargeMediaCommand::class,
                Console\Commands\CreateQdrantIndexesCommand::class,
                Console\Commands\TestIntentAnalysisCommand::class,
                Console\Commands\DecisionFeedbackReportCommand::class,
                Console\Commands\DecisionPolicyCreateCommand::class,
                Console\Commands\DecisionPolicyActivateCommand::class,
                Console\Commands\DecisionPolicyEvaluateCommand::class,
                Console\Commands\TestRealAgentFlowCommand::class,
                Console\Commands\TestDataCollectorCommand::class,
                Console\Commands\ListAutonomousCollectorsCommand::class,
                Console\Commands\ClearDiscoveryCacheCommand::class,
                Console\Commands\WarmDiscoveryCacheCommand::class,
                Console\Commands\InitAgentWorkspaceCommand::class,
                Console\Commands\InitNeo4jGraphCommand::class,
                Console\Commands\SyncNeo4jGraphCommand::class,
                Console\Commands\Neo4jKnowledgeBaseBuildCommand::class,
                Console\Commands\Neo4jKnowledgeBaseWarmCommand::class,
                Console\Commands\Neo4jGraphStatsCommand::class,
                Console\Commands\Neo4jGraphDiagnoseCommand::class,
                Console\Commands\Neo4jGraphRepairCommand::class,
                Console\Commands\Neo4jGraphDriftCommand::class,
                Console\Commands\Neo4jGraphBenchmarkCommand::class,
                Console\Commands\Neo4jGraphLoadBenchmarkCommand::class,
                Console\Commands\Neo4jIndexBenchmarkCommand::class,
                Console\Commands\GraphChatBenchmarkCommand::class,
                Console\Commands\GraphBenchmarkHistoryCommand::class,
                Console\Commands\GraphRankingFeedbackReportCommand::class,
                Console\Commands\ScaffoldAgentArtifactCommand::class,
                Console\Commands\MakeAgentCommand::class,
                Console\Commands\MakeToolCommand::class,
                Console\Commands\DiscoverAgentSkillsCommand::class,
                Console\Commands\TestAgentSkillCommand::class,
                Console\Commands\AgentManifestDoctorCommand::class,
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
                    Console\Commands\Node\BulkSyncNodesCommand::class,
                    Console\Commands\Node\CleanupNodesCommand::class,
                    Console\Commands\NodeDiscoverCommand::class,
                ]);
            }
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ai-engine');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');

        // Load demo routes conditionally (safe for config cache)
        if (config('ai-engine.enable_demo_routes', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Load admin UI routes conditionally (safe for config cache)
        if (config('ai-engine.admin_ui.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
        }

        // Load API routes (check for published version first)
        $publishedApiRoutes = base_path('routes/ai-engine-api.php');
        if (file_exists($publishedApiRoutes)) {
            $this->loadRoutesFrom($publishedApiRoutes);
        } else {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('ai-engine.locale', \LaravelAIEngine\Http\Middleware\SetRequestLocaleMiddleware::class);
        $router->aliasMiddleware('ai-engine.admin.access', \LaravelAIEngine\Http\Middleware\AdminAccessMiddleware::class);

        // Load node API routes (check for published version first)
        if (config('ai-engine.nodes.enabled', true)) {
            $publishedNodeApiRoutes = base_path('routes/ai-engine-node-api.php');
            if (file_exists($publishedNodeApiRoutes)) {
                $this->loadRoutesFrom($publishedNodeApiRoutes);
            } else {
                $this->loadRoutesFrom(__DIR__.'/../routes/node-api.php');
            }

            $router->aliasMiddleware('node.auth', \LaravelAIEngine\Http\Middleware\NodeAuthMiddleware::class);
            $router->aliasMiddleware('node.rate_limit', \LaravelAIEngine\Http\Middleware\NodeRateLimitMiddleware::class);
        }

        // Register views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-engine');

        // Register Blade components
        $this->registerBladeComponents();

        // Register event listeners
        $this->registerEventListeners();

        // Discover and register AutonomousCollectors
        $this->discoverAutonomousCollectors();

        // Register scheduled tasks
        $this->registerScheduledTasks();

        // Optional startup gate for infrastructure readiness
        $this->runStartupHealthGate();
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
     * Discover and register AutonomousCollectors from app directories
     */
    protected function discoverAutonomousCollectors(): void
    {
        try {
            $manifestCollectors = [];
            if ($this->app->bound(\LaravelAIEngine\Services\Agent\AgentManifestService::class)) {
                $manifestCollectors = $this->app
                    ->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)
                    ->collectors();
            }

            foreach ($manifestCollectors as $name => $manifestCollector) {
                $className = $manifestCollector['class'] ?? null;
                if (!$className || !class_exists($className)) {
                    continue;
                }

                $config = null;
                if (method_exists($className, 'getConfig')) {
                    $config = $className::getConfig();
                } elseif (method_exists($className, 'create')) {
                    $config = $className::create();
                }

                if (!$config) {
                    continue;
                }

                \LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::register($name, [
                    'config' => $config,
                    'goal' => (string) ($config->goal ?? ''),
                    'description' => (string) ($manifestCollector['description'] ?? ''),
                    'priority' => (int) ($manifestCollector['priority'] ?? 0),
                    'source' => 'manifest',
                ]);
            }

            $discoveryService = $this->app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class);
            $collectors = $discoveryService->discoverCollectors(useCache: true, includeRemote: true);

            foreach ($collectors as $name => $collectorData) {
                if (\LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::has($name)) {
                    continue;
                }

                $className = $collectorData['class'] ?? null;
                $source = $collectorData['source'] ?? 'local';
                $config = $collectorData['config'] ?? null;

                // For local collectors, instantiate the config
                if ($source === 'local' && $className && class_exists($className) && method_exists($className, 'getConfig')) {
                    $config = $className::getConfig();
                }

                // Register both local and remote collectors
                // Remote collectors will be handled by the orchestrator routing to nodes
                if ($config) {
                    \LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::register($name, [
                        'config' => $config,
                        'goal' => (string) ($config->goal ?? ''),
                        'description' => $collectorData['description'] ?? '',
                        'priority' => $collectorData['priority'] ?? 0,
                        'source' => $source,
                    ]);
                }
            }

            if (count($collectors) > 0) {
                \Illuminate\Support\Facades\Log::channel('ai-engine')->debug('Discovered AutonomousCollectors', [
                    'count' => count($collectors),
                    'names' => array_keys($collectors),
                    'manifest_count' => count($manifestCollectors),
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::channel('ai-engine')->warning('Failed to discover AutonomousCollectors', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function runStartupHealthGate(): void
    {
        if (self::$startupGateChecked) {
            return;
        }

        if (!config('ai-engine.infrastructure.startup_health_gate.enabled', true)) {
            self::$startupGateChecked = true;
            return;
        }

        if ($this->app->runningInConsole() && config('ai-engine.infrastructure.startup_health_gate.skip_in_console', true)) {
            self::$startupGateChecked = true;
            return;
        }

        $ttl = max(0, (int) config('ai-engine.infrastructure.startup_health_gate.cache_seconds', 60));
        $cacheKey = 'ai-engine:startup-health-gate-report';
        $service = $this->app->make(InfrastructureHealthService::class);

        try {
            $report = $ttl > 0
                ? Cache::remember($cacheKey, $ttl, fn (): array => $service->evaluate())
                : $service->evaluate();
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->error('Startup health gate check failed to execute', [
                'error' => $e->getMessage(),
            ]);

            if (config('ai-engine.infrastructure.startup_health_gate.strict', false)) {
                throw new \RuntimeException('AI Engine startup health gate failed to execute: ' . $e->getMessage(), previous: $e);
            }

            return;
        }

        if ((bool) ($report['ready'] ?? false)) {
            self::$startupGateChecked = true;
            return;
        }

        $message = $service->startupGateMessage($report);

        Log::channel('ai-engine')->critical('AI Engine startup health gate blocked readiness', [
            'message' => $message,
            'report' => $report,
        ]);

        if (config('ai-engine.infrastructure.startup_health_gate.strict', false)) {
            throw new \RuntimeException('AI Engine startup health gate failed: ' . $message);
        }

        self::$startupGateChecked = true;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            \LaravelAIEngine\Services\UnifiedEngineManager::class,
            CreditManager::class,
            CacheManager::class,
            RateLimitManager::class,
            AnalyticsManager::class,
            'ai-engine',
        ];
    }
}
