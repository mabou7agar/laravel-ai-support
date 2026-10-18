<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiSdk;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\Support\Stubs\RecordingAgentTool;
use LaravelAIEngine\Tests\TestCase;

/**
 * Smoke test of the laravel_ai runtime against a live provider. It spends
 * real (small) provider usage, so it only runs on demand:
 *
 *   AI_ENGINE_LIVE_LARAVEL_AI=1 OPENAI_API_KEY=... vendor/bin/phpunit tests/Feature/LaravelAiLiveProviderSmokeTest.php
 *
 * Requires laravel/ai. Pick the provider with AI_ENGINE_LIVE_LARAVEL_AI_PROVIDER
 * (openai, openrouter, xai, anthropic or gemini; default openai, whose key is
 * read from OPENAI_API_KEY, OPENROUTER_API_KEY, ...) and the model with
 * AI_ENGINE_LIVE_LARAVEL_AI_MODEL (default: the provider's cheapest model).
 */
class LaravelAiLiveProviderSmokeTest extends TestCase
{
    private const KEYS = [
        'openai' => 'OPENAI_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
        'xai' => 'XAI_API_KEY',
        'anthropic' => 'ANTHROPIC_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ];

    private const CHEAPEST = [
        'openai' => 'gpt-6-luna',
        'openrouter' => 'anthropic/claude-haiku-4.5',
        'xai' => 'grok-4.20-non-reasoning',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $provider = strtolower((string) (getenv('AI_ENGINE_LIVE_LARAVEL_AI_PROVIDER') ?: 'openai'));
        $key = isset(self::KEYS[$provider]) ? (string) getenv(self::KEYS[$provider]) : '';

        if (!LaravelAiSdk::installed()
            || !filter_var(getenv('AI_ENGINE_LIVE_LARAVEL_AI') ?: false, FILTER_VALIDATE_BOOL)
            || $key === ''
        ) {
            $this->markTestSkipped('Live provider smoke test: set AI_ENGINE_LIVE_LARAVEL_AI=1 and the provider API key, with laravel/ai installed.');
        }

        $this->artisan('migrate', [
            '--path' => dirname((new \ReflectionClass(\Laravel\Ai\AiServiceProvider::class))->getFileName(), 2) . '/database/migrations',
            '--realpath' => true,
        ])->run();

        config()->set("ai.providers.{$provider}.key", $key);
        config()->set('ai.providers.openai.store', false);
        config()->set('ai-engine.credits.enabled', false);
        config()->set('ai-agent.runtime.default', 'laravel_ai');
        config()->set('ai-agent.runtime.laravel_ai.enabled', true);
        config()->set('ai-agent.runtime.laravel_ai.provider', $provider);
        config()->set('ai-agent.runtime.laravel_ai.model', getenv('AI_ENGINE_LIVE_LARAVEL_AI_MODEL') ?: (self::CHEAPEST[$provider] ?? null));
        config()->set('ai-agent.runtime.laravel_ai.instructions', 'You are a terse test assistant. When asked to use a tool, call it.');
        RecordingAgentTool::reset();
    }

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists(\Laravel\Ai\AiServiceProvider::class)) {
            $providers[] = \Laravel\Ai\AiServiceProvider::class;
        }

        return $providers;
    }

    public function test_streamed_turn_completes_with_events_usage_and_credits(): void
    {
        $run = $this->createRun('live-stream');
        $this->handle(new RunAgentJob($run->id, 'Reply with exactly the word: pong', 'live-stream', '9'));

        $run->refresh();
        $events = collect($run->metadata['events'] ?? []);

        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status, json_encode($run->final_response));
        $this->assertStringContainsStringIgnoringCase('pong', $run->final_response['message']);
        $this->assertGreaterThan(0, $run->final_response['metadata']['usage']['tokens_used']);
        $this->assertGreaterThan(0, $events->where('name', AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED)->count());
        $this->assertSame(1, $events->where('name', AgentRunEventStreamService::RUN_COMPLETED)->count());
        $this->assertGreaterThan(0, (float) $run->metadata['credits_used']);
    }

    public function test_package_tool_needing_confirmation_runs_only_after_approval(): void
    {
        $tool = new RecordingAgentTool('refund_order', confirm: true);
        app(ToolRegistry::class)->register('refund_order', $tool);
        config()->set('ai-agent.runtime.laravel_ai.package_tools', ['refund_order']);

        $run = $this->createRun('live-tool');
        $this->handle(new RunAgentJob($run->id, 'Use the refund_order tool to refund order 7.', 'live-tool', '9'));

        $run->refresh();
        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $run->status, json_encode($run->final_response));
        $this->assertSame([], RecordingAgentTool::$calls);

        app(AgentRunRuntimeControlService::class)->resume($run, [
            'approval_key' => $run->final_response['data']['approval_key'],
            'actor_id' => 'admin-1',
        ]);

        $run->refresh();
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status, json_encode($run->final_response));
        $this->assertCount(1, RecordingAgentTool::$calls);
        $this->assertSame(7, (int) RecordingAgentTool::$calls[0]['parameters']['order']);
        $this->assertTrue(RecordingAgentTool::$calls[0]['parameters']['confirmed']);
    }

    private function createRun(string $sessionId): AIAgentRun
    {
        return app(AgentRunRepository::class)->create([
            'session_id' => $sessionId,
            'user_id' => '9',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);
    }

    private function handle(RunAgentJob $job): void
    {
        $job->handle(
            $this->app->make(AgentRuntimeContract::class),
            app(AgentRunRepository::class),
            app(AgentRunStepRepository::class),
            app(AgentRunSafetyService::class)
        );
    }
}
