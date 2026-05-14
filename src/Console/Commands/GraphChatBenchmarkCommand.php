<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService;

class GraphChatBenchmarkCommand extends Command
{
    protected $signature = 'ai-engine:chat-benchmark
                            {message : Chat message to benchmark}
                            {--collection=* : Restrict RAG to one or more model classes}
                            {--iterations= : Number of benchmark iterations}
                            {--engine= : Explicit AI engine override}
                            {--model= : Explicit AI model override}
                            {--user= : Authenticated user id}
                            {--session= : Explicit session id}
                            {--canonical-user-id= : Explicit canonical user id for scoped graph retrieval}
                            {--email= : Explicit normalized user email for scoped graph retrieval}
                            {--memory=0 : Enable conversation memory}
                            {--actions=0 : Enable action generation}
                            {--intelligent-rag=1 : Enable intelligent RAG}
                            {--force-rag=0 : Force graph/vector retrieval}
                            {--search-instructions= : Extra graph search instructions}
                            {--warm-cache : Prime retrieval caches before measurement}
                            {--index=}
                            {--property=}
                            {--scope-node=}
                            {--scope-tenant=}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Benchmark full chat latency, routing, and graph-backed retrieval';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);

        $this->applyOverrides();

        $chat = app(ChatService::class);
        $message = trim((string) $this->argument('message'));
        $collections = array_values(array_filter(array_map('strval', (array) $this->option('collection'))));
        $iterations = max(1, (int) ($this->option('iterations') ?: config('ai-engine.graph.benchmark.default_iterations', 5)));
        $engine = trim((string) ($this->option('engine') ?: config('ai-engine.default', 'openai')));
        $model = trim((string) ($this->option('model') ?: config('ai-engine.default_model', 'gpt-4o-mini')));
        $sessionId = trim((string) ($this->option('session') ?: ('chat-benchmark-' . uniqid())));
        $userId = ($this->option('user') !== null && $this->option('user') !== '') ? (string) $this->option('user') : null;
        $memory = $this->asBool($this->option('memory'));
        $actions = $this->asBool($this->option('actions'));
        $intelligentRag = $this->asBool($this->option('intelligent-rag'), true);
        $forceRag = $this->asBool($this->option('force-rag'));
        $searchInstructions = $this->option('search-instructions');
        $scope = $this->scope();

        $extraOptions = array_filter([
            'force_rag' => $forceRag,
            'access_scope' => $scope !== [] ? $scope : null,
        ], static fn ($value) => $value !== null);

        if ((bool) $this->option('warm-cache')) {
            $chat->processMessage(
                message: $message,
                sessionId: $sessionId . '-warm',
                engine: $engine,
                model: $model,
                useMemory: false,
                useActions: false,
                useRag: $intelligentRag,
                ragCollections: $collections,
                userId: $userId,
                searchInstructions: $searchInstructions,
                extraOptions: $extraOptions
            );
        }

        $durations = [];
        $responseLengths = [];
        $sourceCounts = [];
        $routeModes = [];
        $toolUsage = [];
        $plannerKinds = [];

        for ($i = 0; $i < $iterations; $i++) {
            $started = microtime(true);
            $response = $chat->processMessage(
                message: $message,
                sessionId: $sessionId . '-' . $i,
                engine: $engine,
                model: $model,
                useMemory: $memory,
                useActions: $actions,
                useRag: $intelligentRag,
                ragCollections: $collections,
                userId: $userId,
                searchInstructions: $searchInstructions,
                extraOptions: $extraOptions
            );
            $durations[] = (microtime(true) - $started) * 1000;

            $metadata = $this->normalizeMetadata($response->getMetadata());
            $responseLengths[] = mb_strlen((string) $response->getContent());
            $sourceCounts[] = count((array) ($metadata['sources'] ?? []));
            if (!empty($metadata['route_mode'])) {
                $routeModes[] = (string) $metadata['route_mode'];
            }
            if (!empty($metadata['tool_used'])) {
                $toolUsage[] = (string) $metadata['tool_used'];
            }
            if (!empty($metadata['planner_query_kind'])) {
                $plannerKinds[] = (string) $metadata['planner_query_kind'];
            }
        }

