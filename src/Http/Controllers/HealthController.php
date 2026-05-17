<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class HealthController extends Controller
{
    /**
     * Return a JSON health-check payload suitable for load-balancer and
     * Kubernetes readiness / liveness probes.
     *
     * HTTP 200  – overall status is "healthy" or "degraded"
     * HTTP 503  – overall status is "unhealthy"
     */
    public function index(): JsonResponse
    {
        $services = [];
        $overallStatus = 'healthy';

        // --- AI providers --------------------------------------------------
        foreach ($this->configuredProviders() as $provider => $apiKey) {
            $result = $this->checkProvider($provider, $apiKey);
            $services[$provider] = $result;

            if ($result['status'] !== 'healthy' && $overallStatus === 'healthy') {
                $overallStatus = 'degraded';
            }
        }

        // --- Vector store (Qdrant) ------------------------------------------
        $services['qdrant'] = $this->checkQdrant();
        if ($services['qdrant']['status'] === 'unhealthy' && $overallStatus !== 'unhealthy') {
            $overallStatus = 'degraded';
        }

        // --- Graph store (Neo4j) -------------------------------------------
        $services['neo4j'] = $this->checkNeo4j();
        if ($services['neo4j']['status'] === 'unhealthy' && $overallStatus !== 'unhealthy') {
            $overallStatus = 'degraded';
        }

        // --- Memory driver -------------------------------------------------
        $services['memory'] = $this->checkMemory();
        if ($services['memory']['status'] === 'unhealthy') {
            $overallStatus = 'unhealthy';
        }

        $httpStatus = $overallStatus === 'unhealthy' ? 503 : 200;

        return response()->json([
            'status'    => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'version'   => $this->packageVersion(),
            'services'  => $services,
        ], $httpStatus);
    }

    // -----------------------------------------------------------------------
    // Provider checks
    // -----------------------------------------------------------------------

    /**
     * Returns a map of [ provider_slug => api_key ] for every engine that has
     * a non-empty API key in the current configuration.
     *
     * @return array<string, string>
     */
    protected function configuredProviders(): array
    {
        $candidates = [
            'openai'    => (string) config('ai-engine.engines.openai.api_key', ''),
            'anthropic' => (string) config('ai-engine.engines.anthropic.api_key', ''),
            'gemini'    => (string) config('ai-engine.engines.gemini.api_key', ''),
        ];

        return array_filter($candidates, static fn (string $key): bool => $key !== '');
    }

    /**
     * Validate a provider by confirming the API key is present (a lightweight
     * config-presence check rather than a live network call so the endpoint
     * stays fast and free of rate-limit side-effects).
     *
     * @return array{status: string, message: string}
     */
    protected function checkProvider(string $provider, string $apiKey): array
    {
        if ($apiKey === '') {
            return [
                'status'  => 'unconfigured',
                'message' => "No API key found for {$provider}.",
            ];
        }

        return [
            'status'  => 'healthy',
            'message' => "API key present for {$provider}.",
        ];
    }

    // -----------------------------------------------------------------------
    // Qdrant
    // -----------------------------------------------------------------------

    /**
     * @return array{status: string, message: string, latency_ms?: int}
     */
    protected function checkQdrant(): array
    {
        $defaultDriver = (string) config('ai-engine.vector.default_driver', config('ai-engine.vector.driver', 'qdrant'));

        if ($defaultDriver !== 'qdrant') {
            return [
                'status'  => 'skipped',
                'message' => "Active vector driver is '{$defaultDriver}', not qdrant.",
            ];
        }

        $host    = rtrim((string) config('ai-engine.vector.drivers.qdrant.host', 'http://localhost:6333'), '/');
        $apiKey  = config('ai-engine.vector.drivers.qdrant.api_key');
        $timeout = (float) config('ai-engine.infrastructure.qdrant_self_check.timeout_seconds', 5);
        $url     = $host . '/collections';

        $start = microtime(true);

        try {
            $request = Http::timeout($timeout)->acceptJson();

            if (is_string($apiKey) && $apiKey !== '') {
                $request = $request->withHeaders(['api-key' => $apiKey]);
            }

            $response = $request->get($url);
            $latency  = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return [
                    'status'     => 'healthy',
                    'message'    => 'Qdrant responded successfully.',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status'      => 'unhealthy',
                'message'     => "Qdrant returned HTTP {$response->status()}.",
                'latency_ms'  => $latency,
            ];
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'status'     => 'unhealthy',
                'message'    => 'Qdrant connectivity error: ' . $e->getMessage(),
                'latency_ms' => $latency,
            ];
        }
    }

    // -----------------------------------------------------------------------
    // Neo4j
    // -----------------------------------------------------------------------

    /**
     * @return array{status: string, message: string, latency_ms?: int}
     */
    protected function checkNeo4j(): array
    {
        if (!(bool) config('ai-engine.graph.enabled', false)) {
            return [
                'status'  => 'skipped',
                'message' => 'Graph store is disabled (ai-engine.graph.enabled is false).',
            ];
        }

        $backend = (string) config('ai-engine.graph.backend', 'neo4j');

        if ($backend !== 'neo4j') {
            return [
                'status'  => 'skipped',
                'message' => "Graph backend is '{$backend}', not neo4j.",
            ];
        }

        $baseUrl  = rtrim((string) config('ai-engine.graph.neo4j.url', 'http://localhost:7474'), '/');
        $database = (string) config('ai-engine.graph.neo4j.database', 'neo4j');
        $username = (string) config('ai-engine.graph.neo4j.username', 'neo4j');
        $password = (string) config('ai-engine.graph.neo4j.password', '');
        $useQueryApi = (bool) config('ai-engine.graph.neo4j.use_query_api', true);

        // Use the Neo4j HTTP discovery endpoint as a lightweight ping.
        $url = $useQueryApi
            ? $baseUrl . '/db/' . $database . '/query/v2'
            : $baseUrl . '/db/data/';

        $start = microtime(true);

        try {
            $request = Http::timeout(5)->acceptJson();

            if ($username !== '' && $password !== '') {
                $request = $request->withBasicAuth($username, $password);
            }

            // For the Query API, send a trivial RETURN 1 as our ping.
            $response = $useQueryApi
                ? $request->withBody(json_encode(['statement' => 'RETURN 1 AS ping', 'parameters' => []]) ?: '', 'application/json')->post($url)
                : $request->get($url);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return [
                    'status'     => 'healthy',
                    'message'    => 'Neo4j responded successfully.',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status'     => 'unhealthy',
                'message'    => "Neo4j returned HTTP {$response->status()}.",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'status'     => 'unhealthy',
                'message'    => 'Neo4j connectivity error: ' . $e->getMessage(),
                'latency_ms' => $latency,
            ];
        }
    }

    // -----------------------------------------------------------------------
    // Memory driver
    // -----------------------------------------------------------------------

    /**
     * @return array{status: string, driver: string, message: string}
     */
    protected function checkMemory(): array
    {
        if (!(bool) config('ai-engine.memory.enabled', true)) {
            return [
                'status'  => 'skipped',
                'driver'  => 'none',
                'message' => 'Memory driver is disabled (ai-engine.memory.enabled is false).',
            ];
        }

        $driver = (string) config('ai-engine.memory.default_driver', 'database');

        try {
            $status = match ($driver) {
                'database' => $this->checkDatabaseMemory(),
                'redis'    => $this->checkRedisMemory(),
                'file'     => $this->checkFileMemory(),
                default    => ['status' => 'healthy', 'message' => "Driver '{$driver}' in use (no connectivity check available)."],
            };
        } catch (\Throwable $e) {
            $status = [
                'status'  => 'unhealthy',
                'message' => 'Memory driver check threw an exception: ' . $e->getMessage(),
            ];
        }

        return array_merge(['driver' => $driver], $status);
    }

    /** @return array{status: string, message: string} */
    protected function checkDatabaseMemory(): array
    {
        \Illuminate\Support\Facades\DB::select('SELECT 1');

        return ['status' => 'healthy', 'message' => 'Database memory driver is reachable.'];
    }

    /** @return array{status: string, message: string} */
    protected function checkRedisMemory(): array
    {
        $connection = (string) config('ai-engine.memory.redis.connection', 'default');
        \Illuminate\Support\Facades\Redis::connection($connection)->ping();

        return ['status' => 'healthy', 'message' => "Redis memory driver (connection: '{$connection}') is reachable."];
    }

    /** @return array{status: string, message: string} */
    protected function checkFileMemory(): array
    {
        $path = (string) config('ai-engine.memory.file.path', storage_path('ai-engine/conversations'));

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            return [
                'status'  => 'unhealthy',
                'message' => "File memory path '{$path}' does not exist and could not be created.",
            ];
        }

        if (!is_writable($path)) {
            return [
                'status'  => 'unhealthy',
                'message' => "File memory path '{$path}' is not writable.",
            ];
        }

        return ['status' => 'healthy', 'message' => "File memory path '{$path}' is writable."];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function packageVersion(): string
    {
        return '1.0.0';
    }
}
