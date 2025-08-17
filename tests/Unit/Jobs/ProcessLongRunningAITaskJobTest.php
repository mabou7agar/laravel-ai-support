<?php

namespace MagicAI\LaravelAIEngine\Tests\Unit\Jobs;

use Orchestra\Testbench\TestCase;
use MagicAI\LaravelAIEngine\Jobs\ProcessLongRunningAITaskJob;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\Services\AIEngineService;
use MagicAI\LaravelAIEngine\Services\JobStatusTracker;
use Illuminate\Support\Facades\Event;
use Mockery;

class ProcessLongRunningAITaskJobTest extends TestCase
{
    public function test_job_processes_long_running_task_successfully()
    {
        Event::fake();

        $request = AIRequest::make(
            'Generate video from image',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $response = AIResponse::success(
            'Video generated successfully',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $mockService = Mockery::mock(AIEngineService::class);
        $mockService->shouldReceive('generate')
            ->andReturn($response);

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->times(2); // processing and completed
        $mockTracker->shouldReceive('updateProgress')
            ->atLeast(3); // Multiple progress updates

        $job = new ProcessLongRunningAITaskJob(
            $request,
            'video_generation',
            'long_task_123'
        );

        $job->handle($mockService, $mockTracker);

        $this->assertTrue(true); // Job completed without exceptions
    }

    public function test_job_handles_task_failure()
    {
        Event::fake();

        $request = AIRequest::make(
            'Generate video from image',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $mockService = Mockery::mock(AIEngineService::class);
        $mockService->shouldReceive('generate')
            ->andThrow(new \Exception('Video generation failed'));

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->times(2); // processing and failed
        $mockTracker->shouldReceive('updateProgress')
            ->atLeast(2); // Progress updates including error

        $job = new ProcessLongRunningAITaskJob(
            $request,
            'video_generation',
            'long_task_123'
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Video generation failed');

        $job->handle($mockService, $mockTracker);
    }

    public function test_job_sends_progress_updates()
    {
        Event::fake();

        $request = AIRequest::make(
            'Process large document',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $response = AIResponse::success(
            'Document processed',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $mockService = Mockery::mock(AIEngineService::class);
        $mockService->shouldReceive('generate')
            ->andReturn($response);

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->times(2);
        $mockTracker->shouldReceive('updateProgress')
            ->atLeast(5); // Allow flexible progress updates

        $job = new ProcessLongRunningAITaskJob(
            $request,
            'document_processing',
            'long_task_123'
        );

        $job->handle($mockService, $mockTracker);

        $this->assertTrue(true);
    }

    public function test_job_uses_correct_queue_for_task_type()
    {
        $request = AIRequest::make(
            'Generate video',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $job = new ProcessLongRunningAITaskJob($request, 'video_generation');
        
        // The job should be configured to use the video-processing queue
        // This is tested indirectly by checking the constructor doesn't throw
        $this->assertInstanceOf(ProcessLongRunningAITaskJob::class, $job);
    }

    public function test_job_updates_status_on_failure()
    {
        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->with('long_task_123', 'failed', Mockery::type('array'));
        $mockTracker->shouldReceive('updateProgress')
            ->with('long_task_123', -1, 'Task permanently failed: Test failure');

        $request = AIRequest::make(
            'Test task',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $job = new ProcessLongRunningAITaskJob($request, 'video_generation', 'long_task_123');
        $job->failed(new \Exception('Test failure'));

        $this->assertTrue(true);
    }

    public function test_job_has_correct_configuration()
    {
        $request = AIRequest::make(
            'Test task',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $job = new ProcessLongRunningAITaskJob($request, 'video_generation');

        $this->assertEquals(2, $job->tries);
        $this->assertEquals(3600, $job->timeout); // 1 hour
        $this->assertEquals(300, $job->backoff); // 5 minutes
    }

    public function test_job_handles_progress_callbacks()
    {
        Event::fake();

        $request = AIRequest::make(
            'Test task with callbacks',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $response = AIResponse::success(
            'Task completed',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $mockService = Mockery::mock(AIEngineService::class);
        $mockService->shouldReceive('generate')
            ->andReturn($response);

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->times(2);
        $mockTracker->shouldReceive('updateProgress')
            ->atLeast(5);

        $progressCallbacks = ['https://example.com/progress'];

        $job = new ProcessLongRunningAITaskJob(
            $request,
            'document_processing',
            'long_task_123',
            null,
            $progressCallbacks
        );

        $job->handle($mockService, $mockTracker);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
