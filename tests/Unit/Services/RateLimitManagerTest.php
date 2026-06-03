<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\RateLimitExceededException;
use LaravelAIEngine\Services\RateLimitManager;
use LaravelAIEngine\Tests\UnitTestCase;

class RateLimitManagerTest extends UnitTestCase
{
    private RateLimitManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai-engine.rate_limiting.enabled', true);
        Config::set('ai-engine.rate_limiting.driver', 'array');
        Config::set('ai-engine.rate_limiting.per_engine.openai', [
            'requests' => 3,
            'per_minute' => 1,
        ]);

        Cache::driver('array')->flush();

        $this->manager = new RateLimitManager($this->app);
    }

    public function test_sequential_increments_enforce_the_limit(): void
    {
        $engine = EngineEnum::OpenAI;

        // First three requests are within the limit of 3.
        $this->assertTrue($this->manager->checkRateLimit($engine, 'user-1'));
        $this->assertTrue($this->manager->checkRateLimit($engine, 'user-1'));
        $this->assertTrue($this->manager->checkRateLimit($engine, 'user-1'));

        // Fourth request exceeds the limit.
        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('Rate limit exceeded for engine openai. Limit: 3 requests per 1 minute(s)');

        $this->manager->checkRateLimit($engine, 'user-1');
    }

    public function test_remaining_requests_tracks_atomic_counter(): void
    {
        $engine = EngineEnum::OpenAI;

        $this->assertSame(3, $this->manager->getRemainingRequests($engine, 'user-2'));

        $this->manager->checkRateLimit($engine, 'user-2');
        $this->assertSame(2, $this->manager->getRemainingRequests($engine, 'user-2'));

        $this->manager->checkRateLimit($engine, 'user-2');
        $this->assertSame(1, $this->manager->getRemainingRequests($engine, 'user-2'));

        $this->manager->checkRateLimit($engine, 'user-2');
        $this->assertSame(0, $this->manager->getRemainingRequests($engine, 'user-2'));
    }

    public function test_over_limit_request_is_rolled_back_and_counter_does_not_inflate(): void
    {
        $engine = EngineEnum::OpenAI;

        // Saturate the limit.
        $this->manager->checkRateLimit($engine, 'user-3');
        $this->manager->checkRateLimit($engine, 'user-3');
        $this->manager->checkRateLimit($engine, 'user-3');

        // Several over-limit attempts must all reject and must not inflate the
        // stored counter (each rolls back its own increment).
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->manager->checkRateLimit($engine, 'user-3');
                $this->fail('Expected RateLimitExceededException.');
            } catch (RateLimitExceededException $e) {
                // expected
            }
        }

        $key = 'ai_engine:rate_limit:openai:user:user-3';
        $this->assertSame(3, (int) Cache::driver('array')->get($key));
        $this->assertSame(0, $this->manager->getRemainingRequests($engine, 'user-3'));
    }

    public function test_window_ttl_is_set_once_and_does_not_slide_on_each_increment(): void
    {
        $engine = EngineEnum::OpenAI;
        $key = 'ai_engine:rate_limit:openai:user:user-4';

        $start = Carbon::parse('2026-06-02 12:00:00');
        Carbon::setTestNow($start);

        // First request seeds the counter and stamps the 60s window.
        $this->manager->checkRateLimit($engine, 'user-4');

        // 30 seconds later a second request increments the SAME window.
        Carbon::setTestNow($start->copy()->addSeconds(30));
        $this->manager->checkRateLimit($engine, 'user-4');

        // At 59s the window is still alive (was never extended).
        Carbon::setTestNow($start->copy()->addSeconds(59));
        $this->assertSame(2, (int) Cache::driver('array')->get($key));

        // At 61s the original window has expired even though we kept incrementing,
        // proving the TTL did not slide forward on each call.
        Carbon::setTestNow($start->copy()->addSeconds(61));
        $this->assertNull(Cache::driver('array')->get($key));

        Carbon::setTestNow();
    }

    public function test_disabled_rate_limiting_always_passes(): void
    {
        Config::set('ai-engine.rate_limiting.enabled', false);

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->manager->checkRateLimit(EngineEnum::OpenAI, 'user-5'));
        }
    }
}
