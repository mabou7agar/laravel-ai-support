<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Tests\TestCase;

/**
 * Closes IDOR on the agent-run REST endpoints: a run holds the full conversation,
 * tool payloads, and trace, so show/trace/list/resume/cancel must be owner-scoped
 * exactly like the SSE stream endpoint (default fail-closed).
 */
class AgentRunAccessControlTest extends TestCase
{
    private function ownedRun(string $ownerId, string $session = 'acl-run', string $status = AIAgentRun::STATUS_RUNNING): AIAgentRun
    {
        return app(AgentRunRepository::class)->create([
            'session_id' => $session,
            'user_id' => $ownerId,
            'status' => $status,
        ]);
    }

    public function test_show_and_trace_block_cross_owner_access_but_allow_the_owner(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $run = $this->ownedRun((string) $owner->getAuthIdentifier());

        // Another user cannot read the run detail or trace.
        $this->actingAs($other)->getJson("/api/v1/ai/agent-runs/{$run->uuid}")->assertForbidden();
        $this->actingAs($other)->getJson("/api/v1/ai/agent-runs/{$run->uuid}/trace")->assertForbidden();

        // The owner can.
        $this->actingAs($owner)->getJson("/api/v1/ai/agent-runs/{$run->uuid}")
            ->assertOk()
            ->assertJsonPath('data.run.uuid', $run->uuid);
        $this->actingAs($owner)->getJson("/api/v1/ai/agent-runs/{$run->uuid}/trace")
            ->assertOk()
            ->assertJsonPath('data.run_id', $run->uuid);
    }

    public function test_list_cannot_be_widened_past_the_authenticated_owner(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $ownerId = (string) $owner->getAuthIdentifier();
        $otherId = (string) $other->getAuthIdentifier();

        $this->ownedRun($ownerId, 'acl-list-mine-1');
        $this->ownedRun($ownerId, 'acl-list-mine-2');
        $this->ownedRun($otherId, 'acl-list-theirs');

        // A client-supplied user_id filter for another owner must be ignored.
        $data = $this->actingAs($owner)
            ->getJson("/api/v1/ai/agent-runs?user_id={$otherId}")
            ->assertOk()
            ->json('data.data');

        $this->assertCount(2, $data, 'list must return only the authenticated owner rows.');
        foreach ($data as $row) {
            $this->assertSame($ownerId, (string) $row['user_id']);
        }
    }

    public function test_unowned_runs_are_denied_by_default_and_allowed_when_opted_in(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'acl-anon',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);

        // Default fail-closed: a run with no user_id is not public.
        $this->getJson("/api/v1/ai/agent-runs/{$run->uuid}")->assertForbidden();

        // Opt-in exposes anonymous runs.
        config()->set('ai-agent.event_stream.access.allow_anonymous_runs', true);
        $this->getJson("/api/v1/ai/agent-runs/{$run->uuid}")
            ->assertOk()
            ->assertJsonPath('data.run.uuid', $run->uuid);
    }

    public function test_resume_and_cancel_block_cross_owner_and_do_not_execute(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $run = $this->ownedRun((string) $owner->getAuthIdentifier(), 'acl-control', AIAgentRun::STATUS_WAITING_INPUT);

        $this->actingAs($other)
            ->postJson("/api/v1/ai/agent-runs/{$run->uuid}/resume", ['message' => 'go'])
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson("/api/v1/ai/agent-runs/{$run->uuid}/cancel", ['reason' => 'stop'])
            ->assertForbidden();

        // The guard runs before execution, so the run is untouched (not cancelled).
        $this->assertSame(AIAgentRun::STATUS_WAITING_INPUT, $run->fresh()->status);
    }

    public function test_owner_scoping_can_be_disabled(): void
    {
        config()->set('ai-agent.event_stream.access.authorize_owned_runs', false);

        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $run = $this->ownedRun((string) $owner->getAuthIdentifier(), 'acl-disabled');

        // With scoping off, the host owns authorization; the package does not 403.
        $this->actingAs($other)->getJson("/api/v1/ai/agent-runs/{$run->uuid}")
            ->assertOk()
            ->assertJsonPath('data.run.uuid', $run->uuid);
    }
}
