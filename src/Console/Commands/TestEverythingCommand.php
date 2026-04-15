<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Diagnostics\TestEverythingRunner;

class TestEverythingCommand extends Command
{
    protected $signature = 'ai-engine:test-everything
                            {--profile=safe : safe|graph|full|all}
                            {--graph-live : Include package live Neo4j graph checks}
                            {--root-live : Include root-app live graph/chat checks}
                            {--provider-live : Include billed live provider matrix checks}
                            {--root-path= : Root application path for root-app tests}
                            {--phpunit=./vendor/bin/phpunit : PHPUnit binary relative to each stage workdir}
                            {--stop-on-failure : Stop after the first failed stage}
                            {--json : Print a JSON summary}
                            {--neo4j-url=}
                            {--neo4j-database=}
                            {--neo4j-username=}
                            {--neo4j-password=}';

    protected $description = 'Run the package, graph, live-provider, and root-app validation stack from one command';

    public function handle(TestEverythingRunner $runner): int
    {
        $stages = $this->stages();

        if ($stages === []) {
            $this->warn('No stages were selected. Check --profile and path options.');

            return self::INVALID;
        }

        $this->info(sprintf('Running %d validation stage(s)...', count($stages)));

        $results = $runner->runStages($stages, (bool) $this->option('stop-on-failure'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Stage', 'Status', 'Duration (ms)', 'Exit'], array_map(
                fn (array $result): array => [
                    $result['name'],
                    $result['status'],
                    number_format((float) $result['duration_ms'], 2),
                    (string) $result['exit_code'],
                ],
                $results
            ));
        }

