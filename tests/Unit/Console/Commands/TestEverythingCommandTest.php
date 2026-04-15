<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use LaravelAIEngine\Services\Diagnostics\TestEverythingRunner;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class TestEverythingCommandTest extends UnitTestCase
{
    protected array $tempPaths = [];

    public function test_safe_profile_runs_package_graph_stage_only(): void
    {
        $runner = Mockery::mock(TestEverythingRunner::class);
        $runner->shouldReceive('runStages')
            ->once()
            ->withArgs(function (array $stages, bool $stopOnFailure): bool {
                $this->assertFalse($stopOnFailure);
                $this->assertCount(3, $stages);
                $this->assertSame('package_graph_core', $stages[0]['name']);
                $this->assertSame('package_chat_core', $stages[1]['name']);
                $this->assertSame('root_chat_mocked', $stages[2]['name']);
                $this->assertSame(dirname(__DIR__, 4), $stages[0]['workdir']);
                $this->assertStringContainsString('tests/Unit/Services/Graph', $stages[0]['command']);
                $this->assertStringContainsString('tests/Unit/Services/Agent', $stages[1]['command']);
                $this->assertNotSame('', $stages[2]['workdir']);
                $this->assertStringContainsString('tests/Feature/GraphChatRouteTest.php', $stages[2]['command']);

                return true;
            })
            ->andReturn([
                [
                    'name' => 'package_graph_core',
                    'command' => 'phpunit',
                    'workdir' => dirname(__DIR__, 4),
                    'status' => 'passed',
                    'exit_code' => 0,
                    'duration_ms' => 12.5,
                    'output' => '',
                ],
                [
                    'name' => 'package_chat_core',
                    'command' => 'phpunit',
                    'workdir' => dirname(__DIR__, 4),
                    'status' => 'passed',
                    'exit_code' => 0,
                    'duration_ms' => 11.0,
                    'output' => '',
                ],
                [
                    'name' => 'root_chat_mocked',
                    'command' => 'phpunit',
                    'workdir' => '/tmp/test-root',
                    'status' => 'passed',
                    'exit_code' => 0,
                    'duration_ms' => 9.5,
                    'output' => '',
                ],
            ]);

        $this->app->instance(TestEverythingRunner::class, $runner);

        $this->artisan('ai-engine:test-everything')
            ->expectsOutput('Running 3 validation stage(s)...')
            ->expectsOutput('All selected validation stages passed.')
            ->assertSuccessful();
    }

    public function test_all_profile_includes_live_package_and_root_stages(): void
    {
        $rootPath = $this->makeFakeRootApp();

        $runner = Mockery::mock(TestEverythingRunner::class);
        $runner->shouldReceive('runStages')
            ->once()
            ->withArgs(function (array $stages, bool $stopOnFailure) use ($rootPath): bool {
                $this->assertFalse($stopOnFailure);
                $this->assertSame([
                    'package_graph_core',
                    'package_chat_core',
                    'package_graph_live',
                    'root_chat_mocked',
                    'package_provider_live',
                    'root_graph_chat_live',
                ], array_column($stages, 'name'));
                $this->assertSame($rootPath, $stages[3]['workdir']);
                $this->assertSame($rootPath, $stages[5]['workdir']);
                $this->assertStringContainsString('tests/Unit/Services/Agent', $stages[1]['command']);
                $this->assertStringContainsString('AI_ENGINE_RUN_NEO4J_LIVE_TESTS', $stages[2]['command']);
                $this->assertStringContainsString('tests/Feature/GraphChatRouteTest.php', $stages[3]['command']);
                $this->assertStringContainsString('AI_ENGINE_RUN_LIVE_TESTS', $stages[4]['command']);
                $this->assertStringContainsString('tests/Feature/RealGraphRAGWorkflowTest.php', $stages[5]['command']);

                return true;
            })
            ->andReturn(array_map(
                fn (string $name): array => [
                    'name' => $name,
                    'command' => 'phpunit',
                    'workdir' => in_array($name, ['root_chat_mocked', 'root_graph_chat_live'], true) ? $rootPath : dirname(__DIR__, 4),
                    'status' => 'passed',
                    'exit_code' => 0,
                    'duration_ms' => 10.0,
                    'output' => '',
                ],
                ['package_graph_core', 'package_chat_core', 'package_graph_live', 'root_chat_mocked', 'package_provider_live', 'root_graph_chat_live']
            ));

        $this->app->instance(TestEverythingRunner::class, $runner);

        $this->artisan('ai-engine:test-everything', [
            '--profile' => 'all',
            '--root-path' => $rootPath,
        ])->assertSuccessful();
    }

    public function test_command_returns_failure_when_a_stage_fails(): void
    {
        $runner = Mockery::mock(TestEverythingRunner::class);
        $runner->shouldReceive('runStages')
            ->once()
            ->andReturn([
                [
                    'name' => 'package_graph_core',
                    'command' => 'phpunit',
                    'workdir' => dirname(__DIR__, 4),
                    'status' => 'failed',
                    'exit_code' => 1,
                    'duration_ms' => 15.0,
                    'output' => 'failing stage output',
                ],
            ]);

        $this->app->instance(TestEverythingRunner::class, $runner);

        $this->artisan('ai-engine:test-everything', ['--stop-on-failure' => true])
            ->expectsOutput('Running 3 validation stage(s)...')
            ->expectsOutput('Failed stage: package_graph_core')
            ->expectsOutput('failing stage output')
            ->assertFailed();
    }

    protected function makeFakeRootApp(): string
    {
        $path = sys_get_temp_dir().'/ai-engine-root-'.bin2hex(random_bytes(5));
        mkdir($path, 0777, true);
        file_put_contents($path.'/artisan', "#!/usr/bin/env php\n<?php\n");
        $this->tempPaths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path.'/artisan')) {
                unlink($path.'/artisan');
            }

            if (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }
}
