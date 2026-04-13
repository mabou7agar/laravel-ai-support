<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class NodeServiceRegistrar
{
    public static function register($app): void
    {
        self::registerActionServices($app);

        if (!config('ai-engine.nodes.enabled', true)) {
            return;
        }

        self::validateConfiguration();

        $app->singleton(\LaravelAIEngine\Services\Node\NodeAuthService::class);
        $app->singleton(\LaravelAIEngine\Services\Node\CircuitBreakerService::class);
        $app->singleton(\LaravelAIEngine\Services\Node\NodeRegistryService::class, fn ($app) => new \LaravelAIEngine\Services\Node\NodeRegistryService($app->make(\LaravelAIEngine\Services\Node\CircuitBreakerService::class), $app->make(\LaravelAIEngine\Services\Node\NodeAuthService::class)));
        $app->singleton(\LaravelAIEngine\Services\Node\NodeOwnershipResolver::class, fn ($app) => new \LaravelAIEngine\Services\Node\NodeOwnershipResolver($app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class)));
        $app->singleton(\LaravelAIEngine\Services\Node\NodeManifestService::class, fn ($app) => new \LaravelAIEngine\Services\Node\NodeManifestService(
            $app->make(\LaravelAIEngine\Services\Node\NodeMetadataDiscovery::class),
            $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class),
            $app->make(\LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\RAG\UnifiedRAGSearchService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\UnifiedRAGSearchService(
            $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class),
            $app->make(\LaravelAIEngine\Services\AIEngineService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Node\NodeRouterService::class, fn ($app) => new \LaravelAIEngine\Services\Node\NodeRouterService($app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class), $app->make(\LaravelAIEngine\Services\Node\CircuitBreakerService::class), $app->make(\LaravelAIEngine\Services\Node\NodeOwnershipResolver::class)));
        $app->singleton(\LaravelAIEngine\Services\Node\RemoteActionService::class, fn ($app) => new \LaravelAIEngine\Services\Node\RemoteActionService($app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class), $app->make(\LaravelAIEngine\Services\Node\CircuitBreakerService::class), $app->make(\LaravelAIEngine\Services\Node\NodeAuthService::class)));
        $app->singleton(\LaravelAIEngine\Services\ActionExecutionService::class, fn ($app) => new \LaravelAIEngine\Services\ActionExecutionService($app->bound(\LaravelAIEngine\Services\ChatService::class) ? $app->make(\LaravelAIEngine\Services\ChatService::class) : null, $app->bound(\LaravelAIEngine\Services\AIEngineService::class) ? $app->make(\LaravelAIEngine\Services\AIEngineService::class) : null, $app->bound(\LaravelAIEngine\Services\Node\RemoteActionService::class) ? $app->make(\LaravelAIEngine\Services\Node\RemoteActionService::class) : null));
        $app->singleton(\LaravelAIEngine\Services\TemplateEngine::class, fn ($app) => new \LaravelAIEngine\Services\TemplateEngine($app->bound(\LaravelAIEngine\Services\AIEngineService::class) ? $app->make(\LaravelAIEngine\Services\AIEngineService::class) : null));
    }

    protected static function registerActionServices($app): void
    {
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionRegistry::class);
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionParameterExtractor::class, fn () => new \LaravelAIEngine\Services\Actions\ActionParameterExtractor());
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionExecutionPipeline::class, fn ($app) => new \LaravelAIEngine\Services\Actions\ActionExecutionPipeline($app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class), $app->make(\LaravelAIEngine\Services\Actions\ActionParameterExtractor::class)));
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionManager::class, fn ($app) => new \LaravelAIEngine\Services\Actions\ActionManager($app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class), $app->make(\LaravelAIEngine\Services\Actions\ActionParameterExtractor::class), $app->make(\LaravelAIEngine\Services\Actions\ActionExecutionPipeline::class)));
    }

    protected static function validateConfiguration(): void
    {
        $jwtSecret = config('ai-engine.nodes.jwt.secret') ?? config('ai-engine.nodes.jwt_secret');
        if ($jwtSecret === null || $jwtSecret === '') {
            throw new \RuntimeException(
                'AI_ENGINE_JWT_SECRET is required when nodes are enabled. Set it in your .env file or disable nodes with AI_ENGINE_NODES_ENABLED=false'
            );
        }

        if (config('ai-engine.nodes.is_master', true) && !config('ai-engine.nodes.master_url')) {
            \Log::channel('ai-engine')->warning('Master node should have AI_ENGINE_MASTER_URL configured for child nodes to register');
        }

        if (config('ai-engine.nodes.connection_pool.enabled', true)) {
            $maxPerNode = config('ai-engine.nodes.connection_pool.max_per_node', 5);
            if ($maxPerNode < 1 || $maxPerNode > 100) {
                throw new \RuntimeException('AI_ENGINE_CONNECTION_POOL_MAX_PER_NODE must be between 1 and 100');
            }

            $ttl = config('ai-engine.nodes.connection_pool.ttl', 300);
            if ($ttl < 60 || $ttl > 3600) {
                throw new \RuntimeException('AI_ENGINE_CONNECTION_POOL_TTL must be between 60 and 3600 seconds');
            }
        }

        if (config('ai-engine.nodes.rate_limit.enabled', true)) {
            $maxAttempts = config('ai-engine.nodes.rate_limit.max_attempts', 60);
            if ($maxAttempts < 1 || $maxAttempts > 10000) {
                throw new \RuntimeException('AI_ENGINE_RATE_LIMIT_MAX must be between 1 and 10000');
            }

            $decayMinutes = config('ai-engine.nodes.rate_limit.decay_minutes', 1);
            if ($decayMinutes < 1 || $decayMinutes > 1440) {
                throw new \RuntimeException('AI_ENGINE_RATE_LIMIT_DECAY must be between 1 and 1440 minutes');
            }
        }

        $failureThreshold = config('ai-engine.nodes.circuit_breaker.failure_threshold', 5);
        if ($failureThreshold < 1 || $failureThreshold > 100) {
            throw new \RuntimeException('AI_ENGINE_CB_FAILURE_THRESHOLD must be between 1 and 100');
        }

        $successThreshold = config('ai-engine.nodes.circuit_breaker.success_threshold', 2);
        if ($successThreshold < 1 || $successThreshold > 50) {
            throw new \RuntimeException('AI_ENGINE_CB_SUCCESS_THRESHOLD must be between 1 and 50');
        }

        $timeout = config('ai-engine.nodes.request_timeout', 30);
        if ($timeout < 1 || $timeout > 300) {
            throw new \RuntimeException('AI_ENGINE_REQUEST_TIMEOUT must be between 1 and 300 seconds');
        }

        \Log::channel('ai-engine')->info('Node configuration validated successfully');
    }
}
