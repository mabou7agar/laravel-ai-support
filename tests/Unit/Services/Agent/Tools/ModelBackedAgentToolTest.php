<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\ModelBackedLookupTool;
use LaravelAIEngine\Services\Agent\Tools\ModelBackedUpsertTool;
use LaravelAIEngine\Tests\TestCase;

class ModelBackedAgentToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('collector_tool_customers');
        Schema::create('collector_tool_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_lookup_tool_finds_record_by_configured_columns_and_scope(): void
    {
        CollectorToolCustomer::query()->create([
            'name' => 'Shared Customer',
            'email' => 'wrong@example.test',
            'user_id' => 1,
        ]);
        CollectorToolCustomer::query()->create([
            'name' => 'Shared Customer',
            'email' => 'right@example.test',
            'user_id' => 2,
        ]);

        $result = (new CollectorCustomerLookupTool())->execute(
            ['query' => 'Shared Customer'],
            new UnifiedActionContext('lookup-test', 2)
        );

        $this->assertTrue($result->success);
        $this->assertSame('right@example.test', $result->data['email']);
        $this->assertSame(2, $result->data['user_id']);
    }

    public function test_lookup_tool_returns_missing_fields_for_collection_flows(): void
    {
        $result = (new CollectorCustomerLookupTool())->execute(
            ['query' => 'Missing Customer'],
            new UnifiedActionContext('lookup-missing-test', 2)
        );

        $this->assertFalse($result->success);
        $this->assertFalse($result->data['found']);
        $this->assertSame(['name', 'email'], $result->data['required_fields']);
    }

    public function test_upsert_tool_validates_required_fields_and_uses_defaults(): void
    {
        $tool = new CollectorCustomerUpsertTool();

        $this->assertTrue($tool->requiresConfirmation());
        $this->assertSame(['name', 'email'], array_keys($tool->getParameters()));

        $missing = $tool->execute(['name' => 'Mohamed Hagar'], new UnifiedActionContext('upsert-test', 77));
        $this->assertFalse($missing->success);
        $this->assertSame(['email'], $missing->data['missing_fields']);

        $created = $tool->execute([
            'name' => 'Mohamed Hagar',
            'email' => 'mohamed@example.test',
        ], new UnifiedActionContext('upsert-test', 77));

        $this->assertTrue($created->success);
        $this->assertTrue($created->data['success']);
        $this->assertTrue($created->data['created']);
        $this->assertSame(77, $created->data['user_id']);
        $this->assertDatabaseHas('collector_tool_customers', [
            'name' => 'Mohamed Hagar',
            'email' => 'mohamed@example.test',
            'user_id' => 77,
        ]);
    }

    public function test_autonomous_collector_config_can_execute_agent_tool_classes(): void
    {
        CollectorToolCustomer::query()->create([
            'name' => 'Mohamed Hagar',
            'email' => 'mohamed@example.test',
            'user_id' => 77,
        ]);

        $config = new AutonomousCollectorConfig(
            goal: 'Find customer',
            tools: [
                'find_customer' => CollectorCustomerLookupTool::class,
                'create_customer' => CollectorCustomerUpsertTool::class,
            ]
        );

        $this->assertFalse($config->toolRequiresConfirmation('find_customer'));
        $this->assertTrue($config->toolRequiresConfirmation('create_customer'));
        $this->assertStringContainsString('Requires explicit user confirmation', $config->buildSystemPrompt());

        $result = $config->executeTool(
            'find_customer',
            ['query' => 'Mohamed'],
            new UnifiedActionContext('config-tool-test', 77)
        );

        $this->assertTrue($result['found']);
        $this->assertSame('mohamed@example.test', $result['email']);
    }
}

class CollectorToolCustomer extends Model
{
    protected $table = 'collector_tool_customers';

    protected $guarded = [];
}

class CollectorCustomerLookupTool extends ModelBackedLookupTool
{
    public function getName(): string
    {
        return 'find_customer';
    }

    public function getDescription(): string
    {
        return 'Find customer';
    }

    protected function modelClass(): string
    {
        return CollectorToolCustomer::class;
    }

    protected function searchColumns(): array
    {
        return ['name', 'email'];
    }

    protected function returnColumns(): array
    {
        return ['id', 'name', 'email', 'user_id'];
    }

    protected function missingRequiredFields(): array
    {
        return ['name', 'email'];
    }

    protected function scope(UnifiedActionContext $context, array $parameters): array
    {
        return ['user_id' => $context->userId];
    }
}

class CollectorCustomerUpsertTool extends ModelBackedUpsertTool
{
    public function getName(): string
    {
        return 'create_customer';
    }

    public function getDescription(): string
    {
        return 'Create customer';
    }

    protected function modelClass(): string
    {
        return CollectorToolCustomer::class;
    }

    protected function identityFields(): array
    {
        return ['email'];
    }

    protected function writeFields(): array
    {
        return ['name', 'email'];
    }

    protected function requiredFields(): array
    {
        return ['name', 'email'];
    }

    protected function returnColumns(): array
    {
        return ['id', 'name', 'email', 'user_id'];
    }

    protected function defaults(UnifiedActionContext $context, array $parameters): array
    {
        return ['user_id' => $context->userId];
    }
}
