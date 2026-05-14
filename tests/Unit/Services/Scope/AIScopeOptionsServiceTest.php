<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Scope;

use LaravelAIEngine\Contracts\AIScopeResolver;
use LaravelAIEngine\Services\Scope\AIScopeOptionsService;
use LaravelAIEngine\Services\Scope\DefaultAIScopeResolver;
use LaravelAIEngine\Tests\UnitTestCase;

class AIScopeOptionsServiceTest extends UnitTestCase
{
    public function test_merge_injects_resolved_scope_without_overriding_explicit_options(): void
    {
        $service = new AIScopeOptionsService(new class implements AIScopeResolver {
            public function resolve(mixed $userId = null, array $options = []): array
            {
                return [
                    'tenant_id' => 'tenant-from-resolver',
                    'workspace_id' => 'workspace-from-resolver',
                ];
            }
        });

        $result = $service->merge(9, ['tenant_id' => 'tenant-explicit']);

        $this->assertSame('tenant-explicit', $result['tenant_id']);
        $this->assertSame('workspace-from-resolver', $result['workspace_id']);
    }

    public function test_merge_normalizes_tenant_and_workspace_aliases(): void
    {
        $service = new AIScopeOptionsService(new class implements AIScopeResolver {
            public function resolve(mixed $userId = null, array $options = []): array
            {
                return [
                    'tenant' => 'tenant-a',
                    'workspace' => 'workspace-a',
                ];
            }
        });

        $result = $service->merge(9, []);

        $this->assertSame('tenant-a', $result['tenant_id']);
        $this->assertSame('workspace-a', $result['workspace_id']);
    }

    public function test_merge_can_be_disabled_by_config(): void
    {
        config()->set('ai-engine.scope.auto_inject', false);

        $service = new AIScopeOptionsService(new class implements AIScopeResolver {
            public function resolve(mixed $userId = null, array $options = []): array
            {
                return ['tenant_id' => 'tenant-a'];
            }
        });

        $this->assertSame([
            'workspace' => 'workspace-explicit',
            'workspace_id' => 'workspace-explicit',
        ], $service->merge(9, [
            'workspace' => 'workspace-explicit',
        ]));
    }

    public function test_default_resolver_reads_configured_user_fields(): void
    {
        config()->set('ai-engine.scope.tenant_user_fields', ['organization.id']);
        config()->set('ai-engine.scope.workspace_user_fields', ['current_workspace_id']);

        $scope = (new DefaultAIScopeResolver())->resolve(9, [
            'user' => (object) [
                'organization' => (object) ['id' => 'tenant-a'],
                'current_workspace_id' => 'workspace-a',
            ],
        ]);

        $this->assertSame([
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
        ], $scope);
    }
}