        $failures = array_values(array_filter($results, fn (array $result): bool => $result['status'] !== 'passed'));

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->newLine();
                $this->error(sprintf('Failed stage: %s', $failure['name']));
                $this->line($failure['output'] !== '' ? $failure['output'] : '(no output captured)');
            }

            return self::FAILURE;
        }

        $this->info('All selected validation stages passed.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name:string, command:string, workdir:string}>
     */
    protected function stages(): array
    {
        $profile = strtolower(trim((string) $this->option('profile')));
        $includeGraphLive = in_array($profile, ['graph', 'full', 'all'], true) || (bool) $this->option('graph-live');
        $includeRootLive = in_array($profile, ['full', 'all'], true) || (bool) $this->option('root-live');
        $includeProviderLive = $profile === 'all' || (bool) $this->option('provider-live');

        $stages = [
            $this->packageGraphStage(),
            $this->packageChatStage(),
        ];

        if ($includeGraphLive) {
            $stages[] = $this->packageGraphLiveStage();
        }

        if ($rootPath = $this->rootPath()) {
            $stages[] = $this->rootChatMockedStage($rootPath);
        }

        if ($includeProviderLive) {
            $stages[] = $this->packageProviderLiveStage();
        }

        if ($includeRootLive) {
            $rootPath = $this->rootPath();
            if ($rootPath !== null) {
                $stages[] = $this->rootLiveStage($rootPath);
            } else {
                $this->warn('Skipping root-app live stage because no root app path with an artisan file could be resolved.');
            }
        }

        return array_values(array_filter($stages));
    }

    /**
     * @return array{name:string, command:string, workdir:string}
     */
    protected function packageGraphStage(): array
    {
        return [
            'name' => 'package_graph_core',
            'workdir' => $this->packagePath(),
            'command' => $this->buildShellCommand(
                $this->packagePath(),
                [
                    $this->phpunitBinary(),
                    'tests/Unit/Services/Graph',
                    'tests/Unit/Console/Commands/Neo4jGraphCommandsTest.php',
                ]
            ),
        ];
    }

    /**
     * @return array{name:string, command:string, workdir:string}
     */
    protected function packageChatStage(): array
    {
        return [
            'name' => 'package_chat_core',
            'workdir' => $this->packagePath(),
            'command' => $this->buildShellCommand(
                $this->packagePath(),
                [
                    $this->phpunitBinary(),
                    'tests/Unit/Services/Agent',
                    'tests/Unit/Services/RAG/AutonomousRAGDecisionServiceTest.php',
                    'tests/Unit/Http/Controllers/Concerns/ExtractsConversationContextPayloadTest.php',
                ]
            ),
        ];
    }

    /**
     * @return array{name:string, command:string, workdir:string}
     */
    protected function packageGraphLiveStage(): array
    {
        return [
            'name' => 'package_graph_live',
            'workdir' => $this->packagePath(),
            'command' => $this->buildShellCommand(
                $this->packagePath(),
                [
                    $this->phpunitBinary(),
                    'tests/Feature/Acceptance/GraphRAGAcceptanceTest.php',
                    'tests/Feature/Live/Neo4jLiveIntegrationTest.php',
                    'tests/Feature/Live/LiveFeatureMatrixTest.php',
                ],
                array_merge($this->neo4jEnv(), [
                    'AI_ENGINE_RUN_NEO4J_LIVE_TESTS' => 'true',
                    'AI_ENGINE_LIVE_FEATURES' => 'graph_rag',
                ]),
                $this->rootEnvFile()
            ),
        ];
    }

    /**
     * @return array{name:string, command:string, workdir:string}
     */
    protected function packageProviderLiveStage(): array
    {
        return [
            'name' => 'package_provider_live',
            'workdir' => $this->packagePath(),
            'command' => $this->buildShellCommand(
                $this->packagePath(),
                [
                    $this->phpunitBinary(),
                    'tests/Feature/Live/LiveFeatureMatrixTest.php',
                ],
                array_merge($this->neo4jEnv(), [
                    'AI_ENGINE_RUN_LIVE_TESTS' => 'true',
                    'AI_ENGINE_RUN_NEO4J_LIVE_TESTS' => 'true',
                    'AI_ENGINE_LIVE_TEXT_PROVIDER_MATRIX' => getenv('AI_ENGINE_LIVE_TEXT_PROVIDER_MATRIX') ?: 'openai:gpt-4o-mini,openrouter:openai/gpt-4o-mini',
                    'AI_ENGINE_LIVE_AGENT_PROVIDER_MATRIX' => getenv('AI_ENGINE_LIVE_AGENT_PROVIDER_MATRIX') ?: 'openai:gpt-4o-mini,openrouter:openai/gpt-4o-mini',
                    'AI_ENGINE_LIVE_IMAGE_PROVIDER_MATRIX' => getenv('AI_ENGINE_LIVE_IMAGE_PROVIDER_MATRIX') ?: 'openai:dall-e-3,fal_ai:fal-ai/nano-banana-2',
                    'AI_ENGINE_LIVE_VIDEO_PROVIDER_MATRIX' => getenv('AI_ENGINE_LIVE_VIDEO_PROVIDER_MATRIX') ?: 'fal_ai:bytedance/seedance-2.0/text-to-video',
                    'AI_ENGINE_LIVE_TTS_PROVIDER_MATRIX' => getenv('AI_ENGINE_LIVE_TTS_PROVIDER_MATRIX') ?: 'eleven_labs:eleven_multilingual_v2',
                    'AI_ENGINE_LIVE_TRANSCRIBE_PROVIDER_MATRIX' => getenv('AI_ENGINE_LIVE_TRANSCRIBE_PROVIDER_MATRIX') ?: 'openai:whisper-1',
                ]),
                $this->rootEnvFile()
            ),
        ];
    }

    /**
     * @return array{name:string, command:string, workdir:string}
     */
    protected function rootChatMockedStage(string $rootPath): array
    {
        return [
            'name' => 'root_chat_mocked',
            'workdir' => $rootPath,
            'command' => $this->buildShellCommand(
                $rootPath,
                [
                    $this->phpunitBinary(),
                    'tests/Feature/GraphChatRouteTest.php',
                ],
                $this->neo4jEnv(),
                $this->rootEnvFile()
            ),
        ];
    }

    /**
     * @return array{name:string, command:string, workdir:string}
     */
    protected function rootLiveStage(string $rootPath): array
    {
        return [
            'name' => 'root_graph_chat_live',
            'workdir' => $rootPath,
            'command' => $this->buildShellCommand(
                $rootPath,
                [
                    $this->phpunitBinary(),
                    'tests/Feature/RealGraphRAGWorkflowTest.php',
                    'tests/Feature/GraphChatRouteLiveE2ETest.php',
                ],
                array_merge($this->neo4jEnv(), [
                    'AI_ENGINE_RUN_NEO4J_LIVE_TESTS' => 'true',
                    'AI_ENGINE_RUN_LIVE_TESTS' => 'true',
                ]),
                $this->rootEnvFile()
            ),
        ];
    }

    protected function phpunitBinary(): string
    {
        return trim((string) $this->option('phpunit')) ?: './vendor/bin/phpunit';
    }

    protected function packagePath(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function rootPath(): ?string
    {
        $explicit = trim((string) ($this->option('root-path') ?: config('ai-engine.testing.root_app_path', '')));
        if ($explicit !== '') {
            return is_file($explicit.'/artisan') ? $explicit : null;
        }

        $appBasePath = base_path();
        if (is_file($appBasePath.'/artisan')) {
            return $appBasePath;
        }

        $packageParentRoot = dirname($this->packagePath(), 2);
        if (is_file($packageParentRoot.'/artisan')) {
            return $packageParentRoot;
        }

        return null;
    }

    protected function rootEnvFile(): ?string
    {
        $rootPath = $this->rootPath();
        if ($rootPath !== null && is_file($rootPath.'/.env')) {
            return $rootPath.'/.env';
        }

        if (is_file($this->packagePath().'/.env')) {
            return $this->packagePath().'/.env';
        }

        return null;
    }

    /**
     * @param  array<int, string>  $commandParts
     * @param  array<string, string>  $env
     */
    protected function buildShellCommand(string $workdir, array $commandParts, array $env = [], ?string $envFile = null): string
    {
        $exports = '';

        foreach ($env as $key => $value) {
            if ($value === '') {
                continue;
            }

            $exports .= sprintf('export %s=%s; ', $key, escapeshellarg($value));
        }

        $command = implode(' ', array_map('escapeshellarg', $commandParts));
        $prefix = 'set -e; ';

        if ($envFile !== null) {
            $prefix .= sprintf('[ -f %s ] && set -a && source %s >/dev/null 2>&1 && set +a; ', escapeshellarg($envFile), escapeshellarg($envFile));
        }

        return sprintf(
            'bash -lc %s',
            escapeshellarg($prefix.$exports.$command)
        );
    }

    /**
     * @return array<string, string>
     */
    protected function neo4jEnv(): array
    {
        return array_filter([
            'AI_ENGINE_NEO4J_URL' => $this->option('neo4j-url') ?: (string) (config('ai-engine.graph.neo4j.url') ?: getenv('AI_ENGINE_NEO4J_URL') ?: ''),
            'AI_ENGINE_NEO4J_DATABASE' => $this->option('neo4j-database') ?: (string) (config('ai-engine.graph.neo4j.database') ?: getenv('AI_ENGINE_NEO4J_DATABASE') ?: ''),
            'AI_ENGINE_NEO4J_USERNAME' => $this->option('neo4j-username') ?: (string) (config('ai-engine.graph.neo4j.username') ?: getenv('AI_ENGINE_NEO4J_USERNAME') ?: ''),
            'AI_ENGINE_NEO4J_PASSWORD' => $this->option('neo4j-password') ?: (string) (config('ai-engine.graph.neo4j.password') ?: getenv('AI_ENGINE_NEO4J_PASSWORD') ?: ''),
        ], static fn ($value): bool => is_string($value) && trim($value) !== '');
    }
}
