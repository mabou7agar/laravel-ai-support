<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AiResource;
use LaravelAIEngine\Services\Agent\Tools\GenericModelLookupTool;
use LaravelAIEngine\Services\Agent\Tools\GenericModelUpsertTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\TestCase;

/**
 * AiResource turns an Eloquent model into agent tools (find_<name> + create_<name>) with
 * no hand-written tool subclasses.
 */
class AiResourceTest extends TestCase
{
    private function userResource(): AiResource
    {
        return AiResource::for(User::class)
            ->name('person')
            ->search(['name', 'email'])
            ->writable(['name', 'email'])
            ->identity(['email'])
            ->required(['name', 'email'])
            ->defaults(['password' => bcrypt('secret')]);
    }

    public function test_registers_find_and_create_tools_typed_from_config(): void
    {
        $registry = new ToolRegistry();
        $this->userResource()->register($registry);

        $this->assertTrue($registry->has('find_person'));
        $this->assertTrue($registry->has('create_person'));
        $this->assertInstanceOf(GenericModelLookupTool::class, $registry->get('find_person'));
        $this->assertInstanceOf(GenericModelUpsertTool::class, $registry->get('create_person'));

        // The create tool exposes the writable fields as parameters, marking required ones.
        $params = $registry->get('create_person')->getParameters();
        $this->assertArrayHasKey('name', $params);
        $this->assertArrayHasKey('email', $params);
        $this->assertTrue($params['email']['required']);
    }

    public function test_create_tool_find_or_creates_and_find_tool_locates_the_record(): void
    {
        $registry = new ToolRegistry();
        $this->userResource()->register($registry);
        $context = new UnifiedActionContext('resource-session');

        // create_person upserts a real row (identity = email).
        $created = $registry->get('create_person')->execute(['name' => 'Ada Lovelace', 'email' => 'ada@example.com'], $context);
        $this->assertTrue($created->success);
        $this->assertTrue((bool) ($created->data['created'] ?? false));
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);

        // Calling create again with the same identity updates, it does not duplicate.
        $again = $registry->get('create_person')->execute(['name' => 'Ada L.', 'email' => 'ada@example.com'], $context);
        $this->assertTrue($again->success);
        $this->assertFalse((bool) ($again->data['created'] ?? true));
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());

        // find_person locates it.
        $found = $registry->get('find_person')->execute(['query' => 'ada@example.com'], $context);
        $this->assertTrue($found->success);
        $this->assertTrue((bool) ($found->data['found'] ?? false));
        $this->assertSame('ada@example.com', $found->data['email'] ?? null);
    }

    public function test_from_config_maps_a_config_array_to_tools(): void
    {
        $tools = AiResource::fromConfig('widget', [
            'model' => User::class,
            'search' => ['name', 'email'],
            'writable' => ['name', 'email'],
            'identity' => ['email'],
            'lookup_only' => false,
        ])->tools();

        $this->assertArrayHasKey('find_widget', $tools);
        $this->assertArrayHasKey('create_widget', $tools);
    }

    public function test_resources_declared_in_config_auto_register_at_boot(): void
    {
        config()->set('ai-agent.resources', [
            'member' => [
                'model' => User::class,
                'search' => ['name', 'email'],
                'writable' => ['name', 'email'],
                'identity' => ['email'],
                'required' => ['name', 'email'],
                'defaults' => ['password' => 'seed'],
            ],
            'reader' => [
                'model' => User::class,
                'search' => ['email'],
                'lookup_only' => true,
            ],
        ]);

        // Re-resolve the registry singleton so the boot-time config loop runs.
        $this->app->forgetInstance(ToolRegistry::class);
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has('find_member'));
        $this->assertTrue($registry->has('create_member'));
        $this->assertTrue($registry->has('find_reader'));
        $this->assertFalse($registry->has('create_reader'), 'lookup_only must not register a create tool.');
    }

    public function test_lookup_only_skips_the_create_tool(): void
    {
        $registry = new ToolRegistry();
        AiResource::for(User::class)->name('reader')->search(['email'])->lookupOnly()->register($registry);

        $this->assertTrue($registry->has('find_reader'));
        $this->assertFalse($registry->has('create_reader'));
    }

    public function test_defaults_closure_receives_context_for_server_set_columns(): void
    {
        $registry = new ToolRegistry();
        AiResource::for(User::class)
            ->name('member')
            ->search(['email'])
            ->writable(['name', 'email'])
            ->identity(['email'])
            ->required(['name', 'email'])
            ->defaults(fn (UnifiedActionContext $ctx): array => ['password' => bcrypt('from-' . $ctx->sessionId)])
            ->register($registry);

        $result = $registry->get('create_member')->execute(['name' => 'Grace', 'email' => 'grace@example.com'], new UnifiedActionContext('s1'));

        $this->assertTrue($result->success);
        $this->assertDatabaseHas('users', ['email' => 'grace@example.com']);
        // The closure-provided password column was applied (not null/empty).
        $this->assertNotEmpty(User::where('email', 'grace@example.com')->value('password'));
    }
}
