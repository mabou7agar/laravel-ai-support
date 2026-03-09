<?php

namespace LaravelAIEngine\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentScaffoldCommandsTest extends UnitTestCase
{
    protected string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = app_path('AI/agent-manifest.php');
        config()->set('ai-agent.manifest.path', $this->manifestPath);

        File::deleteDirectory(app_path('AI'));
    }

    public function test_init_command_creates_workspace_and_manifest(): void
    {
        $exitCode = Artisan::call('ai-engine:init');

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryExists(app_path('AI/Configs'));
        $this->assertDirectoryExists(app_path('AI/Collectors'));
        $this->assertDirectoryExists(app_path('AI/Filters'));
        $this->assertDirectoryExists(app_path('AI/Tools'));
        $this->assertFileExists($this->manifestPath);

        $manifest = require $this->manifestPath;

        $this->assertArrayHasKey('model_configs', $manifest);
        $this->assertArrayHasKey('collectors', $manifest);
        $this->assertArrayHasKey('tools', $manifest);
        $this->assertArrayHasKey('filters', $manifest);
    }

    public function test_init_dry_run_does_not_write_files(): void
    {
        $exitCode = Artisan::call('ai-engine:init', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryDoesNotExist(app_path('AI/Configs'));
        $this->assertFileDoesNotExist($this->manifestPath);
    }

    public function test_scaffold_agent_and_filter_registers_manifest_entries(): void
    {
        $agentExitCode = Artisan::call('ai-engine:scaffold', [
            'type' => 'agent',
            'name' => 'Invoice',
            '--model' => 'App\\Models\\Invoice',
            '--description' => 'Invoice agent configuration',
        ]);

        $filterExitCode = Artisan::call('ai-engine:scaffold', [
            'type' => 'filter',
            'name' => 'TenantScope',
        ]);

        $this->assertSame(0, $agentExitCode);
        $this->assertSame(0, $filterExitCode);

        $this->assertFileExists(app_path('AI/Configs/InvoiceConfig.php'));
        $this->assertFileExists(app_path('AI/Filters/TenantScopeFilter.php'));

        $manifest = require $this->manifestPath;

        $this->assertContains('App\\AI\\Configs\\InvoiceConfig', $manifest['model_configs']);
        $this->assertSame('App\\AI\\Filters\\TenantScopeFilter', $manifest['filters']['tenant_scope'] ?? null);

        $agentFile = file_get_contents(app_path('AI/Configs/InvoiceConfig.php')) ?: '';
        $this->assertStringContainsString('class InvoiceConfig extends AutonomousModelConfig', $agentFile);
        $this->assertStringContainsString('return \\App\\Models\\Invoice::class;', $agentFile);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('AI'));

        parent::tearDown();
    }
}
