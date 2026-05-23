<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class TestSubAgentConversationCommandTest extends UnitTestCase
{
    public function test_command_runs_two_demo_sub_agents_for_long_life_chat(): void
    {
        $exitCode = Artisan::call('ai:test-sub-agent-chat', [
            '--target' => 'Create and review an invoice automation plan',
            '--rounds' => 2,
            '--session' => 'sub-agent-command-test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame(4, $payload['summary']['turn_count']);
        $this->assertSame(2, $payload['summary']['rounds_completed']);
        $this->assertSame(['planner', 'reviewer'], $payload['summary']['participants']);
        $this->assertTrue($payload['summary']['context_saved']);
        $this->assertNotEmpty($payload['transcript'][0]['message'] ?? null);
    }

    public function test_command_accepts_custom_agent_ids(): void
    {
        $exitCode = Artisan::call('ai:test-sub-agent-chat', [
            '--target' => 'Custom agent conversation',
            '--agent' => ['designer', 'auditor'],
            '--rounds' => 1,
            '--session' => 'sub-agent-custom-command-test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(['designer', 'auditor'], $payload['summary']['participants']);
        $this->assertSame(['designer', 'auditor'], array_column($payload['transcript'], 'agent_id'));
        $this->assertTrue($payload['summary']['context_saved']);
    }

    public function test_deterministic_command_does_not_extract_ai_memories_during_compaction(): void
    {
        Config::set('ai-agent.context_compaction.enabled', true);
        Config::set('ai-agent.context_compaction.max_messages', 4);
        Config::set('ai-agent.context_compaction.keep_recent_messages', 2);
        Config::set('ai-agent.conversation_memory.enabled', true);
        Config::set('ai-agent.conversation_memory.extract_on_compaction', true);

        $extractor = Mockery::mock(ConversationMemoryExtractor::class);
        $extractor->shouldNotReceive('extract');
        $this->app->instance(ConversationMemoryExtractor::class, $extractor);
        $this->app->forgetInstance(ConversationContextCompactor::class);
        $this->app->forgetInstance(ContextManager::class);

        $exitCode = Artisan::call('ai:test-sub-agent-chat', [
            '--target' => 'No provider calls in deterministic mode',
            '--rounds' => 8,
            '--session' => 'sub-agent-no-memory-extract',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['summary']['live']);
        $this->assertTrue(Config::get('ai-agent.conversation_memory.extract_on_compaction'));
    }
}
