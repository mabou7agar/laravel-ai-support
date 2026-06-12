<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\Vector\VectorDriverManager;

/**
 * One-shot orchestrator health check: reports what the AI router can actually
 * route to (tools, skills, RAG collections, nodes), key config, and provider
 * readiness — so "the orchestrator isn't doing anything" is diagnosable in one
 * command instead of guessing.
 */
class AiDoctorCommand extends Command
{
    protected $signature = 'ai:doctor
                            {--json : Output the report as JSON}
                            {--fail-on-warning : Exit non-zero if any warning is found}';

    protected $description = 'Diagnose the AI orchestrator: registered tools, skills, RAG collections, providers, and config readiness';

    public function handle(): int
    {
        $report = $this->collect();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($report);
        }

        $this->render($report);

        return $this->exitCode($report);
    }

    /**
     * @return array<string, mixed>
     */
    protected function collect(): array
    {
        $tools = $this->safe(fn () => array_keys(app(ToolRegistry::class)->getToolDefinitions()), []);
        $skills = $this->safe(fn () => array_map(
            static fn ($s) => is_object($s) ? ($s->id ?? get_class($s)) : (string) $s,
            app(AgentSkillRegistry::class)->skills()
        ), []);
        $collections = $this->safe(fn () => app(RAGCollectionDiscovery::class)->discover(), []);
        $nodes = $this->safe(fn () => app()->bound(NodeRegistryService::class)
            ? (array) app(NodeRegistryService::class)->getActiveNodes()
            : [], []);

        $defaultEngine = (string) config('ai-engine.default', 'openai');
        $apiKey = (string) config("ai-engine.engines.{$defaultEngine}.api_key", '');
        $vectorDriver = (string) config('ai-engine.vector.driver', config('ai-engine.vector.default_driver', 'qdrant'));
        $vectorOk = $this->safe(static function () use ($vectorDriver): bool {
            app(VectorDriverManager::class)->driver();

            return true;
        }, false);

        $counts = [
            'tools' => count($tools),
            'skills' => count($skills),
            'rag_collections' => count($collections),
            'nodes' => count($nodes),
        ];

        $engineHealth = $this->engineHealth();

        return [
            'counts' => $counts,
            'tools' => $tools,
            'skills' => $skills,
            'engines' => $engineHealth['engines'],
            'config' => [
                'agent_enabled' => (bool) config('ai-agent.enabled', true),
                'intent_understanding_mode' => (string) config('ai-agent.intent_understanding.mode', 'heuristic'),
                'skills_enabled' => (bool) config('ai-agent.skills.enabled', true),
                'manifest_fallback_discovery' => (bool) config('ai-agent.manifest.fallback_discovery', true),
                'default_engine' => $defaultEngine,
                'orchestration_model' => (string) config('ai-engine.orchestration_model', 'gpt-4o-mini'),
                'nodes_enabled' => (bool) config('ai-engine.nodes.enabled', true),
                'vector_driver' => $vectorDriver,
            ],
            'providers' => [
                'default_engine_api_key' => $apiKey !== '',
                'vector_driver_resolves' => $vectorOk,
            ],
            'warnings' => $this->warnings($counts, $tools, $apiKey !== '', $vectorOk, $engineHealth['keyless_in_chain']),
        ];
    }

    /**
     * Per-engine credential health for the default engine, every engine referenced in a
     * failover chain, and any engine that has an API key actually configured. The last
     * group matters because a key the user added (e.g. xai/Grok) would otherwise be
     * invisible here unless that engine happened to be the default or wired into a chain.
     * `keyless_in_chain` are fallback engines with no API key — they are skipped at
     * runtime, but flagged so the chain can be made meaningful.
     *
     * @return array{engines: array<string, bool>, keyless_in_chain: array<int, string>}
     */
    protected function engineHealth(): array
    {
        $default = (string) config('ai-engine.default', 'openai');
        $chains = (array) config('ai-engine.error_handling.fallback_engines', []);

        $names = [$default];
        foreach ($chains as $primary => $fallbacks) {
            $names[] = (string) $primary;
            foreach ((array) $fallbacks as $fallback) {
                $names[] = (string) $fallback;
            }
        }

        // Surface any engine the user has actually configured a key for, even if it is
        // neither the default nor part of a failover chain — otherwise an added key
        // (e.g. xai/Grok) never appears in the report and looks "unhandled".
        foreach ((array) config('ai-engine.engines', []) as $engine => $config) {
            if (is_array($config) && !empty($config['api_key'])) {
                $names[] = (string) $engine;
            }
        }

        $names = array_values(array_unique(array_filter($names, static fn ($n): bool => $n !== '')));

        $engines = [];
        $keylessInChain = [];
        foreach ($names as $name) {
            $config = config("ai-engine.engines.{$name}");
            $hasKeyConcept = is_array($config) && array_key_exists('api_key', $config);
            $engines[$name] = !$hasKeyConcept || !empty($config['api_key']);

            if ($hasKeyConcept && empty($config['api_key']) && $this->isFallbackEngine($name, $chains)) {
                $keylessInChain[] = $name;
            }
        }

        return ['engines' => $engines, 'keyless_in_chain' => array_values(array_unique($keylessInChain))];
    }

    /**
     * @param array<string, mixed> $chains
     */
    protected function isFallbackEngine(string $name, array $chains): bool
    {
        foreach ($chains as $fallbacks) {
            if (in_array($name, (array) $fallbacks, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $counts
     * @param array<int, string> $tools
     * @param array<int, string> $keylessInChain
     * @return array<int, string>
     */
    protected function warnings(array $counts, array $tools, bool $hasApiKey, bool $vectorOk, array $keylessInChain = []): array
    {
        $warnings = [];

        if ($keylessInChain !== []) {
            $warnings[] = 'Failover chain lists engine(s) with no API key: ' . implode(', ', $keylessInChain)
                . '. They are skipped at runtime (so they no longer surface a misleading "<engine> API key is required" '
                . 'error), but add their keys or remove them from ai-engine.error_handling.fallback_engines to make the chain meaningful.';
        }

        if ($counts['tools'] === 0 && $counts['skills'] === 0 && $counts['nodes'] === 0) {
            $warnings[] = 'No tools, skills, or nodes registered — the orchestrator will only ever answer conversationally/RAG. Register tools (app/AI/Tools or ModelToolConfig), skills (app/AI/Skills), or enable nodes.';
        }

        if (!in_array('data_query', $tools, true)) {
            $warnings[] = 'No "data_query" tool registered — structured list/count/filter queries fall back to RAG retrieval. Register a data_query tool for precise count/aggregate answers.';
        }

        if (!$hasApiKey) {
            $warnings[] = 'The default engine has no API key configured — orchestration calls will fail and routing will degrade to conversational.';
        }

        if ($counts['rag_collections'] === 0) {
            $warnings[] = 'No RAG collections discovered — search_rag will return nothing. Add the Vectorizable trait to a model (and index it) or set AI_ENGINE_RAG_COLLECTIONS.';
        }

        if (!$vectorOk) {
            $warnings[] = 'The vector driver could not be resolved — check the vector store configuration.';
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function render(array $report): void
    {
        $this->info('AI Orchestrator Doctor');
        $this->newLine();

        $this->table(['Capability', 'Count'], [
            ['Tools', $report['counts']['tools']],
            ['Skills', $report['counts']['skills']],
            ['RAG collections', $report['counts']['rag_collections']],
            ['Nodes', $report['counts']['nodes']],
        ]);

        $rows = [];
        foreach ($report['config'] as $key => $value) {
            $rows[] = [$key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value];
        }
        $rows[] = ['default_engine_api_key', $report['providers']['default_engine_api_key'] ? 'set' : 'MISSING'];
        $rows[] = ['vector_driver_resolves', $report['providers']['vector_driver_resolves'] ? 'yes' : 'NO'];
        $this->table(['Setting', 'Value'], $rows);

        if (!empty($report['engines'])) {
            $engineRows = [];
            foreach ($report['engines'] as $name => $configured) {
                $engineRows[] = [$name, $configured ? 'configured' : 'NO KEY'];
            }
            $this->table(['Engine', 'Key'], $engineRows);
        }

        if ($report['counts']['tools'] > 0) {
            $this->line('Tools: ' . implode(', ', $report['tools']));
        }
        if ($report['counts']['skills'] > 0) {
            $this->line('Skills: ' . implode(', ', $report['skills']));
        }

        $this->newLine();
        if ($report['warnings'] === []) {
            $this->info('No issues detected. The orchestrator has capabilities to route to.');
        } else {
            foreach ($report['warnings'] as $warning) {
                $this->warn('⚠ ' . $warning);
            }
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function exitCode(array $report): int
    {
        if ($this->option('fail-on-warning') && $report['warnings'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @param T $default
     * @return T
     */
    protected function safe(callable $callback, mixed $default): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
