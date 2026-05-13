<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\File;
use LaravelAIEngine\Contracts\AgentSkillProvider;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use RuntimeException;

class AgentSkillRegistryTest extends UnitTestCase
{
    protected string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = app_path('AI/agent-manifest.php');
        config()->set('ai-agent.manifest.path', $this->manifestPath);
        File::deleteDirectory(app_path('AI'));
    }

    public function test_it_resolves_configured_and_manifest_skill_providers(): void
    {
        config()->set('ai-agent.skill_providers', [
            'test' => TestSkillProvider::class,
        ]);

        File::ensureDirectoryExists(dirname($this->manifestPath));
        File::put($this->manifestPath, "<?php\n\nreturn " . var_export([
            'skill_providers' => [
                'manifest' => ManifestSkillProvider::class,
            ],
        ], true) . ";\n");

        app(\LaravelAIEngine\Services\Agent\AgentManifestService::class)->refresh();

        $skills = app(AgentSkillRegistry::class)->skills(includeDisabled: true);

        $this->assertCount(2, $skills);
        $this->assertSame('create_invoice', $skills[0]->id);
        $this->assertSame('manifest_skill', $skills[1]->id);
    }

    public function test_it_loads_manifest_skill_definitions_and_ignores_disabled_by_default(): void
    {
        File::ensureDirectoryExists(dirname($this->manifestPath));
        File::put($this->manifestPath, "<?php\n\nreturn " . var_export([
            'skills' => [
                'create_invoice' => [
                    'name' => 'Create Invoice',
                    'description' => 'Create invoices through an approved action.',
                    'triggers' => ['create invoice'],
                    'actions' => ['invoices.create'],
                    'enabled' => true,
                ],
                'draft_quote' => [
                    'name' => 'Draft Quote',
                    'description' => 'Draft quote candidate.',
                    'enabled' => false,
                ],
            ],
        ], true) . ";\n");

        app(\LaravelAIEngine\Services\Agent\AgentManifestService::class)->refresh();

        $enabled = app(AgentSkillRegistry::class)->skills();
        $all = app(AgentSkillRegistry::class)->skills(includeDisabled: true);

        $this->assertCount(1, $enabled);
        $this->assertSame('create_invoice', $enabled[0]->id);
        $this->assertCount(2, $all);
    }

    public function test_it_rejects_invalid_skill_provider_classes(): void
    {
        config()->set('ai-agent.skill_providers', [
            'invalid' => InvalidSkillProvider::class,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(AgentSkillProvider::class);

        app(AgentSkillRegistry::class)->skills();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('AI'));

        parent::tearDown();
    }
}

class TestSkillProvider implements AgentSkillProvider
{
    public function skills(): iterable
    {
        yield new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create an invoice using safe actions.',
            triggers: ['create invoice'],
            actions: ['invoices.create']
        );
    }
}

class ManifestSkillProvider implements AgentSkillProvider
{
    public function skills(): iterable
    {
        yield [
            'id' => 'manifest_skill',
            'name' => 'Manifest Skill',
            'description' => 'Loaded through manifest provider.',
            'enabled' => true,
        ];
    }
}

class InvalidSkillProvider
{
}
