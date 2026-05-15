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
        $exitCode = Artisan::call('ai:init');

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryExists(app_path('AI/Configs'));
        $this->assertDirectoryExists(app_path('AI/Filters'));
        $this->assertDirectoryExists(app_path('AI/Skills'));
        $this->assertDirectoryExists(app_path('AI/Tools'));
        $this->assertFileExists($this->manifestPath);

        $manifest = require $this->manifestPath;

        $this->assertArrayHasKey('model_configs', $manifest);
        $this->assertArrayHasKey('tools', $manifest);
        $this->assertArrayHasKey('filters', $manifest);
        $this->assertArrayHasKey('skill_providers', $manifest);
        $this->assertArrayHasKey('skills', $manifest);
    }

    public function test_init_dry_run_does_not_write_files(): void
    {
        $exitCode = Artisan::call('ai:init', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryDoesNotExist(app_path('AI/Configs'));
        $this->assertFileDoesNotExist($this->manifestPath);
    }

    public function test_scaffold_agent_and_filter_registers_manifest_entries(): void
    {
        $agentExitCode = Artisan::call('ai:scaffold', [
            'type' => 'agent',
            'name' => 'Invoice',
            '--model' => 'App\\Models\\Invoice',
            '--description' => 'Invoice agent configuration',
        ]);

        $filterExitCode = Artisan::call('ai:scaffold', [
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

    public function test_make_agent_and_make_tool_aliases_reuse_scaffold_registration(): void
    {
        $agentExitCode = Artisan::call('ai:make-agent', [
            'name' => 'Project',
            '--model' => 'App\\Models\\Project',
        ]);

        $toolExitCode = Artisan::call('ai:make-tool', [
            'name' => 'LookupCustomer',
        ]);

        $this->assertSame(0, $agentExitCode);
        $this->assertSame(0, $toolExitCode);

        $this->assertFileExists(app_path('AI/Configs/ProjectConfig.php'));
        $this->assertFileExists(app_path('AI/Tools/LookupCustomerTool.php'));

        $manifest = require $this->manifestPath;

        $this->assertContains('App\\AI\\Configs\\ProjectConfig', $manifest['model_configs']);
        $this->assertSame('App\\AI\\Tools\\LookupCustomerTool', $manifest['tools']['lookup_customer'] ?? null);

        $toolFile = file_get_contents(app_path('AI/Tools/LookupCustomerTool.php')) ?: '';
        $this->assertStringContainsString('extends SimpleAgentTool', $toolFile);
        $this->assertStringContainsString('protected function handle(array $parameters, UnifiedActionContext $context): array', $toolFile);
    }

    public function test_make_tool_can_generate_model_lookup_and_action_backed_templates(): void
    {
        $lookupExitCode = Artisan::call('ai:make-tool', [
            'name' => 'FindCustomer',
            '--kind' => 'lookup',
            '--model' => 'App\\Models\\Customer',
        ]);

        $actionExitCode = Artisan::call('ai:make-tool', [
            'name' => 'CreateInvoice',
            '--kind' => 'action',
            '--action' => 'invoices.create',
        ]);

        $this->assertSame(0, $lookupExitCode);
        $this->assertSame(0, $actionExitCode);

        $lookupFile = file_get_contents(app_path('AI/Tools/FindCustomerTool.php')) ?: '';
        $this->assertStringContainsString('extends ModelBackedLookupTool', $lookupFile);
        $this->assertStringContainsString('protected string $model = \\App\\Models\\Customer::class;', $lookupFile);
        $this->assertStringContainsString("protected array \$search = ['name', 'email'];", $lookupFile);

        $actionFile = file_get_contents(app_path('AI/Tools/CreateInvoiceTool.php')) ?: '';
        $this->assertStringContainsString('extends ActionBackedTool', $actionFile);
        $this->assertStringContainsString("public string \$actionId = 'invoices.create';", $actionFile);
    }

    public function test_make_skill_generates_class_based_skill(): void
    {
        $exitCode = Artisan::call('ai:make-skill', [
            'name' => 'CreateInvoice',
            '--description' => 'Create invoices through approved tools.',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists(app_path('AI/Skills/CreateInvoiceSkill.php'));

        $manifest = require $this->manifestPath;

        $this->assertSame(
            'App\\AI\\Skills\\CreateInvoiceSkill',
            $manifest['skill_providers']['create_invoice'] ?? null
        );

        $skillFile = file_get_contents(app_path('AI/Skills/CreateInvoiceSkill.php')) ?: '';
        $this->assertStringContainsString('extends AgentSkill', $skillFile);
        $this->assertStringContainsString("public string \$id = 'create_invoice';", $skillFile);
        $this->assertStringContainsString('public function targetJson(): array', $skillFile);
        $this->assertStringNotContainsString('implements AgentSkillProvider', $skillFile);
    }

    public function test_scaffold_skill_registers_manifest_provider(): void
    {
        $exitCode = Artisan::call('ai:scaffold', [
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
        $this->assertStringContainsString('extends AgentSkill', $skillFile);
        $this->assertStringContainsString("public string \$id = 'create_invoice';", $skillFile);
        $this->assertStringContainsString('public bool $enabled = false;', $skillFile);
    }

    public function test_scaffold_runtime_graph_extension_types_register_manifest_entries(): void
    {
        $types = [
            'routing-stage' => ['InvoiceGuard', 'AI/Routing/InvoiceGuardRoutingStage.php', 'routing_stages', 'invoice_guard', 'implements RoutingStageContract'],
            'execution-handler' => ['ApproveInvoice', 'AI/Execution/ApproveInvoiceExecutionHandler.php', 'execution_handlers', 'approve_invoice', 'implements ExecutionHandlerContract'],
            'runtime' => ['DurableGraph', 'AI/Runtimes/DurableGraphRuntime.php', 'runtimes', 'durable_graph', 'implements AgentRuntimeContract'],
            'rag-retriever' => ['HybridKnowledge', 'AI/RAG/HybridKnowledgeRAGRetriever.php', 'rag_retrievers', 'hybrid_knowledge', 'implements RAGRetrieverContract'],
            'policy' => ['FinanceApproval', 'AI/Policies/FinanceApprovalPolicy.php', 'policies', 'finance_approval', 'function canExecute'],
        ];

        foreach ($types as $type => [$name, $path, $section, $key, $expected]) {
            $exitCode = Artisan::call('ai:scaffold', [
                'type' => $type,
                'name' => $name,
            ]);

            $this->assertSame(0, $exitCode, "Scaffold failed for {$type}");
            $this->assertFileExists(app_path($path));
            $this->assertStringContainsString($expected, file_get_contents(app_path($path)) ?: '');

            $manifest = require $this->manifestPath;
            $this->assertArrayHasKey($section, $manifest);
            $this->assertNotNull($manifest[$section][$key] ?? null);
        }
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

        app(\LaravelAIEngine\Services\Actions\ActionRegistry::class)->clearCache();
        app(\LaravelAIEngine\Services\Actions\ActionRegistry::class)->registerBatch((array) config('ai-agent.actions', []));

        $exitCode = Artisan::call('ai:skills:discover', [
            '--write' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $manifest = require $this->manifestPath;

        $this->assertSame('Create Invoice', $manifest['skills']['invoices_create']['name'] ?? null);
        $this->assertSame(['customer_id', 'items'], $manifest['skills']['invoices_create']['required_data'] ?? null);
        $this->assertFalse($manifest['skills']['invoices_create']['enabled'] ?? true);
    }

    public function test_skill_test_command_outputs_match_for_enabled_manifest_skill(): void
    {
        File::ensureDirectoryExists(dirname($this->manifestPath));
        File::put($this->manifestPath, "<?php\n\nreturn " . var_export([
            'skills' => [
                'create_invoice' => [
                    'name' => 'Create Invoice',
                    'description' => 'Create invoices through approved services.',
                    'triggers' => ['create invoice'],
                    'actions' => ['invoices.create'],
                    'enabled' => true,
                ],
            ],
        ], true) . ";\n");
        app(\LaravelAIEngine\Services\Agent\AgentManifestService::class)->refresh();

        $exitCode = Artisan::call('ai:skills:test', [
            'message' => 'create invoice for ACME',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['matched']);
        $this->assertSame('create_invoice', $payload['skill']['id']);
        $this->assertSame('update_action_draft', $payload['plan']['resource_name']);
    }

    public function test_manifest_doctor_reports_missing_skill_references(): void
    {
        File::ensureDirectoryExists(dirname($this->manifestPath));
        File::put($this->manifestPath, "<?php\n\nreturn " . var_export([
            'skills' => [
                'create_invoice' => [
                    'name' => 'Create Invoice',
                    'description' => 'Create invoices through approved services.',
                    'triggers' => ['create invoice'],
                    'actions' => ['missing.action'],
                    'tools' => ['missing_tool'],
                    'enabled' => true,
                ],
            ],
        ], true) . ";\n");
        app(\LaravelAIEngine\Services\Agent\AgentManifestService::class)->refresh();

        $exitCode = Artisan::call('ai:manifest:doctor', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertGreaterThanOrEqual(2, $payload['summary']['errors']);
    }

    public function test_manifest_doctor_outputs_developer_inventory_and_next_commands(): void
    {
        File::ensureDirectoryExists(dirname($this->manifestPath));
        File::put($this->manifestPath, "<?php\n\nreturn " . var_export([
            'skills' => [
                'create_invoice' => [
                    'name' => 'Create Invoice',
                    'description' => 'Create invoices through approved services.',
                    'triggers' => ['create invoice'],
                    'tools' => ['run_skill'],
                    'enabled' => true,
                ],
            ],
        ], true) . ";\n");
        app(\LaravelAIEngine\Services\Agent\AgentManifestService::class)->refresh();

        $exitCode = Artisan::call('ai:manifest:doctor', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertContains('create_invoice', $payload['inventory']['skills']);
        $this->assertContains('run_skill', $payload['inventory']['tools']);
        $this->assertContains('ai:make-tool', $payload['developer_commands']['scaffold']);
        $this->assertContains('ai:make-skill', $payload['developer_commands']['scaffold']);
        $this->assertContains('ai:pricing-simulate', $payload['developer_commands']['pricing']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('AI'));

        parent::tearDown();
    }
}
