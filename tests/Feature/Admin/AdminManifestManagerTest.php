<?php

namespace LaravelAIEngine\Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use LaravelAIEngine\Tests\UnitTestCase;

class AdminManifestManagerTest extends UnitTestCase
{
    protected string $manifestPath;
    protected array $generatedFiles = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('ai-engine.admin_ui.enabled', true);
        $app['config']->set('ai-engine.admin_ui.route_prefix', 'ai-engine/admin');
        $app['config']->set('ai-engine.admin_ui.middleware', ['web']);
        $app['config']->set('ai-engine.admin_ui.access.allowed_ips', ['203.0.113.10']);
        $app['config']->set('ai-engine.admin_ui.access.allow_localhost', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = sys_get_temp_dir() . '/ai-engine-manifest-' . uniqid('', true) . '.php';
        config()->set('ai-agent.manifest.path', $this->manifestPath);

        File::put($this->manifestPath, "<?php\n\nreturn ['model_configs' => [], 'collectors' => [], 'tools' => [], 'filters' => []];\n");
    }

    public function test_manifest_manager_page_is_accessible(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/manifest')
            ->assertOk()
            ->assertSee('Manifest Manager');
    }

    public function test_can_export_manifest_as_json(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/manifest/export')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="agent-manifest.json"')
            ->assertJsonPath('model_configs', []);
    }

    public function test_can_import_manifest_from_json_payload(): void
    {
        $payload = json_encode([
            'model_configs' => ['App\\AI\\Configs\\InvoiceConfig'],
            'collectors' => ['invoice' => 'App\\AI\\Collectors\\InvoiceCollector'],
            'tools' => ['lookup_customer' => 'App\\AI\\Tools\\LookupCustomerTool'],
            'filters' => ['tenant_scope' => 'App\\AI\\Filters\\TenantScopeFilter'],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/import', [
                'payload' => $payload,
            ])
            ->assertRedirect();

        $manifest = require $this->manifestPath;
        $this->assertContains('App\\AI\\Configs\\InvoiceConfig', $manifest['model_configs']);
        $this->assertSame('App\\AI\\Collectors\\InvoiceCollector', $manifest['collectors']['invoice'] ?? null);
        $this->assertSame('App\\AI\\Tools\\LookupCustomerTool', $manifest['tools']['lookup_customer'] ?? null);
        $this->assertSame('App\\AI\\Filters\\TenantScopeFilter', $manifest['filters']['tenant_scope'] ?? null);
    }

    public function test_can_add_and_remove_filter_entry_from_ui(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/entries', [
                'type' => 'filter',
                'key' => 'tenant_scope',
                'class' => 'App\\AI\\Filters\\TenantScopeFilter',
            ])
            ->assertRedirect();

        $manifest = require $this->manifestPath;
        $this->assertSame('App\\AI\\Filters\\TenantScopeFilter', $manifest['filters']['tenant_scope'] ?? null);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/entries/delete', [
                'type' => 'filter',
                'key' => 'tenant_scope',
            ])
            ->assertRedirect();

        $manifest = require $this->manifestPath;
        $this->assertArrayNotHasKey('tenant_scope', $manifest['filters']);
    }

    public function test_can_update_filter_key_and_class_from_ui(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/entries', [
                'type' => 'filter',
                'key' => 'tenant_scope',
                'class' => 'App\\AI\\Filters\\TenantScopeFilter',
            ])
            ->assertRedirect();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/entries/update', [
                'type' => 'filter',
                'old_key' => 'tenant_scope',
                'key' => 'workspace_scope',
                'class' => 'App\\AI\\Filters\\WorkspaceScopeFilter',
            ])
            ->assertRedirect();

        $manifest = require $this->manifestPath;
        $this->assertArrayNotHasKey('tenant_scope', $manifest['filters']);
        $this->assertSame('App\\AI\\Filters\\WorkspaceScopeFilter', $manifest['filters']['workspace_scope'] ?? null);
    }

    public function test_can_add_agent_entry_from_ui(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/entries', [
                'type' => 'agent',
                'class' => 'App\\AI\\Configs\\InvoiceConfig',
            ])
            ->assertRedirect();

        $manifest = require $this->manifestPath;
        $this->assertContains('App\\AI\\Configs\\InvoiceConfig', $manifest['model_configs']);
    }

    public function test_can_update_agent_class_from_ui(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/entries', [
                'type' => 'agent',
                'class' => 'App\\AI\\Configs\\InvoiceConfig',
            ])
            ->assertRedirect();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/entries/update', [
                'type' => 'agent',
                'old_class' => 'App\\AI\\Configs\\InvoiceConfig',
                'class' => 'App\\AI\\Configs\\SalesInvoiceConfig',
            ])
            ->assertRedirect();

        $manifest = require $this->manifestPath;
        $this->assertNotContains('App\\AI\\Configs\\InvoiceConfig', $manifest['model_configs']);
        $this->assertContains('App\\AI\\Configs\\SalesInvoiceConfig', $manifest['model_configs']);
    }

    public function test_can_scaffold_filter_from_ui_and_register_manifest(): void
    {
        $name = 'UiScoped' . substr(md5((string) microtime(true)), 0, 6);
        $generatedPath = app_path('AI/Filters/' . $name . 'Filter.php');
        $this->generatedFiles[] = $generatedPath;

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/manifest/scaffold', [
                'type' => 'filter',
                'name' => $name,
                'description' => 'Scoped filter from admin UI',
            ])
            ->assertRedirect();

        $this->assertFileExists($generatedPath);

        $manifest = require $this->manifestPath;
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);

        $this->assertSame(
            'App\\AI\\Filters\\' . $name . 'Filter',
            $manifest['filters'][$key] ?? null
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            @unlink($this->manifestPath);
        }

        foreach ($this->generatedFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }
}
