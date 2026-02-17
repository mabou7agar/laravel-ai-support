<?php

namespace LaravelAIEngine\Tests\Unit\Jobs;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\RateLimitExceededException;
use LaravelAIEngine\Jobs\ProcessAIRequestJob;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Services\RateLimitManager;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class ProcessAIRequestJobRateLimitingTest extends UnitTestCase
{

    private $mockAIEngineService;
    private $mockStatusTracker;
    private $mockRateLimitManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAIEngineService = Mockery::mock(AIEngineService::class);
        $this->mockStatusTracker = Mockery::mock(JobStatusTracker::class);
        $this->mockRateLimitManager = Mockery::mock(RateLimitManager::class);

        $this->app->instance(AIEngineService::class, $this->mockAIEngineService);
        $this->app->instance(JobStatusTracker::class, $this->mockStatusTracker);
        $this->app->instance(RateLimitManager::class, $this->mockRateLimitManager);
    }

    public function test_job_processes_successfully_when_rate_limit_check_passes()
    {
        // Arrange
        config(['ai-engine.rate_limiting.enabled' => true]);
        config(['ai-engine.rate_limiting.apply_to_jobs' => true]);

        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: 'user-123'
        );

        $response = AIResponse::success(
            'Generated content',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            ['tokens' => 100]
        );

        $job = new ProcessAIRequestJob($request, 'test-job-123', null, 'user-123');

        // Mock rate limit check passes
        $this->mockRateLimitManager
            ->shouldReceive('checkRateLimit')
            ->once()
            ->with(Mockery::type(EngineEnum::class), 'user-123')
            ->andReturn(true);

        $this->mockRateLimitManager
            ->shouldReceive('getRemainingRequests')
            ->once()
            ->with(Mockery::type(EngineEnum::class), 'user-123')
            ->andReturn(50);

        // Mock status updates
        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'processing', Mockery::type('array'))
            ->twice();

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'completed', Mockery::type('array'))
            ->once();

        // Mock AI processing
        $this->mockAIEngineService
            ->shouldReceive('generate')
            ->once()
            ->with($request)
            ->andReturn($response);

        // Act
        $job->handle($this->mockAIEngineService, $this->mockStatusTracker);

        // Assert - No exceptions thrown, job completed successfully
        $this->assertTrue(true);
    }

    public function test_job_is_released_when_rate_limited()
    {
        // Arrange
        config(['ai-engine.rate_limiting.enabled' => true]);
        config(['ai-engine.rate_limiting.apply_to_jobs' => true]);
        config(['ai-engine.rate_limiting.per_engine.openai' => [
            'requests' => 100,
            'per_minute' => 1
        ]]);

        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: 'user-123'
        );

        $job = new class($request, 'test-job-123', null, 'user-123') extends ProcessAIRequestJob {
            public $wasReleased = false;
            public $releaseDelay = 0;

            public function release($delay = 0)
            {
                $this->wasReleased = true;
                $this->releaseDelay = $delay;
            }
        };

        // Mock rate limit exceeded
        $this->mockRateLimitManager
            ->shouldReceive('checkRateLimit')
            ->once()
            ->with(Mockery::type(EngineEnum::class), 'user-123')
            ->andThrow(new RateLimitExceededException('Rate limit exceeded'));

        // The job may still call generate after rate limit check, so mock it
        $this->mockAIEngineService
            ->shouldReceive('generate')
            ->andReturn(AIResponse::error('Rate limited', EngineEnum::OPENAI, EntityEnum::GPT_4O))
            ->atMost()
            ->once();

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'processing', Mockery::type('array'))
            ->once();

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'rate_limited', Mockery::type('array'))
            ->once();

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'completed', Mockery::type('array'))
            ->once();

        // Act
        $job->handle($this->mockAIEngineService, $this->mockStatusTracker);

        // Assert
        $this->assertTrue($job->wasReleased);
        $this->assertGreaterThan(0, $job->releaseDelay);
    }

    public function test_job_skips_rate_limiting_when_disabled()
    {
        // Arrange
        config(['ai-engine.rate_limiting.enabled' => false]);

        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: 'user-123'
        );

        $response = AIResponse::success(
            'Generated content',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            ['tokens' => 100]
        );

        $job = new ProcessAIRequestJob($request, 'test-job-123', null, 'user-123');

        // Rate limit manager should not be called
        $this->mockRateLimitManager
            ->shouldNotReceive('checkRateLimit');

        // Mock status updates
        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'processing', Mockery::type('array'))
            ->once();

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'completed', Mockery::type('array'))
            ->once();

        // Mock AI processing
        $this->mockAIEngineService
            ->shouldReceive('generate')
            ->once()
            ->with($request)
            ->andReturn($response);

        // Act
        $job->handle($this->mockAIEngineService, $this->mockStatusTracker);

        // Assert - No exceptions thrown, job completed successfully
        $this->assertTrue(true);
    }

    public function test_job_skips_rate_limiting_when_job_rate_limiting_disabled()
    {
        // Arrange
        config(['ai-engine.rate_limiting.enabled' => true]);
        config(['ai-engine.rate_limiting.apply_to_jobs' => false]);

        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: 'user-123'
        );

        $response = AIResponse::success(
            'Generated content',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            ['tokens' => 100]
        );

        $job = new ProcessAIRequestJob($request, 'test-job-123', null, 'user-123');

        // Rate limit manager should not be called
        $this->mockRateLimitManager
            ->shouldNotReceive('checkRateLimit');

        // Mock status updates
        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'processing', Mockery::type('array'))
            ->once();

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'completed', Mockery::type('array'))
            ->once();

        // Mock AI processing
        $this->mockAIEngineService
            ->shouldReceive('generate')
            ->once()
            ->with($request)
            ->andReturn($response);

        // Act
        $job->handle($this->mockAIEngineService, $this->mockStatusTracker);

        // Assert - No exceptions thrown, job completed successfully
        $this->assertTrue(true);
    }

    public function test_job_uses_correct_user_id_for_rate_limiting()
    {
        // Arrange
        config(['ai-engine.rate_limiting.enabled' => true]);
        config(['ai-engine.rate_limiting.apply_to_jobs' => true]);

        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: 'request-user-456'
        );

        $response = AIResponse::success(
            'Generated content',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            ['tokens' => 100]
        );

        // Job user ID should override request user ID
        $job = new ProcessAIRequestJob($request, 'test-job-123', null, 'job-user-789');

        // Mock rate limit check with job user ID
        $this->mockRateLimitManager
            ->shouldReceive('checkRateLimit')
            ->once()
            ->with(Mockery::type(EngineEnum::class), 'job-user-789')
            ->andReturn(true);

        $this->mockRateLimitManager
            ->shouldReceive('getRemainingRequests')
            ->once()
            ->with(Mockery::type(EngineEnum::class), 'job-user-789')
            ->andReturn(50);

        // Mock status updates
        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'processing', Mockery::type('array'))
            ->twice();

        $this->mockStatusTracker
            ->shouldReceive('updateStatus')
            ->with('test-job-123', 'completed', Mockery::type('array'))
            ->once();

        // Mock AI processing
        $this->mockAIEngineService
            ->shouldReceive('generate')
            ->once()
            ->with($request)
            ->andReturn($response);

        // Act
        $job->handle($this->mockAIEngineService, $this->mockStatusTracker);

        // Assert - No exceptions thrown, job completed successfully
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
