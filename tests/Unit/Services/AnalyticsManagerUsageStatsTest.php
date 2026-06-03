<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\DB;
use LaravelAIEngine\Services\AnalyticsManager;
use LaravelAIEngine\Tests\TestCase;

/**
 * These tests exercise the real database-backed analytics aggregates, so they
 * extend the full TestCase (RefreshDatabase + package migrations) rather than
 * the lighter UnitTestCase that the rest of tests/Unit defaults to. This mirrors
 * the sibling Analytics/AnalyticsManagerTest, which is also a class-based test
 * in the Unit tree.
 */
class AnalyticsManagerUsageStatsTest extends TestCase
{
    private function manager(): AnalyticsManager
    {
        return app(AnalyticsManager::class);
    }

    /**
     * Insert an ai_requests row with sensible defaults.
     */
    private function seedRequest(array $overrides = []): void
    {
        DB::table('ai_requests')->insert(array_merge([
            'user_id' => 'user-1',
            'engine' => 'openai',
            'model' => 'gpt-4o',
            'content_type' => 'text',
            'prompt_length' => 10,
            'tokens_used' => 100,
            'credits_used' => 1.0,
            'latency_ms' => 100.0,
            'cached' => false,
            'success' => true,
            'request_id' => 'req-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_success_rate_is_not_always_100_percent_with_a_mix_of_outcomes(): void
    {
        // 3 successes, 1 failure => 75% success rate.
        $this->seedRequest(['success' => true]);
        $this->seedRequest(['success' => true]);
        $this->seedRequest(['success' => true]);
        $this->seedRequest(['success' => false]);

        $stats = $this->manager()->getUsageStats();

        $this->assertSame(4, $stats['total_requests']);
        $this->assertSame(75.0, $stats['success_rate']);
        $this->assertNotSame(100.0, $stats['success_rate']);
    }

    public function test_success_rate_is_zero_when_every_request_failed(): void
    {
        $this->seedRequest(['success' => false]);
        $this->seedRequest(['success' => false]);

        $stats = $this->manager()->getUsageStats();

        $this->assertSame(2, $stats['total_requests']);
        $this->assertSame(0.0, $stats['success_rate']);
    }

    public function test_total_aggregates_are_not_corrupted_by_the_success_filter(): void
    {
        // Regression guard for the clone-per-aggregate fix: total_credits_used /
        // total_tokens_used must reflect ALL rows, not just successful ones.
        $this->seedRequest(['success' => true, 'credits_used' => 2.0, 'tokens_used' => 100]);
        $this->seedRequest(['success' => false, 'credits_used' => 3.0, 'tokens_used' => 250]);

        $stats = $this->manager()->getUsageStats();

        $this->assertSame(2, $stats['total_requests']);
        $this->assertSame(5.0, (float) $stats['total_credits_used']);
        $this->assertSame(350, (int) $stats['total_tokens_used']);
    }

    public function test_system_overview_returns_correct_aggregates(): void
    {
        // Two recent users, plus an older (inactive) third user.
        $this->seedRequest(['user_id' => 'user-1', 'success' => true, 'credits_used' => 2.0, 'latency_ms' => 100.0]);
        $this->seedRequest(['user_id' => 'user-1', 'success' => false, 'credits_used' => 3.0, 'latency_ms' => 300.0]);
        $this->seedRequest(['user_id' => 'user-2', 'success' => true, 'credits_used' => 5.0, 'latency_ms' => 200.0]);
        // Stale row: counts toward totals but NOT toward active_users (>7 days old).
        $this->seedRequest([
            'user_id' => 'user-3',
            'success' => true,
            'credits_used' => 0.0,
            'latency_ms' => 400.0,
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        $overview = $this->manager()->getSystemOverview();

        $this->assertSame(3, $overview['total_users']);
        $this->assertSame(2, $overview['active_users']);
        $this->assertSame(4, $overview['total_requests']);
        $this->assertSame(10.0, (float) $overview['total_credits_used']);
        $this->assertSame(250.0, round((float) $overview['avg_response_time'], 2));
        // 1 failure out of 4 => 25% error rate.
        $this->assertSame(25.0, $overview['error_rate']);
    }

    public function test_engine_breakdown_returns_correct_per_engine_aggregates(): void
    {
        // openai: 3 requests (2 success, 1 failure), 6 credits, avg latency 200.
        $this->seedRequest(['engine' => 'openai', 'success' => true, 'credits_used' => 1.0, 'latency_ms' => 100.0]);
        $this->seedRequest(['engine' => 'openai', 'success' => true, 'credits_used' => 2.0, 'latency_ms' => 200.0]);
        $this->seedRequest(['engine' => 'openai', 'success' => false, 'credits_used' => 3.0, 'latency_ms' => 300.0]);
        // anthropic: 1 request (success), 4 credits.
        $this->seedRequest(['engine' => 'anthropic', 'success' => true, 'credits_used' => 4.0, 'latency_ms' => 500.0]);

        $breakdown = collect($this->manager()->getEngineBreakdown())->keyBy('engine');

        $this->assertCount(2, $breakdown);

        $openai = $breakdown['openai'];
        $this->assertSame(3, $openai['requests']);
        $this->assertSame(6.0, $openai['credits_used']);
        $this->assertSame(200.0, $openai['avg_response_time']);
        $this->assertSame(round(2 / 3 * 100, 2), $openai['success_rate']);

        $anthropic = $breakdown['anthropic'];
        $this->assertSame(1, $anthropic['requests']);
        $this->assertSame(4.0, $anthropic['credits_used']);
        $this->assertSame(500.0, $anthropic['avg_response_time']);
        $this->assertSame(100.0, $anthropic['success_rate']);
    }

    public function test_engine_breakdown_respects_the_engine_filter(): void
    {
        $this->seedRequest(['engine' => 'openai']);
        $this->seedRequest(['engine' => 'anthropic']);

        $breakdown = $this->manager()->getEngineBreakdown(['engine' => 'openai']);

        $this->assertCount(1, $breakdown);
        $this->assertSame('openai', $breakdown[0]['engine']);
    }
}
