<?php

namespace MagicAI\LaravelAIEngine\Tests\Unit\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\Job;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Exceptions\RateLimitExceededException;
use MagicAI\LaravelAIEngine\Services\RateLimitManager;
use MagicAI\LaravelAIEngine\Services\JobStatusTracker;
use MagicAI\LaravelAIEngine\Traits\HandlesRateLimiting;
use MagicAI\LaravelAIEngine\Tests\TestCase;
use Mockery;

class HandlesRateLimitingTest extends TestCase
{

    private $mockRateLimitManager;
    private $mockStatusTracker;
    private $testJob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRateLimitManager = Mockery::mock(RateLimitManager::class);
        $this->mockStatusTracker = Mockery::mock(JobStatusTracker::class);

        $this->app->instance(RateLimitManager::class, $this->mockRateLimitManager);
        $this->app->instance(JobStatusTracker::class, $this->mockStatusTracker);

        // Create a test job that uses the trait
        $this->testJob = new class {
            use HandlesRateLimiting;

            public $released = false;
            public $releaseDelay = 0;

            public function release($delay = 0)
            {
                $this->released = true;
                $this->releaseDelay = $delay;
            }

            public function getRateLimitUserId(): ?string
            {
                return 'test-user-123';
            }
        };
    }

    public function test_check_rate_limit_passes_when_within_limits()
    {
        // Arrange
        $engine = EngineEnum::OPENAI;
        $userId = 'test-user-123';
        $jobId = 'test-job-123';

        $this->mockRateLimitManager
            ->shouldReceive('checkRateLimit')
            ->once()
            ->with($engine, $userId)
            ->andReturn(true);

        $this->mockRateLimitManager
            ->shouldReceive('getRemainingRequests')
            ->once()
            ->with($engine, $userId)
            ->andReturn(50);

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->once()
            ->with($jobId, 'processing', [
                'rate_limit_check' => 'passed',
                'remaining_requests' => 50,
            ]);

        // Act & Assert - Should not throw exception
        $this->testJob->checkRateLimit($engine, $userId, $jobId);
        $this->assertFalse($this->testJob->released);
    }

    public function test_check_rate_limit_releases_job_when_rate_limited()
    {
        // Arrange
        $engine = EngineEnum::OPENAI;
        $userId = 'test-user-123';
        $jobId = 'test-job-123';

        $this->mockRateLimitManager
            ->shouldReceive('checkRateLimit')
            ->once()
            ->with($engine, $userId)
            ->andThrow(new RateLimitExceededException('Rate limit exceeded'));

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->once()
            ->with($jobId, 'rate_limited', [
                'rate_limit_exceeded_at' => Mockery::type('object'),
                'rate_limit_error' => 'Rate limit exceeded',
                'remaining_requests' => 0,
            ]);

        // Act
        $this->testJob->checkRateLimit($engine, $userId, $jobId);

        // Assert
        $this->assertTrue($this->testJob->released);
        $this->assertGreaterThan(0, $this->testJob->releaseDelay);
    }

    public function test_calculate_rate_limit_delay_uses_engine_config()
    {
        // Arrange
        $engine = EngineEnum::OPENAI;
        
        config(['ai-engine.rate_limiting.per_engine.openai' => [
            'requests' => 100,
            'per_minute' => 2
        ]]);

        // Act
        $delay = $this->testJob->calculateRateLimitDelay($engine);

        // Assert
        $this->assertGreaterThanOrEqual(120, $delay); // 2 minutes base
        $this->assertLessThanOrEqual(300, $delay); // Max 5 minutes
    }

    public function test_calculate_rate_limit_delay_defaults_when_no_config()
    {
        // Arrange
        $engine = EngineEnum::OPENAI;
        config(['ai-engine.rate_limiting.per_engine.openai' => null]);

        // Act
        $delay = $this->testJob->calculateRateLimitDelay($engine);

        // Assert
        $this->assertGreaterThanOrEqual(60, $delay); // Default 1 minute base
        $this->assertLessThanOrEqual(300, $delay); // Max 5 minutes
    }

    public function test_should_check_rate_limit_respects_config()
    {
        // Test when rate limiting is enabled
        config(['ai-engine.rate_limiting.enabled' => true]);
        config(['ai-engine.rate_limiting.apply_to_jobs' => true]);
        $this->assertTrue($this->testJob->shouldCheckRateLimit());

        // Test when rate limiting is disabled
        config(['ai-engine.rate_limiting.enabled' => false]);
        $this->assertFalse($this->testJob->shouldCheckRateLimit());

        // Test when job rate limiting is disabled
        config(['ai-engine.rate_limiting.enabled' => true]);
        config(['ai-engine.rate_limiting.apply_to_jobs' => false]);
        $this->assertFalse($this->testJob->shouldCheckRateLimit());
    }

    public function test_handle_batch_rate_limit_splits_requests_correctly()
    {
        // Arrange
        $engine = EngineEnum::OPENAI;
        $requests = [
            (object)['id' => 1],
            (object)['id' => 2],
            (object)['id' => 3],
        ];
        $jobId = 'batch-job-123';

        $this->mockRateLimitManager
            ->shouldReceive('getRemainingRequests')
            ->times(3)
            ->with($engine, 'test-user-123')
            ->andReturn(2, 1, 0); // First 2 can process, 3rd cannot

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->once()
            ->with($jobId, 'partially_rate_limited', [
                'processable_count' => 2,
                'delayed_count' => 1,
                'rate_limited_at' => Mockery::type('object'),
            ]);

        // Act
        $result = $this->testJob->handleBatchRateLimit($engine, $requests, $jobId);

        // Assert
        $this->assertCount(2, $result['processable']);
        $this->assertCount(1, $result['delayed']);
        $this->assertEquals(1, $result['processable'][0]->id);
        $this->assertEquals(2, $result['processable'][1]->id);
        $this->assertEquals(3, $result['delayed'][0]->id);
    }

    public function test_handle_batch_rate_limit_with_rate_limit_exception()
    {
        // Arrange
        $engine = EngineEnum::OPENAI;
        $requests = [(object)['id' => 1]];
        $jobId = 'batch-job-123';

        $this->mockRateLimitManager
            ->shouldReceive('getRemainingRequests')
            ->once()
            ->with($engine, 'test-user-123')
            ->andThrow(new RateLimitExceededException('Rate limit exceeded'));

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->once()
            ->with($jobId, 'partially_rate_limited', [
                'processable_count' => 0,
                'delayed_count' => 1,
                'rate_limited_at' => Mockery::type('object'),
            ]);

        // Act
        $result = $this->testJob->handleBatchRateLimit($engine, $requests, $jobId);

        // Assert
        $this->assertCount(0, $result['processable']);
        $this->assertCount(1, $result['delayed']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