        $this->table(['Metric', 'Value'], [
            ['message', $message],
            ['collections', $collections === [] ? 'all' : implode(', ', $collections)],
            ['iterations', $iterations],
            ['engine', $engine],
            ['model', $model],
            ['avg_chat_ms', number_format($this->average($durations), 2)],
            ['min_chat_ms', number_format(min($durations), 2)],
            ['max_chat_ms', number_format(max($durations), 2)],
            ['avg_response_chars', number_format($this->average($responseLengths), 2)],
            ['avg_sources', number_format($this->average($sourceCounts), 2)],
            ['route_modes', $routeModes === [] ? 'n/a' : implode(', ', array_values(array_unique($routeModes)))],
            ['tools', $toolUsage === [] ? 'n/a' : implode(', ', array_values(array_unique($toolUsage)))],
            ['planner_query_kinds', $plannerKinds === [] ? 'n/a' : implode(', ', array_values(array_unique($plannerKinds)))],
        ]);

        app(GraphBenchmarkHistoryService::class)->record('chat', [
            'message' => $message,
            'avg_ms' => round($this->average($durations), 2),
            'details' => sprintf(
                'route=%s tool=%s planner=%s sources=%.2f',
                $routeModes === [] ? 'n/a' : implode('|', array_values(array_unique($routeModes))),
                $toolUsage === [] ? 'n/a' : implode('|', array_values(array_unique($toolUsage))),
                $plannerKinds === [] ? 'n/a' : implode('|', array_values(array_unique($plannerKinds))),
                $this->average($sourceCounts)
            ),
        ]);

        $this->info('Chat benchmark completed.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function scope(): array
    {
        return array_filter([
            'canonical_user_id' => $this->option('canonical-user-id') ?: null,
            'user_email_normalized' => $this->option('email') ? strtolower(trim((string) $this->option('email'))) : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    protected function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    protected function asBool(mixed $value, bool $default = false): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    protected function applyOverrides(): void
    {
        $map = [
            'url' => 'ai-engine.graph.neo4j.url',
            'database' => 'ai-engine.graph.neo4j.database',
            'username' => 'ai-engine.graph.neo4j.username',
            'password' => 'ai-engine.graph.neo4j.password',
            'index' => 'ai-engine.graph.neo4j.chunk_vector_index',
            'property' => 'ai-engine.graph.neo4j.chunk_vector_property',
            'scope-node' => 'ai-engine.graph.neo4j.vector_naming.node_slug',
            'scope-tenant' => 'ai-engine.graph.neo4j.vector_naming.tenant_key',
        ];

        foreach ($map as $option => $configKey) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                config()->set($configKey, trim($value));
            }
        }

        if (($timeout = $this->option('timeout')) !== null && $timeout !== '') {
            config()->set('ai-engine.graph.timeout', (int) $timeout);
        }
    }

    protected function normalizeMetadata(array $metadata): array
    {
        $contextMetadata = is_array($metadata['metadata'] ?? null) ? $metadata['metadata'] : [];

        foreach ([
            'tool_used',
            'fast_path',
            'route_mode',
            'decision_path',
            'decision_source',
            'graph_planned',
            'planner_strategy',
            'planner_query_kind',
        ] as $key) {
            if (!array_key_exists($key, $metadata) && array_key_exists($key, $contextMetadata)) {
                $metadata[$key] = $contextMetadata[$key];
            }
        }

        $ragMetadata = $contextMetadata['rag_last_metadata'] ?? ($metadata['rag_last_metadata'] ?? null);
        if (!is_array($ragMetadata)) {
            return $metadata;
        }

        foreach ([
            'rag_enabled',
            'sources',
            'graph_planned',
            'planner_strategy',
            'planner_query_kind',
        ] as $key) {
            if (!array_key_exists($key, $metadata) && array_key_exists($key, $ragMetadata)) {
                $metadata[$key] = $ragMetadata[$key];
            }
        }

        if (!array_key_exists('planner_query_kind', $metadata) && !empty($metadata['sources']) && is_array($metadata['sources'])) {
            $plannerQueryKind = collect($metadata['sources'])
                ->map(static fn ($source) => is_array($source) ? ($source['planner_query_kind'] ?? null) : null)
                ->filter()
                ->first();

            if ($plannerQueryKind !== null) {
                $metadata['planner_query_kind'] = $plannerQueryKind;
            }
        }

        return $metadata;
    }
}
