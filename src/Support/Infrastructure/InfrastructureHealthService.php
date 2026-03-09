<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InfrastructureHealthService
{
    public function evaluate(): array
    {
        $migration = $this->remoteNodeMigrationGuardStatus();
        $qdrant = $this->qdrantConnectivityStatus();

        $checks = [
            'remote_node_migrations' => $migration,
            'qdrant_connectivity' => $qdrant,
        ];

        $failedRequired = array_filter($checks, static fn (array $check): bool => ($check['required'] ?? false) && !($check['healthy'] ?? false));

        return [
            'status' => $failedRequired === [] ? 'healthy' : 'degraded',
            'ready' => $failedRequired === [],
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function remoteNodeMigrationGuardStatus(): array
    {
        if (!config('ai-engine.infrastructure.remote_node_migration_guard.enabled', true)) {
            return [
                'required' => false,
                'healthy' => true,
                'missing_tables' => [],
                'message' => 'Remote node migration guard is disabled.',
            ];
        }

        $requiredTables = config('ai-engine.infrastructure.remote_node_migration_guard.required_tables', ['ai_conversations', 'ai_messages']);
        if (!is_array($requiredTables)) {
            $requiredTables = ['ai_conversations', 'ai_messages'];
        }

        $missingTables = [];
        foreach ($requiredTables as $table) {
            if (!is_string($table) || trim($table) === '') {
                continue;
            }

            $table = trim($table);

            try {
                if (!Schema::hasTable($table)) {
                    $missingTables[] = $table;
                }
            } catch (\Throwable $e) {
                $missingTables[] = $table;

                Log::channel('ai-engine')->warning('Migration guard table check failed', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $healthy = $missingTables === [];

        return [
            'required' => true,
            'healthy' => $healthy,
            'missing_tables' => $missingTables,
            'message' => $healthy
                ? 'Required remote-node tables are present.'
                : ('Missing required tables: ' . implode(', ', $missingTables)),
        ];
    }

    public function qdrantConnectivityStatus(): array
    {
        $defaultDriver = (string) config('ai-engine.vector.default_driver', 'qdrant');

        if ($defaultDriver !== 'qdrant') {
            return [
                'required' => false,
                'healthy' => true,
                'driver' => $defaultDriver,
                'message' => 'Qdrant self-check skipped: active vector driver is not qdrant.',
            ];
        }

        if (!config('ai-engine.infrastructure.qdrant_self_check.enabled', true)) {
            return [
                'required' => false,
                'healthy' => true,
                'driver' => $defaultDriver,
                'message' => 'Qdrant self-check is disabled.',
            ];
        }

        $host = (string) config('ai-engine.vector.drivers.qdrant.host', 'http://localhost:6333');
        $endpoint = (string) config('ai-engine.infrastructure.qdrant_self_check.endpoint', '/collections');
        $timeout = (float) config('ai-engine.infrastructure.qdrant_self_check.timeout_seconds', 5);
        $apiKey = config('ai-engine.vector.drivers.qdrant.api_key');

        $url = rtrim($host, '/') . '/' . ltrim($endpoint, '/');

        try {
            $request = Http::timeout($timeout)->acceptJson();

            if (is_string($apiKey) && $apiKey !== '') {
                $request = $request->withHeaders(['api-key' => $apiKey]);
            }

            $response = $request->get($url);

            if ($response->successful()) {
                return [
                    'required' => true,
                    'healthy' => true,
                    'driver' => $defaultDriver,
                    'url' => $url,
                    'status_code' => $response->status(),
                    'message' => 'Qdrant connectivity check passed.',
                ];
            }

            return [
                'required' => true,
                'healthy' => false,
                'driver' => $defaultDriver,
                'url' => $url,
                'status_code' => $response->status(),
                'message' => 'Qdrant connectivity check failed with non-success HTTP status.',
            ];
        } catch (\Throwable $e) {
            return [
                'required' => true,
                'healthy' => false,
                'driver' => $defaultDriver,
                'url' => $url,
                'message' => 'Qdrant connectivity check exception: ' . $e->getMessage(),
            ];
        }
    }

    public function chatGuardStatus(): array
    {
        return $this->remoteNodeMigrationGuardStatus();
    }

    public function startupGateMessage(array $report): string
    {
        $checks = $report['checks'] ?? [];
        $fragments = [];

        foreach ($checks as $checkName => $check) {
            if (!is_array($check)) {
                continue;
            }

            $required = (bool) ($check['required'] ?? false);
            $healthy = (bool) ($check['healthy'] ?? false);

            if ($required && !$healthy) {
                $fragments[] = sprintf('%s: %s', $checkName, (string) ($check['message'] ?? 'unhealthy'));
            }
        }

        return $fragments === []
            ? 'Infrastructure checks passed.'
            : implode(' | ', $fragments);
    }
}
