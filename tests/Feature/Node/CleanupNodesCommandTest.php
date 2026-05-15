<?php

namespace LaravelAIEngine\Tests\Feature\Node;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Tests\UnitTestCase;

class CleanupNodesCommandTest extends UnitTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('ai-engine.nodes.enabled', true);
        $app['config']->set('ai-engine.nodes.is_master', true);
        $app['config']->set('ai-engine.nodes.jwt.secret', 'test-jwt-secret');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNodeSchema();
    }

    public function test_cleanup_dry_run_does_not_mutate_nodes(): void
    {
        $stale = $this->createNode([
            'slug' => 'stale-error',
            'status' => 'error',
            'last_ping_at' => now()->subDays(30),
        ]);

        $this->artisan('ai:node-cleanup', [
            '--status' => 'error',
            '--days' => 14,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_nodes', [
            'id' => $stale->id,
            'status' => 'error',
            'deleted_at' => null,
        ]);
    }

    public function test_cleanup_apply_marks_stale_non_master_nodes_inactive(): void
    {
        $staleChild = $this->createNode([
            'slug' => 'stale-child',
            'status' => 'error',
            'type' => 'child',
            'last_ping_at' => now()->subDays(40),
        ]);

        $freshChild = $this->createNode([
            'slug' => 'fresh-child',
            'status' => 'error',
            'type' => 'child',
            'last_ping_at' => now()->subDays(2),
        ]);

        $staleMaster = $this->createNode([
            'slug' => 'stale-master',
            'status' => 'error',
            'type' => 'master',
            'last_ping_at' => now()->subDays(40),
        ]);

        $this->artisan('ai:node-cleanup', [
            '--status' => 'error',
            '--days' => 14,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_nodes', [
            'id' => $staleChild->id,
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'id' => $freshChild->id,
            'status' => 'error',
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'id' => $staleMaster->id,
            'status' => 'error',
        ]);
    }

    public function test_cleanup_apply_delete_soft_deletes_matched_nodes(): void
    {
        $staleInactive = $this->createNode([
            'slug' => 'stale-inactive',
            'status' => 'inactive',
            'last_ping_at' => now()->subDays(30),
        ]);

        $staleError = $this->createNode([
            'slug' => 'stale-error',
            'status' => 'error',
            'last_ping_at' => now()->subDays(30),
        ]);

        $this->artisan('ai:node-cleanup', [
            '--days' => 14,
            '--delete' => true,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSoftDeleted('ai_nodes', ['id' => $staleInactive->id]);
        $this->assertSoftDeleted('ai_nodes', ['id' => $staleError->id]);
    }

    public function test_cleanup_with_zero_days_can_target_recent_nodes(): void
    {
        $recent = $this->createNode([
            'slug' => 'recent-error',
            'status' => 'error',
            'last_ping_at' => null,
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('ai:node-cleanup', [
            '--status' => 'error',
            '--days' => 0,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_nodes', [
            'id' => $recent->id,
            'status' => 'inactive',
        ]);
    }

    protected function createNode(array $overrides = []): AINode
    {
        return AINode::query()->create(array_merge([
            'name' => 'Node ' . uniqid(),
            'slug' => 'node-' . uniqid(),
            'type' => 'child',
            'url' => 'https://node.example.test',
            'status' => 'active',
            'capabilities' => ['search'],
            'weight' => 1,
            'last_ping_at' => now()->subDays(1),
            'updated_at' => now(),
            'created_at' => now(),
        ], $overrides));
    }

    protected function setUpNodeSchema(): void
    {
        Schema::dropIfExists('ai_nodes');

        Schema::create('ai_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('child');
            $table->string('url');
            $table->text('description')->nullable();
            $table->string('api_key')->nullable();
            $table->string('refresh_token')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('domains')->nullable();
            $table->json('data_types')->nullable();
            $table->json('keywords')->nullable();
            $table->json('collections')->nullable();
            $table->json('autonomous_collectors')->nullable();
            $table->json('metadata')->nullable();
            $table->string('version')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_ping_at')->nullable();
            $table->unsignedInteger('ping_failures')->default(0);
            $table->unsignedInteger('avg_response_time')->nullable();
            $table->unsignedInteger('weight')->default(1);
            $table->unsignedInteger('active_connections')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
