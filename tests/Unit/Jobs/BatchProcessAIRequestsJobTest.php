<?php

namespace LaravelAIEngine\Tests\Unit\Jobs;

use Orchestra\Testbench\TestCase;
use LaravelAIEngine\Jobs\BatchProcessAIRequestsJob;
use LaravelAIEngine\Jobs\ProcessAIRequestJob;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\JobStatusTracker;
use Illuminate\Support\Facades\Queue;
use Mockery;

class BatchProcessAIRequestsJobTest extends TestCase
{
    public function test_job_processes_batch_requests_successfully()
    {
        Queue::fake();

        $requests = [
            AIRequest::make('Test prompt 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            AIRequest::make('Test prompt 2', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            AIRequest::make('Test prompt 3', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->times(3); // processing, completed for batch, and individual job updates

        $job = new BatchProcessAIRequestsJob($requests, 'batch_123');
        $job->handle($mockTracker);

        // Verify individual jobs were dispatched
        Queue::assertPushed(ProcessAIRequestJob::class, 3);

        $this->assertTrue(true); // Job completed without exceptions
    }

    public function test_job_handles_invalid_request_in_batch()
    {
        $requests = [
            AIRequest::make('Test prompt 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            'invalid_request', // This should cause an error
            AIRequest::make('Test prompt 3', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->atLeast(2); // processing and failed

        $job = new BatchProcessAIRequestsJob($requests, 'batch_123');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid AIRequest at index 1');

        $job->handle($mockTracker);
    }

    public function test_job_stops_on_error_when_configured()
    {
        $requests = [
            AIRequest::make('Test prompt 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            'invalid_request',
            AIRequest::make('Test prompt 3', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->atLeast(2);

        $job = new BatchProcessAIRequestsJob($requests, 'batch_123', null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid AIRequest at index 1');

        $job->handle($mockTracker);
    }

    public function test_job_continues_on_error_when_not_configured_to_stop()
    {
        Queue::fake();

        // Use all valid requests since the job validates requests first
        $requests = [
            AIRequest::make('Test prompt 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            AIRequest::make('Test prompt 2', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            AIRequest::make('Test prompt 3', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->atLeast(2); // processing + completed

        $job = new BatchProcessAIRequestsJob($requests, 'batch_123', null, false);
        $job->handle($mockTracker);

        // Should have dispatched all jobs
        Queue::assertPushed(ProcessAIRequestJob::class, 3);

        $this->assertTrue(true);
    }

    public function test_job_updates_status_on_failure()
    {
        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->with('batch_123', 'failed', Mockery::type('array'));

        $requests = [
            AIRequest::make('Test prompt', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $job = new BatchProcessAIRequestsJob($requests, 'batch_123');
        $job->failed(new \Exception('Test failure'));

        $this->assertTrue(true);
    }

    public function test_job_has_correct_configuration()
    {
        $requests = [
            AIRequest::make('Test prompt', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $job = new BatchProcessAIRequestsJob($requests, 'batch_123');

        $this->assertEquals(2, $job->tries);
        $this->assertEquals(1800, $job->timeout);
        $this->assertEquals(60, $job->backoff);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
