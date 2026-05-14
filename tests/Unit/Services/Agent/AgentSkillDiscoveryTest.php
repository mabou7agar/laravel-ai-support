<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\File;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentSkillDiscoveryTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(app_path('AI'));
        config()->set('ai-agent.manifest.fallback_discovery', true);
        config()->set('ai-agent.manifest.path', app_path('AI/agent-manifest.php'));
    }

    public function test_class_based_skill_is_discovered_and_normalizes_tool_classes(): void
    {
        File::ensureDirectoryExists(app_path('AI/Tools'));
        File::put(app_path('AI/Tools/FindCustomerTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class FindCustomerTool extends AgentTool
{
    public function getName(): string
    {
        return 'find_customer';
    }

    public function getDescription(): string
    {
        return 'Find customers.';
    }

    public function getParameters(): array
    {
        return ['query' => ['type' => 'string', 'required' => true]];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::ensureDirectoryExists(app_path('AI/Skills'));
        File::put(app_path('AI/Skills/CreateInvoiceSkill.php'), <<<'PHP'
<?php

namespace App\AI\Skills;

use App\AI\Tools\FindCustomerTool;
use LaravelAIEngine\Services\Agent\Skills\AgentSkill;

class CreateInvoiceSkill extends AgentSkill
{
    public string $id = 'create_invoice';
    public string $name = 'Create Invoice';
    public string $description = 'Create invoices by resolving customers and items.';
    public array $triggers = ['create invoice'];
    public array $tools = [FindCustomerTool::class];
    public string $finalTool = FindCustomerTool::class;

    public function targetJson(): array
    {
        return ['customer_id' => null, 'items' => []];
    }
}
PHP);

        app(AgentManifestService::class)->refresh();
        $this->app->forgetInstance(ToolRegistry::class);

        $tools = app(ToolRegistry::class);
        $skill = collect(app(AgentSkillRegistry::class)->skills())
            ->firstWhere('id', 'create_invoice');

        $this->assertTrue($tools->has('find_customer'));
        $this->assertNotNull($skill);
        $this->assertSame(['find_customer'], $skill->tools);
        $this->assertSame('find_customer', $skill->metadata['final_tool'] ?? null);
        $this->assertSame(['customer_id' => null, 'items' => []], $skill->metadata['target_json'] ?? null);
        $this->assertSame('skill_tool_auto', $skill->metadata['planner'] ?? null);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('AI'));

        parent::tearDown();
    }
}
