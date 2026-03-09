<?php

namespace LaravelAIEngine\Tests\Feature\Admin;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AdminNodeManagementTest extends UnitTestCase
{
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
        $app['config']->set('ai-engine.nodes.is_master', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

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
            $table->json('workflows')->nullable();
            $table->json('autonomous_collectors')->nullable();
            $table->json('metadata')->nullable();
            $table->string('version')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('weight')->default(1);
            $table->unsignedInteger('active_connections')->default(0);
            $table->unsignedInteger('ping_failures')->default(0);
            $table->unsignedInteger('avg_response_time')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_can_register_node_from_admin_ui(): void
    {
        $node = new AINode(['slug' => 'billing-node']);

        $registry = Mockery::mock(NodeRegistryService::class);
        $registry->shouldReceive('register')
            ->once()
            ->withArgs(function (array $payload): bool {
                $this->assertSame('Billing Node', $payload['name']);
                $this->assertSame('billing-node', $payload['slug']);
                $this->assertSame('child', $payload['type']);
                $this->assertSame('https://billing.example.test', $payload['url']);
                $this->assertSame(['search', 'actions', 'rag'], $payload['capabilities']);
                $this->assertSame(2, $payload['weight']);

                return true;
            })
            ->andReturn($node);

        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/register', [
                'name' => 'Billing Node',
                'slug' => 'billing-node',
                'type' => 'child',
                'url' => 'https://billing.example.test',
                'capabilities' => 'search,actions,rag',
                'weight' => 2,
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_can_update_node_status_from_admin_ui(): void
    {
        $node = AINode::query()->create([
            'name' => 'Billing Node',
            'slug' => 'billing-node',
            'type' => 'child',
            'url' => 'https://billing.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
        ]);

        $registry = Mockery::mock(NodeRegistryService::class);
        $registry->shouldReceive('updateStatus')
            ->once()
            ->withArgs(fn (AINode $model, string $status): bool => $model->id === $node->id && $status === 'inactive');

        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/status', [
                'node_id' => $node->id,
                'status' => 'inactive',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_can_update_node_details_from_admin_ui(): void
    {
        $node = AINode::query()->create([
            'name' => 'Billing Node',
            'slug' => 'billing-node',
            'type' => 'child',
            'url' => 'https://billing.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
            'description' => 'Old description',
        ]);

        $registry = Mockery::mock(NodeRegistryService::class);
        $registry->shouldReceive('updateStatus')->never();
        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/update', [
                'node_id' => $node->id,
                'name' => 'Billing Node V2',
                'slug' => 'billing-node-v2',
                'type' => 'master',
                'url' => 'https://billing-v2.example.test',
                'description' => 'Updated from admin',
                'capabilities' => 'search,rag',
                'weight' => 4,
                'status' => 'active',
                'api_key' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $node->refresh();

        $this->assertSame('Billing Node V2', $node->name);
        $this->assertSame('billing-node-v2', $node->slug);
        $this->assertSame('master', $node->type);
        $this->assertSame('https://billing-v2.example.test', $node->url);
        $this->assertSame('Updated from admin', $node->description);
        $this->assertSame(['search', 'rag'], $node->capabilities);
        $this->assertSame(4, $node->weight);
        $this->assertSame('active', $node->status);
    }

    public function test_can_ping_single_node_from_admin_ui(): void
    {
        $node = AINode::query()->create([
            'name' => 'CRM Node',
            'slug' => 'crm-node',
            'type' => 'child',
            'url' => 'https://crm.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
        ]);

        $registry = Mockery::mock(NodeRegistryService::class);
        $registry->shouldReceive('ping')
            ->once()
            ->withArgs(fn (AINode $model): bool => $model->id === $node->id)
            ->andReturn(true);

        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/ping', [
                'node_id' => $node->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_can_delete_node_from_admin_ui(): void
    {
        $node = AINode::query()->create([
            'name' => 'Inventory Node',
            'slug' => 'inventory-node',
            'type' => 'child',
            'url' => 'https://inventory.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
        ]);

        $registry = Mockery::mock(NodeRegistryService::class);
        $registry->shouldReceive('unregister')
            ->once()
            ->withArgs(function (AINode $model) use ($node): bool {
                $this->assertSame($node->id, $model->id);
                $model->delete();

                return true;
            })
            ->andReturn(true);

        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/delete', [
                'node_id' => $node->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSoftDeleted('ai_nodes', ['id' => $node->id]);
    }

    public function test_can_ping_all_nodes_from_admin_ui(): void
    {
        $registry = Mockery::mock(NodeRegistryService::class);
        $registry->shouldReceive('pingAll')
            ->once()
            ->andReturn([
                'billing-node' => ['success' => true],
                'crm-node' => ['success' => false],
            ]);

        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/ping-all')
            ->assertRedirect()
            ->assertSessionHas('status', 'Pinged 2 node(s): 1 healthy, 1 failed.');
    }

    public function test_can_preview_bulk_sync_plan_from_admin_ui(): void
    {
        AINode::query()->create([
            'name' => 'Billing Node',
            'slug' => 'billing-node',
            'type' => 'child',
            'url' => 'https://billing.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
            'version' => '1.0.0',
        ]);

        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing-v2.example.test',
                    'capabilities' => ['search', 'rag'],
                ],
                [
                    'name' => 'CRM Node',
                    'slug' => 'crm-node',
                    'url' => 'https://crm.example.test',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/preview', [
                'payload' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_sync_preview');

        $preview = session('bulk_sync_preview');
        $this->assertSame(1, data_get($preview, 'summary.create'));
        $this->assertSame(1, data_get($preview, 'summary.update'));
        $this->assertSame(0, data_get($preview, 'summary.invalid'));
    }

    public function test_can_apply_bulk_sync_plan_from_admin_ui_with_prune(): void
    {
        AINode::query()->create([
            'name' => 'Billing Node',
            'slug' => 'billing-node',
            'type' => 'child',
            'url' => 'https://billing.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
            'version' => '1.0.0',
        ]);

        AINode::query()->create([
            'name' => 'Stale Node',
            'slug' => 'stale-node',
            'type' => 'child',
            'url' => 'https://stale.example.test',
            'status' => 'active',
            'weight' => 1,
            'capabilities' => ['search'],
            'version' => '1.0.0',
        ]);

        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing-v2.example.test',
                    'capabilities' => ['search', 'rag'],
                ],
                [
                    'name' => 'CRM Node',
                    'slug' => 'crm-node',
                    'url' => 'https://crm.example.test',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $registry = Mockery::mock(NodeRegistryService::class);
        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/apply', [
                'payload' => $payload,
                'prune' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_sync_applied');

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'billing-node',
            'url' => 'https://billing-v2.example.test',
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'crm-node',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('ai_nodes', [
            'slug' => 'stale-node',
            'status' => 'inactive',
        ]);
    }

    public function test_bulk_sync_is_blocked_for_non_master_node_apps(): void
    {
        config()->set('ai-engine.nodes.is_master', false);

        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/preview', [
                'payload' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['nodes']);
    }

    public function test_bulk_sync_preview_reports_invalid_rows_with_reasons(): void
    {
        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                ],
                [
                    'name' => 'Invalid URL Node',
                    'slug' => 'invalid-url-node',
                    'url' => 'not-a-url',
                ],
                [
                    'name' => '',
                    'slug' => 'missing-name-node',
                    'url' => 'https://missing-name.example.test',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/preview', [
                'payload' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_sync_preview');

        $preview = session('bulk_sync_preview');

        $this->assertSame(1, data_get($preview, 'summary.create'));
        $this->assertSame(2, data_get($preview, 'summary.invalid'));
        $this->assertSame('Invalid URL format.', data_get($preview, 'invalid_rows.0.reason'));
        $this->assertSame(
            'Use a valid absolute URL starting with http:// or https://.',
            data_get($preview, 'invalid_rows.0.suggestion')
        );
    }

    public function test_bulk_sync_apply_is_blocked_when_invalid_rows_exist(): void
    {
        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Support Node',
                    'slug' => 'support-node',
                    'url' => 'https://support.example.test',
                ],
                [
                    'name' => 'Broken Node',
                    'slug' => 'broken-node',
                    'url' => '',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $registry = Mockery::mock(NodeRegistryService::class);
        $this->app->instance(NodeRegistryService::class, $registry);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/apply', [
                'payload' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['nodes']);

        $this->assertDatabaseMissing('ai_nodes', [
            'slug' => 'support-node',
        ]);
    }

    public function test_can_download_bulk_sync_template_from_admin_ui(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/nodes/bulk-sync/template')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="ai-engine-nodes-template.json"')
            ->assertJsonPath('nodes.0.slug', 'billing');
    }

    public function test_can_export_current_nodes_for_bulk_sync_from_admin_ui(): void
    {
        AINode::query()->create([
            'name' => 'Billing Node',
            'slug' => 'billing-node',
            'type' => 'child',
            'url' => 'https://billing.example.test',
            'status' => 'active',
            'weight' => 3,
            'capabilities' => ['search', 'rag'],
            'version' => '1.2.0',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/nodes/bulk-sync/export')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="ai-engine-nodes-export.json"')
            ->assertJsonPath('nodes.0.slug', 'billing-node')
            ->assertJsonPath('nodes.0.weight', 3);
    }

    public function test_can_preview_bulk_sync_using_uploaded_json_file(): void
    {
        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Support Node',
                    'slug' => 'support-node',
                    'url' => 'https://support.example.test',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $file = UploadedFile::fake()->createWithContent('nodes.json', (string) $payload);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/preview', [
                'payload_file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_sync_preview');
    }

    public function test_can_autofix_bulk_sync_payload_and_prepare_preview(): void
    {
        $payload = json_encode([
            'nodes' => [
                [
                    'name' => ' Billing Node ',
                    'slug' => 'Billing Node',
                    'url' => 'billing.example.test',
                    'type' => 'unknown',
                    'status' => 'bad',
                    'capabilities' => 'search,rag',
                    'weight' => 0,
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/autofix', [
                'payload' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_sync_autofix')
            ->assertSessionHas('bulk_sync_preview');

        $preview = session('bulk_sync_preview');
        $autofix = session('bulk_sync_autofix');
        $this->assertSame(1, data_get($preview, 'summary.create'));
        $this->assertSame(0, data_get($preview, 'summary.invalid'));
        $this->assertSame('smart', data_get($autofix, 'mode'));

        $oldPayload = (string) session('_old_input.payload', '');
        $decoded = json_decode($oldPayload, true);
        $this->assertIsArray($decoded);
        $this->assertSame('https://billing.example.test', data_get($decoded, 'nodes.0.url'));
        $this->assertSame('billing-node', data_get($decoded, 'nodes.0.slug'));
        $this->assertSame('active', data_get($decoded, 'nodes.0.status'));
    }

    public function test_can_download_autofixed_bulk_sync_payload_from_admin_ui(): void
    {
        $payload = json_encode([
            'nodes' => [
                [
                    'name' => ' Billing Node ',
                    'slug' => 'Billing Node',
                    'url' => 'billing.example.test',
                    'capabilities' => 'search,rag',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/autofix-download', [
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="ai-engine-nodes-autofixed.json"')
            ->assertHeader('x-ai-engine-autofix-changes')
            ->assertHeader('x-ai-engine-autofix-mode', 'smart')
            ->assertJsonPath('nodes.0.slug', 'billing-node')
            ->assertJsonPath('nodes.0.url', 'https://billing.example.test')
            ->assertJsonPath('nodes.0.capabilities.0', 'search')
            ->assertJsonPath('nodes.0.capabilities.1', 'rag');
    }

    public function test_can_download_autofixed_payload_in_strict_mode(): void
    {
        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                    'status' => 'bad',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/autofix-download', [
                'payload' => $payload,
                'autofix_strict' => '1',
            ])
            ->assertOk()
            ->assertHeader('x-ai-engine-autofix-mode', 'strict')
            ->assertJsonPath('nodes.0.status', 'bad');
    }

    public function test_autofix_strict_mode_keeps_duplicate_slug_and_reports_invalid(): void
    {
        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                    'status' => 'bad',
                ],
                [
                    'name' => 'Another Billing',
                    'slug' => 'billing-node',
                    'url' => 'https://billing2.example.test',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/autofix', [
                'payload' => $payload,
                'autofix_strict' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_sync_autofix')
            ->assertSessionHas('bulk_sync_preview');

        $autofix = session('bulk_sync_autofix');
        $preview = session('bulk_sync_preview');

        $this->assertSame('strict', data_get($autofix, 'mode'));
        $this->assertSame(1, data_get($preview, 'summary.invalid'));
        $this->assertSame('Duplicate slug in payload.', data_get($preview, 'invalid_rows.0.reason'));

        $decoded = json_decode((string) session('_old_input.payload', ''), true);
        $this->assertSame('bad', data_get($decoded, 'nodes.0.status'));
    }

    public function test_autofix_uses_configured_default_mode_when_flag_not_sent(): void
    {
        config()->set('ai-engine.nodes.bulk_sync.autofix_mode', 'strict');

        $payload = json_encode([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                    'status' => 'bad',
                ],
                [
                    'name' => 'Billing Node 2',
                    'slug' => 'billing-node',
                    'url' => 'https://billing2.example.test',
                ],
            ],
        ], JSON_PRETTY_PRINT);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/nodes/bulk-sync/autofix', [
                'payload' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_sync_autofix')
            ->assertSessionHas('bulk_sync_preview');

        $this->assertSame('strict', data_get(session('bulk_sync_autofix'), 'mode'));
        $this->assertSame(1, data_get(session('bulk_sync_preview'), 'summary.invalid'));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_nodes');

        parent::tearDown();
    }
}
