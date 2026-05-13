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
        $this->assertDirectoryExists(app_path('AI/Skills'));
        $this->assertDirectoryExists(app_path('AI/Tools'));
        $this->assertFileExists($this->manifestPath);

        $manifest = require $this->manifestPath;

        $this->assertArrayHasKey('model_configs', $manifest);
        $this->assertArrayHasKey('collectors', $manifest);
        $this->assertArrayHasKey('tools', $manifest);
        $this->assertArrayHasKey('filters', $manifest);
        $this->assertArrayHasKey('skill_providers', $manifest);
        $this->assertArrayHasKey('skills', $manifest);
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

    public function test_scaffold_skill_registers_manifest_provider(): void
    {
        $exitCode = Artisan::call('ai-engine:scaffold', [
            'type' => 'skill',
            'name' => 'CreateInvoice',
            '--description' => 'Create invoices through approved services.',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists(app_path('AI/Skills/CreateInvoiceSkill.php'));

        $manifest = require $this->manifestPath;

        $this->assertSame(
            'App\\AI\\Skills\\CreateInvoiceSkill',
            $manifest['skill_providers']['create_invoice'] ?? null
        );

        $skillFile = file_get_contents(app_path('AI/Skills/CreateInvoiceSkill.php')) ?: '';
        $this->assertStringContainsString('implements AgentSkillProvider', $skillFile);
        $this->assertStringContainsString("id: 'create_invoice'", $skillFile);
        $this->assertStringContainsString('enabled: false', $skillFile);
    }

    public function test_discover_skills_command_writes_draft_manifest_definitions(): void
    {
        config()->set('ai-agent.actions', [
            'invoices.create' => [
                'id' => 'invoices.create',
                'label' => 'Create Invoice',
                'description' => 'Create invoices through approved services.',
                'operation' => 'create',
                'required' => ['customer_id', 'items'],
                'triggers' => ['create invoice', 'new invoice'],
                'enabled' => true,
            ],
        ]);

        app(\LaravelAIEngine\Services\Actions\ActionRegistry::class)->clear();
        app(\LaravelAIEngine\Services\Actions\ActionRegistry::class)->registerBatch((array) config('ai-agent.actions', []));

        $exitCode = Artisan::call('ai-engine:skills:discover', [
            '--write' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $manifest = require $this->manifestPath;

        $this->assertSame('Create Invoice', $manifest['skills']['invoices_create']['name'] ?? null);
        $this->assertSame(['customer_id', 'items'], $manifest['skills']['invoices_create']['required_data'] ?? null);
        $this->assertFalse($manifest['skills']['invoices_create']['enabled'] ?? true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('AI'));

        parent::tearDown();
    }
}
