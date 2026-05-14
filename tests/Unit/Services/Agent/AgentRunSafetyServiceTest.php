<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentRunSafetyServiceTest extends UnitTestCase
{
    public function test_session_lock_key_is_tenant_and_workspace_aware(): void
    {
        $service = new AgentRunSafetyService();

        $tenantA = $service->sessionLockKey('session-1', 'tenant-a', 'workspace-1');
        $tenantB = $service->sessionLockKey('session-1', 'tenant-b', 'workspace-1');

        $this->assertNotSame($tenantA, $tenantB);
        $this->assertSame($tenantA, $service->sessionLockKey('session-1', 'tenant-a', 'workspace-1'));
    }

    public function test_session_lock_wraps_mutation_callback(): void
    {
        $service = new AgentRunSafetyService();

        $result = $service->withSessionLock(
            'session-lock-test',
            static fn (): string => 'mutated',
            tenantId: 'tenant-a',
            workspaceId: 'workspace-a',
            ttlSeconds: 5,
            waitSeconds: 0
        );

        $this->assertSame('mutated', $result);
    }

    public function test_duplicate_message_protection_is_scoped_to_session_user_and_tenant(): void
    {
        $service = new AgentRunSafetyService();

        $first = $service->rememberMessage('session-1', '  Hello   World ', 7, 'tenant-a', 'workspace-a', 60);
        $second = $service->rememberMessage('session-1', 'hello world', 7, 'tenant-a', 'workspace-a', 60);
        $otherTenant = $service->rememberMessage('session-1', 'hello world', 7, 'tenant-b', 'workspace-a', 60);

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertTrue($otherTenant);
    }

    public function test_scope_from_options_reuses_tenant_and_workspace_metadata(): void
    {
        $service = new AgentRunSafetyService();

        $this->assertSame([
            'tenant_id' => 'tenant-9',
            'workspace_id' => 'workspace-3',
        ], $service->scopeFromOptions([
            'tenant_id' => 'tenant-9',
            'workspace' => 'workspace-3',
        ]));
    }

    public function test_scope_key_and_metadata_use_one_normalized_shape(): void
    {
        $service = new AgentRunSafetyService();
        $scope = [
            'tenant_id' => 'tenant-9',
            'workspace_id' => 'workspace-3',
        ];

        $this->assertSame($scope, $service->currentScope($scope));
        $this->assertSame($service->scopeKey($scope), $service->scopeKey('tenant-9', 'workspace-3'));

        $metadata = $service->applyScopeToMetadata([
            'tenant_id' => 'tenant-9',
            'workspace_id' => 'workspace-3',
            'existing' => true,
        ]);

        $this->assertTrue($metadata['existing']);
        $this->assertSame('tenant-9', $metadata['tenant_id']);
        $this->assertSame('workspace-3', $metadata['workspace_id']);
        $this->assertSame($service->scopeKey($scope), $metadata['scope_key']);
    }

    public function test_idempotency_keys_are_claimed_once_per_scope(): void
    {
        $service = new AgentRunSafetyService();

        $this->assertTrue($service->rememberIdempotencyKey('idem-1', ['run_id' => 1], 60));
        $this->assertFalse($service->rememberIdempotencyKey('idem-1', ['run_id' => 1], 60));
        $this->assertTrue($service->rememberIdempotencyKey('idem-1', ['run_id' => 2], 60));
    }

    public function test_assert_run_scope_reuses_vector_access_control_scope_flags(): void
    {
        config()->set('vector-access-control.enable_tenant_scope', true);
        config()->set('vector-access-control.enable_workspace_scope', true);

        $run = new AIAgentRun([
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
        ]);

        $service = new AgentRunSafetyService();
        $service->assertRunScope($run, [
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tenant scope');

        $service->assertRunScope($run, [
            'tenant_id' => 'tenant-b',
            'workspace_id' => 'workspace-a',
        ]);
    }
}
