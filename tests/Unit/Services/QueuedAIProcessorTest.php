<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Orchestra\Testbench\TestCase;
use LaravelAIEngine\Services\QueuedAIProcessor;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Jobs\ProcessAIRequestJob;
use LaravelAIEngine\Jobs\ProcessLongRunningAITaskJob;
use LaravelAIEngine\Jobs\BatchProcessAIRequestsJob;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Queue;
use Mockery;

class QueuedAIProcessorTest extends TestCase
{
    private QueuedAIProcessor $processor;
    private $mockTracker;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockTracker = Mockery::mock(JobStatusTracker::class);
        $this->processor = new QueuedAIProcessor($this->mockTracker);
    }

    public function test_queue_request_dispatches_job_and_returns_job_id()
    {
        Queue::fake();

        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->mockTracker->shouldReceive('updateStatus')
            ->once()
            ->with(Mockery::pattern('/^ai_job_/'), 'queued', Mockery::type('array'));

        $jobId = $this->processor->queueRequest($request);

        $this->assertStringStartsWith('ai_job_', $jobId);
        Queue::assertPushed(ProcessAIRequestJob::class);
    }

    public function test_queue_request_with_callback_url()
    {
        Queue::fake();

        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $callbackUrl = 'https://example.com/callback';

        $this->mockTracker->shouldReceive('updateStatus')
            ->once();

        $jobId = $this->processor->queueRequest($request, $callbackUrl);

        Queue::assertPushed(ProcessAIRequestJob::class, function ($job) use ($callbackUrl) {
            return $job->callbackUrl === $callbackUrl;
        });

        $this->assertStringStartsWith('ai_job_', $jobId);
    }

    public function test_queue_request_with_custom_queue()
    {
        Queue::fake();

        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->mockTracker->shouldReceive('updateStatus')
            ->once();

        $jobId = $this->processor->queueRequest($request, null, 'custom-queue');

        Queue::assertPushedOn('custom-queue', ProcessAIRequestJob::class);
        $this->assertStringStartsWith('ai_job_', $jobId);
    }

    public function test_queue_long_running_task_dispatches_correct_job()
    {
        Queue::fake();

        $request = AIRequest::make(
            'Generate video',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->mockTracker->shouldReceive('updateStatus')
            ->once()
            ->with(Mockery::pattern('/^long_task_/'), 'queued', Mockery::type('array'));

        $jobId = $this->processor->queueLongRunningTask(
            $request,
            'video_generation',
            'https://example.com/callback',
            ['https://example.com/progress']
        );

        $this->assertStringStartsWith('long_task_', $jobId);
        Queue::assertPushed(ProcessLongRunningAITaskJob::class);
    }

    public function test_queue_batch_dispatches_batch_job()
    {
        Queue::fake();

        $requests = [
            AIRequest::make('Test 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            AIRequest::make('Test 2', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $this->mockTracker->shouldReceive('updateStatus')
            ->once()
            ->with(Mockery::pattern('/^batch_/'), 'queued', Mockery::type('array'));

        $batchId = $this->processor->queueBatch($requests);

        $this->assertStringStartsWith('batch_', $batchId);
        Queue::assertPushed(BatchProcessAIRequestsJob::class);
    }

    public function test_queue_batch_with_stop_on_error()
    {
        Queue::fake();

        $requests = [
            AIRequest::make('Test 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $this->mockTracker->shouldReceive('updateStatus')
            ->once();

        $batchId = $this->processor->queueBatch($requests, null, true);

        Queue::assertPushed(BatchProcessAIRequestsJob::class, function ($job) {
            return $job->stopOnError === true;
        });

        $this->assertStringStartsWith('batch_', $batchId);
    }

    public function test_get_job_status_delegates_to_tracker()
    {
        $jobId = 'test_job_123';
        $expectedStatus = ['status' => 'completed', 'metadata' => []];

        $this->mockTracker->shouldReceive('getStatus')
            ->with($jobId)
            ->andReturn($expectedStatus);

        $result = $this->processor->getJobStatus($jobId);

        $this->assertEquals($expectedStatus, $result);
    }

    public function test_get_job_statuses_delegates_to_tracker()
    {
        $jobIds = ['job_1', 'job_2'];
        $expectedStatuses = [
            'job_1' => ['status' => 'completed'],
            'job_2' => ['status' => 'processing'],
        ];

        $this->mockTracker->shouldReceive('getMultipleStatuses')
            ->with($jobIds)
            ->andReturn($expectedStatuses);

        $result = $this->processor->getJobStatuses($jobIds);

        $this->assertEquals($expectedStatuses, $result);
    }

    public function test_is_job_completed_delegates_to_tracker()
    {
        $jobId = 'completed_job';

        $this->mockTracker->shouldReceive('isCompleted')
            ->with($jobId)
            ->andReturn(true);

        $result = $this->processor->isJobCompleted($jobId);

        $this->assertTrue($result);
    }

    public function test_is_job_running_delegates_to_tracker()
    {
        $jobId = 'running_job';

        $this->mockTracker->shouldReceive('isRunning')
            ->with($jobId)
            ->andReturn(true);

        $result = $this->processor->isJobRunning($jobId);

        $this->assertTrue($result);
    }

    public function test_get_job_progress_delegates_to_tracker()
    {
        $jobId = 'progress_job';

        $this->mockTracker->shouldReceive('getProgress')
            ->with($jobId)
            ->andReturn(75);

        $result = $this->processor->getJobProgress($jobId);

        $this->assertEquals(75, $result);
    }

    public function test_get_statistics_delegates_to_tracker()
    {
        $expectedStats = [
            'total_jobs' => 100,
            'completed_jobs' => 80,
            'failed_jobs' => 10,
            'processing_jobs' => 10,
            'success_rate' => 80.0,
        ];

        $this->mockTracker->shouldReceive('getStatistics')
            ->with(24)
            ->andReturn($expectedStats);

        $result = $this->processor->getStatistics(24);

        $this->assertEquals($expectedStats, $result);
    }

    public function test_cleanup_delegates_to_tracker()
    {
        $this->mockTracker->shouldReceive('cleanup')
            ->with(48)
            ->andReturn(25);

        $result = $this->processor->cleanup(48);

        $this->assertEquals(25, $result);
    }

    public function test_queue_multiple_requests_dispatches_individual_jobs()
    {
        Queue::fake();

        $requests = [
            AIRequest::make('Test 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            AIRequest::make('Test 2', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            AIRequest::make('Test 3', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $this->mockTracker->shouldReceive('updateStatus')
            ->times(3);

        $jobIds = $this->processor->queueMultipleRequests($requests);

        $this->assertCount(3, $jobIds);
        foreach ($jobIds as $jobId) {
            $this->assertStringStartsWith('ai_job_', $jobId);
        }

        Queue::assertPushed(ProcessAIRequestJob::class, 3);
    }

    public function test_queue_multiple_requests_throws_exception_for_invalid_request()
    {
        $requests = [
            AIRequest::make('Test 1', EngineEnum::OPENAI, EntityEnum::GPT_4O),
            'invalid_request',
            AIRequest::make('Test 3', EngineEnum::OPENAI, EntityEnum::GPT_4O),
        ];

        $this->mockTracker->shouldReceive('updateStatus')
            ->once(); // For the first valid request before exception

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid AIRequest at index 1');

        $this->processor->queueMultipleRequests($requests);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
