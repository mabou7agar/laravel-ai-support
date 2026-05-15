<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;

class Neo4jGraphLoadBenchmarkCommand extends Command
{
    protected $signature = 'ai:neo4j-load-benchmark
                            {--mode=retrieval : retrieval, chat, or mixed}
                            {--profile= : smoke, interactive, steady, burst, or stress}
                            {--query=* : Retrieval query payload(s)}
                            {--message=* : Chat message payload(s)}
                            {--collection=* : Restrict model classes}
                            {--iterations=20 : Total operations to execute}
                            {--concurrency= : Concurrent workers when pcntl is available}
                            {--engine= : Chat engine override}
                            {--model= : Chat model override}
                            {--canonical-user-id=}
                            {--email=}
                            {--index=}
                            {--property=}
                            {--scope-node=}
                            {--scope-tenant=}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Run larger graph retrieval/chat load benchmarks with optional worker concurrency';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        $this->applyOverrides();

        $profile = $this->profileDefinition((string) ($this->option('profile') ?: ''));
        $mode = trim((string) ($this->optionWasProvided('mode') ? $this->option('mode') : ($profile['mode'] ?? 'retrieval'))) ?: 'retrieval';
        $queries = array_values(array_filter(array_map('strval', (array) $this->option('query'))));
        $messages = array_values(array_filter(array_map('strval', (array) $this->option('message'))));
        $iterations = max(1, (int) ($this->optionWasProvided('iterations') ? $this->option('iterations') : ($profile['iterations'] ?? 20)));
        $concurrency = max(1, (int) ($this->optionWasProvided('concurrency') ? $this->option('concurrency') : ($profile['concurrency'] ?? config('ai-engine.graph.benchmark.default_load_concurrency', 4))));
        $collections = array_values(array_filter(array_map('strval', (array) $this->option('collection'))));
        $scope = array_filter([
            'canonical_user_id' => $this->option('canonical-user-id') ?: null,
            'user_email_normalized' => $this->option('email') ? strtolower(trim((string) $this->option('email'))) : null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($queries === []) {
            $queries = $profile['queries'] ?? ['who owns Apollo?', 'what changed this week around Apollo?'];
        }
        if ($messages === []) {
            $messages = $profile['messages'] ?? ['What changed for Apollo?', 'Who owns Apollo and what is it related to?'];
        }

        $started = microtime(true);
        $latencies = $this->supportsForking() && $concurrency > 1
            ? $this->runConcurrent($mode, $queries, $messages, $collections, $iterations, $concurrency, $scope)
            : $this->runSequential($mode, $queries, $messages, $collections, $iterations, $scope);
        $elapsed = (microtime(true) - $started) * 1000;

        sort($latencies);
        $avg = $this->average($latencies);
        $p95 = $latencies === [] ? 0.0 : $latencies[(int) floor((count($latencies) - 1) * 0.95)];

        $this->table(['Metric', 'Value'], [
            ['profile', $profile['name'] ?? 'custom'],
            ['mode', $mode],
            ['iterations', $iterations],
            ['concurrency', $this->supportsForking() ? $concurrency : 1],
            ['avg_ms', number_format($avg, 2)],
            ['p95_ms', number_format($p95, 2)],
            ['max_ms', number_format($latencies === [] ? 0 : max($latencies), 2)],
            ['wall_clock_ms', number_format($elapsed, 2)],
            ['ops_per_second', number_format($elapsed > 0 ? (($iterations / $elapsed) * 1000) : 0, 2)],
        ]);

        app(GraphBenchmarkHistoryService::class)->record($mode === 'chat' ? 'chat' : 'retrieval', [
            'query' => ($profile['name'] ?? 'custom') . ':' . $mode,
            'avg_ms' => round($avg, 2),
            'details' => sprintf('load profile=%s mode=%s iterations=%d concurrency=%d p95=%.2f', $profile['name'] ?? 'custom', $mode, $iterations, $this->supportsForking() ? $concurrency : 1, $p95),
        ]);

        $this->info('Neo4j load benchmark completed.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, float>
     */
    protected function runSequential(string $mode, array $queries, array $messages, array $collections, int $iterations, array $scope): array
    {
        $latencies = [];
        for ($i = 0; $i < $iterations; $i++) {
            $latencies[] = $this->runOne($mode, $queries[$i % count($queries)], $messages[$i % count($messages)], $collections, $scope, $i);
        }

        return $latencies;
    }

    /**
     * @return array<int, float>
     */
    protected function runConcurrent(string $mode, array $queries, array $messages, array $collections, int $iterations, int $concurrency, array $scope): array
    {
        $tempFiles = [];
        $children = [];
        $chunks = array_chunk(range(0, $iterations - 1), (int) ceil($iterations / $concurrency));

        foreach ($chunks as $workerIndex => $indexes) {
            $tempFile = tempnam(sys_get_temp_dir(), 'ai-engine-load-');
            if ($tempFile === false) {
                continue;
            }

            $pid = pcntl_fork();
            if ($pid === -1) {
                @unlink($tempFile);
                continue;
            }

            if ($pid === 0) {
                $latencies = [];
                foreach ($indexes as $i) {
                    $latencies[] = $this->runOne($mode, $queries[$i % count($queries)], $messages[$i % count($messages)], $collections, $scope, $i);
                }
                file_put_contents($tempFile, json_encode($latencies));
                exit(0);
            }

            $children[] = $pid;
            $tempFiles[] = $tempFile;
        }

        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
        }

        $latencies = [];
        foreach ($tempFiles as $tempFile) {
            $decoded = json_decode((string) file_get_contents($tempFile), true);
            if (is_array($decoded)) {
                foreach ($decoded as $latency) {
                    if (is_numeric($latency)) {
                        $latencies[] = (float) $latency;
                    }
                }
            }
            @unlink($tempFile);
        }

        return $latencies;
    }

    protected function runOne(string $mode, string $query, string $message, array $collections, array $scope, int $iteration): float
    {
        $started = microtime(true);

        if ($mode === 'chat' || ($mode === 'mixed' && $iteration % 2 === 1)) {
            app(ChatService::class)->processMessage(
                message: $message,
                sessionId: 'load-benchmark-' . $iteration,
                engine: (string) ($this->option('engine') ?: config('ai-engine.default', 'openai')),
                model: (string) ($this->option('model') ?: config('ai-engine.default_model', 'gpt-4o-mini')),
                useMemory: false,
                useActions: false,
                useRag: true,
                ragCollections: $collections,
                userId: null,
                searchInstructions: null,
                extraOptions: array_filter(['access_scope' => $scope ?: null])
            );
        } else {
            app(Neo4jRetrievalService::class)->retrieveRelevantContext(
                [$query],
                $collections,
                max(3, (int) config('ai-engine.graph.benchmark.default_max_results', 5)),
                $scope === [] ? [] : ['access_scope' => $scope],
                null
            );
        }

        return (microtime(true) - $started) * 1000;
    }

    protected function supportsForking(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    protected function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
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

    protected function optionWasProvided(string $name): bool
    {
        $definition = $this->getDefinition()->getOption($name);
        $current = $this->option($name);

        return $current !== $definition->getDefault();
    }

    /**
     * @return array{name:string,mode:string,iterations:int,concurrency:int,queries:array<int,string>,messages:array<int,string>}
     */
    protected function profileDefinition(string $profile): array
    {
        $profile = strtolower(trim($profile));

        return match ($profile) {
            'smoke' => [
                'name' => 'smoke',
                'mode' => 'retrieval',
                'iterations' => 5,
                'concurrency' => 1,
                'queries' => ['who owns Apollo?', 'what changed for Apollo?'],
                'messages' => ['What changed for Apollo?'],
            ],
            'interactive' => [
                'name' => 'interactive',
                'mode' => 'mixed',
                'iterations' => 12,
                'concurrency' => 2,
                'queries' => ['what changed this week around Apollo?', 'who is related to Apollo?'],
                'messages' => ['What changed for Apollo?', 'Who owns Apollo and what is it related to?'],
            ],
            'steady' => [
                'name' => 'steady',
                'mode' => 'mixed',
                'iterations' => 30,
                'concurrency' => 4,
                'queries' => ['what changed this week around Apollo?', 'who owns Apollo?', 'show Apollo dependency context'],
                'messages' => ['What changed for Apollo?', 'Who owns Apollo?', 'Summarize Apollo dependency context.'],
            ],
            'burst' => [
                'name' => 'burst',
                'mode' => 'retrieval',
                'iterations' => 40,
                'concurrency' => 8,
                'queries' => ['who owns Apollo?', 'show Apollo relationship context', 'what changed on Friday around Apollo?'],
                'messages' => ['What changed for Apollo?'],
            ],
            'stress' => [
                'name' => 'stress',
                'mode' => 'mixed',
                'iterations' => 80,
                'concurrency' => 8,
                'queries' => ['who owns Apollo?', 'show Apollo dependency context', 'what changed on Friday around Apollo?', 'who replied in Apollo thread?'],
                'messages' => ['What changed for Apollo?', 'Who owns Apollo and what is it related to?', 'Summarize Apollo activity.'],
            ],
            default => [
                'name' => 'custom',
                'mode' => 'retrieval',
                'iterations' => 20,
                'concurrency' => max(1, (int) config('ai-engine.graph.benchmark.default_load_concurrency', 4)),
                'queries' => ['who owns Apollo?', 'what changed this week around Apollo?'],
                'messages' => ['What changed for Apollo?', 'Who owns Apollo and what is it related to?'],
            ],
        };
    }
}
