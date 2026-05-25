<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class AgentRagServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGDecisionEngine::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\RAGDecisionEngine(
                $app->make(\LaravelAIEngine\Services\AIEngineService::class),
                $app->make(\LaravelAIEngine\Contracts\RAGPipelineContract::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionStateService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGPlannerService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGToolExecutionService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGStructuredDataService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGContextService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGModelMetadataService::class),
                $app->make(\LaravelAIEngine\Services\Scope\AIScopeOptionsService::class)
            );
        });
    }
}
