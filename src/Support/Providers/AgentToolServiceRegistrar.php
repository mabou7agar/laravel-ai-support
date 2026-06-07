<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class AgentToolServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry($app));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner(
            $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService(
            $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentConversationService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\SubAgentConversationService(
            $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\ToolCallingSubAgentHandler::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\ToolCallingSubAgentHandler(
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\ConversationalSubAgentHandler::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\ConversationalSubAgentHandler(
            $app->make(\LaravelAIEngine\Services\Agent\AgentConversationService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\GoalAgentService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\GoalAgentService(
            $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner::class),
            $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class, function ($app) {
            $registry = new \LaravelAIEngine\Services\Agent\Tools\ToolRegistry();
            $registry->discoverFromConfig();
            foreach ([
                'search_learned_context' => \LaravelAIEngine\Services\Agent\Tools\SearchLearnedContextTool::class,
                'run_skill' => \LaravelAIEngine\Services\Agent\Tools\RunSkillTool::class,
            ] as $name => $class) {
                if (!$registry->has($name)) {
                    $registry->register($name, $app->make($class));
                }
            }
            if ((bool) config('ai-engine.learning.tools.agent_ingest_enabled', false) && !$registry->has('learn_source')) {
                $registry->register('learn_source', $app->make(\LaravelAIEngine\Services\Agent\Tools\LearnSourceTool::class));
            }
            if ((bool) config('ai-agent.goal_agent.register_sub_agent_tool', true) && !$registry->has('run_sub_agent')) {
                $registry->register('run_sub_agent', new \LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool());
            }
            if ((bool) config('ai-engine.data_query.enabled', true) && !$registry->has('data_query')) {
                $registry->register('data_query', $app->make(\LaravelAIEngine\Services\Agent\Tools\DataQueryTool::class));
            }
            // Exact aggregates (sum/avg/min/max/top/group-by) so analytics questions ("total
            // revenue", "most expensive invoice", "which customer spent the most") return real
            // figures instead of being estimated from a data_query list. Fails closed unless the
            // model declares 'aggregatable'/'groupable' columns in ai-engine.data_query.models.
            if ((bool) config('ai-engine.data_query.aggregate_enabled', true) && !$registry->has('aggregate_data')) {
                $registry->register('aggregate_data', $app->make(\LaravelAIEngine\Services\Agent\Tools\AggregateQueryTool::class));
            }
            // Generic file intake: extract a stored upload's text and suggest the create
            // action it implies (entity-agnostic, sandboxed). Opt-in — enable with
            // ai-engine.file_analysis.enabled and declare keyword_suggestions.
            if ((bool) config('ai-engine.file_analysis.enabled', false) && !$registry->has('analyze_file')) {
                $registry->register('analyze_file', $app->make(\LaravelAIEngine\Services\Agent\Tools\AnalyzeFileTool::class));
            }
            // Grounded knowledge-base retrieval. Without this the AiNative loop has no
            // way to reach the vector/document RAG store; it is also the tool a
            // force_rag turn is instructed to call (see AiNativePromptBuilder).
            if ((bool) config('ai-agent.ai_native.knowledge_tool_enabled', true) && !$registry->has('search_knowledge')) {
                $registry->register('search_knowledge', $app->make(\LaravelAIEngine\Services\Agent\Tools\SearchKnowledgeTool::class));
            }
            // Progressive disclosure: the planner loads full tool schemas on demand via
            // find_tools (the prompt lists tools by name + summary only).
            if ((string) config('ai-agent.ai_native.tool_selection.disclosure', 'full') === 'progressive' && !$registry->has('find_tools')) {
                $registry->register('find_tools', new \LaravelAIEngine\Services\Agent\Tools\FindToolsTool($registry));
            }

            // Config-declared resources: expose Eloquent models as find_<name>/create_<name>
            // tools with no code (see AiResource). Each entry mirrors the AiResource fluent
            // config, e.g. ['model' => Customer::class, 'search' => [...], 'writable' => [...]].
            foreach ((array) config('ai-agent.resources', []) as $key => $definition) {
                if (!is_array($definition) || empty($definition['model'])) {
                    continue;
                }
                \LaravelAIEngine\Services\Agent\Tools\AiResource::fromConfig(
                    is_string($key) ? $key : null,
                    $definition
                )->register($registry);
            }

            return $registry;
        });
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentOrchestrationInspector::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentOrchestrationInspector(
            $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class)
        ));

        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\ProjectAbilityScanner::class, fn ($app) => new \LaravelAIEngine\Services\Agent\ProjectAbilityScanner(
            $app->make(\LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class),
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentManifestDoctor::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentManifestDoctor(
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class),
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)
        ));
    }
}
