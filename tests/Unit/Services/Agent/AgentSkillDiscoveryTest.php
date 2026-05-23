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
        File::put(app_path('AI/Skills/DiscoverInvoiceSkill.php'), <<<'PHP'
<?php

namespace App\AI\Skills;

use App\AI\Tools\FindCustomerTool;
use LaravelAIEngine\Services\Agent\Skills\AgentSkill;

class DiscoverInvoiceSkill extends AgentSkill
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
        $this->assertSame('ai_native', $skill->metadata['planner'] ?? null);
    }

    public function test_class_based_skill_builder_infers_result_payload_mappings_from_tool_schemas(): void
    {
        File::ensureDirectoryExists(app_path('AI/Tools'));
        File::put(app_path('AI/Tools/FindBillingCustomerTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class FindBillingCustomerTool extends AgentTool
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

    public function getResultSchema(): array
    {
        return ['id' => 'integer', 'name' => 'string', 'email' => 'string'];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::ensureDirectoryExists(app_path('AI/Skills'));
        File::put(app_path('AI/Skills/MapInvoiceCustomerSkill.php'), <<<'PHP'
<?php

namespace App\AI\Skills;

use App\AI\Tools\FindBillingCustomerTool;
use LaravelAIEngine\Services\Agent\Skills\AgentSkill;
use LaravelAIEngine\Services\Agent\Skills\SkillBuilder;

class MapInvoiceCustomerSkill extends AgentSkill
{
    public string $id = 'create_invoice';
    public array $triggers = ['create invoice'];

    public function configure(SkillBuilder $skill): void
    {
        $skill->target()
            ->id('customer_id')
            ->text('customer_name')
            ->email('customer_email');

        $skill->use(FindBillingCustomerTool::class);
        $skill->final(FindBillingCustomerTool::class)->confirmTerms(['invoice']);
    }
}
PHP);

        app(AgentManifestService::class)->refresh();
        $this->app->forgetInstance(ToolRegistry::class);

        $skill = collect(app(AgentSkillRegistry::class)->skills())
            ->firstWhere('id', 'create_invoice');

        $this->assertNotNull($skill);
        $this->assertSame(['find_customer'], $skill->tools);
        $this->assertSame([
            'customer_id' => null,
            'customer_name' => null,
            'customer_email' => null,
        ], $skill->metadata['target_json'] ?? null);
        $this->assertSame(['invoice'], $skill->metadata['final_confirmation_terms'] ?? null);
        $this->assertSame([
            'customer_id' => 'id',
            'customer_name' => 'name',
            'customer_email' => 'email',
        ], $skill->metadata['result_payload_mappings']['find_customer']['fields'] ?? null);
    }

    public function test_class_based_skill_builder_infers_list_result_payload_mappings(): void
    {
        File::ensureDirectoryExists(app_path('AI/Tools'));
        File::put(app_path('AI/Tools/FindInventoryProductTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class FindInventoryProductTool extends AgentTool
{
    public function getName(): string
    {
        return 'find_product';
    }

    public function getDescription(): string
    {
        return 'Find products.';
    }

    public function getParameters(): array
    {
        return ['query' => ['type' => 'string', 'required' => true]];
    }

    public function getResultSchema(): array
    {
        return ['id' => 'integer', 'name' => 'string', 'sale_price' => 'number', 'price' => 'number'];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::ensureDirectoryExists(app_path('AI/Skills'));
        File::put(app_path('AI/Skills/BuildInvoiceSkill.php'), <<<'PHP'
<?php

namespace App\AI\Skills;

use App\AI\Tools\FindInventoryProductTool;
use LaravelAIEngine\Services\Agent\Skills\AgentSkill;
use LaravelAIEngine\Services\Agent\Skills\SkillBuilder;

class BuildInvoiceSkill extends AgentSkill
{
    public string $id = 'create_invoice';

    public function configure(SkillBuilder $skill): void
    {
        $skill->target()->list('items', fn ($item) => $item
            ->id('product_id')
            ->text('product_name')
            ->number('quantity')
            ->money('unit_price'));

        $skill->use(FindInventoryProductTool::class)->forList('items')->matchBy('product_name');
    }
}
PHP);

        app(AgentManifestService::class)->refresh();
        $this->app->forgetInstance(ToolRegistry::class);

        $skill = collect(app(AgentSkillRegistry::class)->skills())
            ->firstWhere('id', 'create_invoice');

        $this->assertNotNull($skill);
        $this->assertSame([
            'path' => 'items',
            'match' => [
                'payload' => 'product_name',
                'result' => 'name',
                'params' => 'query',
            ],
            'fields' => [
                'product_id' => 'id',
                'product_name' => 'name',
                'unit_price' => ['sale_price', 'price'],
            ],
        ], $skill->metadata['result_payload_mappings']['find_product']['lists'][0] ?? null);
    }

    public function test_class_based_skill_builder_publishes_prompt_and_relation_metadata(): void
    {
        File::ensureDirectoryExists(app_path('AI/Tools'));
        File::put(app_path('AI/Tools/FindBuyerTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class FindBuyerTool extends AgentTool
{
    public function getName(): string
    {
        return 'find_buyer';
    }

    public function getDescription(): string
    {
        return 'Find buyers.';
    }

    public function getParameters(): array
    {
        return ['query' => ['type' => 'string', 'required' => true]];
    }

    public function getResultSchema(): array
    {
        return ['id' => 'integer', 'name' => 'string', 'email' => 'string'];
    }

    public function getRelations(): array
    {
        return [
            ['name' => 'buyer', 'field' => 'buyer_id', 'role' => 'lookup'],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::put(app_path('AI/Tools/CreateBuyerTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class CreateBuyerTool extends AgentTool
{
    public function getName(): string
    {
        return 'create_buyer';
    }

    public function getDescription(): string
    {
        return 'Create buyers.';
    }

    public function getParameters(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true],
            'email' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::ensureDirectoryExists(app_path('AI/Skills'));
        File::put(app_path('AI/Skills/CreateOrderSkill.php'), <<<'PHP'
<?php

namespace App\AI\Skills;

use App\AI\Tools\CreateBuyerTool;
use App\AI\Tools\FindBuyerTool;
use LaravelAIEngine\Services\Agent\Skills\AgentSkill;
use LaravelAIEngine\Services\Agent\Skills\SkillBuilder;

class CreateOrderSkill extends AgentSkill
{
    public string $id = 'create_order';
    public string $name = 'Create Order';

    public function configure(SkillBuilder $skill): void
    {
        $skill->prompt('Resolve the buyer first, create it only after confirmation, then summarize the order before the final write.');

        $skill->target()
            ->id('buyer_id')
            ->text('buyer_name')
            ->email('buyer_email');

        $skill->relation('buyer')
            ->field('buyer_id')
            ->lookup(FindBuyerTool::class)
            ->create(CreateBuyerTool::class)
            ->lookupFields(['buyer_name', 'buyer_email'])
            ->createRequired(['name', 'email'])
            ->safeCreate();

        $skill->use(FindBuyerTool::class);
        $skill->use(CreateBuyerTool::class);
    }
}
PHP);

        app(AgentManifestService::class)->refresh();
        $this->app->forgetInstance(ToolRegistry::class);

        $skill = collect(app(AgentSkillRegistry::class)->skills())
            ->firstWhere('id', 'create_order');
        $tool = app(ToolRegistry::class)->get('find_buyer');

        $this->assertNotNull($skill);
        $this->assertSame(
            'Resolve the buyer first, create it only after confirmation, then summarize the order before the final write.',
            $skill->prompt
        );
        $this->assertSame($skill->prompt, $skill->metadata['prompt'] ?? null);
        $this->assertSame([
            [
                'name' => 'buyer',
                'field' => 'buyer_id',
                'lookup_tool' => 'find_buyer',
                'create_tool' => 'create_buyer',
                'lookup_fields' => ['buyer_name', 'buyer_email'],
                'create_required_fields' => ['name', 'email'],
                'safe_create' => true,
            ],
        ], $skill->metadata['relations'] ?? null);
        $this->assertSame([
            ['name' => 'buyer', 'field' => 'buyer_id', 'role' => 'lookup'],
        ], $tool?->toArray()['relations'] ?? null);
    }

    public function test_class_based_skill_builder_infers_relation_metadata_when_not_declared(): void
    {
        File::ensureDirectoryExists(app_path('AI/Tools'));
        File::put(app_path('AI/Tools/FindOrderBuyerTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class FindOrderBuyerTool extends AgentTool
{
    public function getName(): string
    {
        return 'find_buyer';
    }

    public function getDescription(): string
    {
        return 'Find buyers.';
    }

    public function getParameters(): array
    {
        return ['query' => ['type' => 'string', 'required' => true]];
    }

    public function getEntityType(): ?string
    {
        return 'buyer';
    }

    public function getToolKind(): ?string
    {
        return 'lookup';
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::put(app_path('AI/Tools/CreateOrderBuyerTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class CreateOrderBuyerTool extends AgentTool
{
    public function getName(): string
    {
        return 'create_buyer';
    }

    public function getDescription(): string
    {
        return 'Create buyers.';
    }

    public function getParameters(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true],
            'email' => ['type' => 'string', 'required' => true],
        ];
    }

    public function getEntityType(): ?string
    {
        return 'buyer';
    }

    public function getToolKind(): ?string
    {
        return 'create';
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::put(app_path('AI/Tools/FindOrderItemTool.php'), <<<'PHP'
<?php

namespace App\AI\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class FindOrderItemTool extends AgentTool
{
    public function getName(): string
    {
        return 'find_item';
    }

    public function getDescription(): string
    {
        return 'Find items.';
    }

    public function getParameters(): array
    {
        return ['query' => ['type' => 'string', 'required' => true]];
    }

    public function getEntityType(): ?string
    {
        return 'item';
    }

    public function getToolKind(): ?string
    {
        return 'lookup';
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
PHP);

        File::ensureDirectoryExists(app_path('AI/Skills'));
        File::put(app_path('AI/Skills/CreatePurchaseOrderSkill.php'), <<<'PHP'
<?php

namespace App\AI\Skills;

use App\AI\Tools\CreateOrderBuyerTool;
use App\AI\Tools\FindOrderBuyerTool;
use App\AI\Tools\FindOrderItemTool;
use LaravelAIEngine\Services\Agent\Skills\AgentSkill;
use LaravelAIEngine\Services\Agent\Skills\SkillBuilder;

class CreatePurchaseOrderSkill extends AgentSkill
{
    public string $id = 'create_purchase_order';

    public function configure(SkillBuilder $skill): void
    {
        $skill->target()
            ->id('buyer_id')
            ->text('buyer_name')
            ->email('buyer_email')
            ->list('lines', fn ($line) => $line
                ->id('item_id')
                ->text('item_name')
                ->number('quantity'));

        $skill->use(FindOrderBuyerTool::class);
        $skill->use(CreateOrderBuyerTool::class);
        $skill->use(FindOrderItemTool::class);
    }
}
PHP);

        app(AgentManifestService::class)->refresh();

        $skill = collect(app(AgentSkillRegistry::class)->skills())
            ->firstWhere('id', 'create_purchase_order');

        $this->assertNotNull($skill);
        $this->assertSame([
            [
                'name' => 'buyer',
                'field' => 'buyer_id',
                'lookup_tool' => 'find_buyer',
                'create_tool' => 'create_buyer',
                'lookup_fields' => ['buyer_name', 'buyer_email'],
                'create_required_fields' => ['name', 'email'],
                'safe_create' => true,
                'source' => 'inferred',
            ],
            [
                'name' => 'item',
                'field' => 'lines.*.item_id',
                'lookup_tool' => 'find_item',
                'lookup_fields' => ['lines.*.item_name'],
                'safe_create' => false,
                'source' => 'inferred',
            ],
        ], $skill->metadata['relations'] ?? null);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('AI'));

        parent::tearDown();
    }
}
