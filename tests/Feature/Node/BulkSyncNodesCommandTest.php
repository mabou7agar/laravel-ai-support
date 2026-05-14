<?php

namespace LaravelAIEngine\Tests\Feature\Node;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Tests\UnitTestCase;

class BulkSyncNodesCommandTest extends UnitTestCase
{
    protected array $tempFiles = [];

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

    public function test_dry_run_does_not_persist_changes(): void
    {
        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'url' => 'https://billing.example.test',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', ['--file' => $file])
            ->assertExitCode(0);

        $this->assertDatabaseCount('ai_nodes', 0);
    }

    public function test_apply_creates_and_updates_nodes(): void
    {
        AINode::query()->create([
            'name' => 'Billing Node',
            'slug' => 'billing-node',
            'type' => 'child',
            'url' => 'https://old-billing.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
        ]);

        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                    'capabilities' => ['search', 'rag'],
                    'weight' => 3,
                ],
                [
                    'name' => 'CRM Node',
                    'url' => 'https://crm.example.test',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', [
            '--file' => $file,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'billing-node',
            'url' => 'https://billing.example.test',
            'weight' => 3,
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'crm-node',
            'url' => 'https://crm.example.test',
            'status' => 'active',
        ]);
    }

    public function test_apply_with_prune_marks_missing_child_nodes_inactive(): void
    {
        AINode::query()->create([
            'name' => 'Keep Node',
            'slug' => 'keep-node',
            'type' => 'child',
            'url' => 'https://keep.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
        ]);

        AINode::query()->create([
            'name' => 'Stale Node',
            'slug' => 'stale-node',
            'type' => 'child',
            'url' => 'https://stale.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
        ]);

        AINode::query()->create([
            'name' => 'Master Node',
            'slug' => 'master-node',
            'type' => 'master',
            'url' => 'https://master.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
        ]);

        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => 'Keep Node',
                    'slug' => 'keep-node',
                    'url' => 'https://keep.example.test',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', [
            '--file' => $file,
            '--apply' => true,
            '--prune' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'keep-node',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'stale-node',
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'master-node',
            'status' => 'active',
        ]);
    }

    public function test_apply_fails_when_payload_contains_invalid_rows(): void
    {
        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => 'Support Node',
                    'slug' => 'support-node',
                    'url' => 'https://support.example.test',
                ],
                [
                    'name' => 'Broken Node',
                    'slug' => 'broken-node',
                    'url' => 'not-a-url',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', [
            '--file' => $file,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('ai_nodes', 0);
    }

    public function test_apply_with_autofix_normalizes_rows_and_persists_changes(): void
    {
        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => ' Support Node ',
                    'slug' => 'Support Node',
                    'url' => 'support.example.test',
                    'type' => 'unknown',
                    'status' => 'bad',
                    'capabilities' => 'search,rag',
                    'weight' => 0,
                ],
                [
                    'name' => 'Ops Node',
                    'slug' => 'Support Node',
                    'url' => 'ops.example.test',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', [
            '--file' => $file,
            '--autofix' => true,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'support-node',
            'url' => 'https://support.example.test',
            'type' => 'child',
            'status' => 'active',
            'weight' => 1,
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'support-node-2',
            'url' => 'https://ops.example.test',
            'status' => 'active',
        ]);
    }

    public function test_autofix_strict_option_requires_autofix_flag(): void
    {
        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => 'Support Node',
                    'url' => 'https://support.example.test',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', [
            '--file' => $file,
            '--autofix-strict' => true,
        ])->assertExitCode(1);
    }

    public function test_apply_with_autofix_strict_keeps_duplicates_and_fails_validation(): void
    {
        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => 'Support Node',
                    'slug' => 'support-node',
                    'url' => 'https://support.example.test',
                    'status' => 'bad',
                ],
                [
                    'name' => 'Support Node 2',
                    'slug' => 'support-node',
                    'url' => 'https://support2.example.test',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', [
            '--file' => $file,
            '--autofix' => true,
            '--autofix-strict' => true,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('ai_nodes', 0);
    }

    public function test_apply_with_autofix_uses_configured_strict_default_mode(): void
    {
        config()->set('ai-engine.nodes.bulk_sync.autofix_mode', 'strict');

        $file = $this->createNodesJson([
            'nodes' => [
                [
                    'name' => 'Support Node',
                    'slug' => 'support-node',
                    'url' => 'https://support.example.test',
                    'status' => 'bad',
                ],
                [
                    'name' => 'Support Node 2',
                    'slug' => 'support-node',
                    'url' => 'https://support2.example.test',
                ],
            ],
        ]);

        $this->artisan('ai-engine:nodes-sync', [
            '--file' => $file,
            '--autofix' => true,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('ai_nodes', 0);
    }

    protected function createNodesJson(array $payload): string
    {
        $path = sys_get_temp_dir() . '/ai-engine-nodes-' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
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
