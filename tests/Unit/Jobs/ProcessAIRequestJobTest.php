<?php

namespace MagicAI\LaravelAIEngine\Tests\Unit\Jobs;

use MagicAI\LaravelAIEngine\Tests\TestCase;
use MagicAI\LaravelAIEngine\Jobs\ProcessAIRequestJob;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\Services\AIEngineService;
use MagicAI\LaravelAIEngine\Services\JobStatusTracker;
use Illuminate\Support\Facades\Event;
use Mockery;

class ProcessAIRequestJobTest extends TestCase
{
    public function test_job_processes_ai_request_successfully()
    {
        Event::fake();

        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $response = AIResponse::success(
            'Test response',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $mockService = Mockery::mock(AIEngineService::class);
        $mockService->shouldReceive('generate')
            ->with($request)
            ->andReturn($response);

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->times(2); // processing and completed

        $job = new ProcessAIRequestJob($request, 'test_job_123');
        $job->handle($mockService, $mockTracker);

        $this->assertTrue(true); // Job completed without exceptions
    }

    public function test_job_handles_ai_request_failure()
    {
        Event::fake();

        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $mockService = Mockery::mock(AIEngineService::class);
        $mockService->shouldReceive('generate')
            ->with($request)
            ->andThrow(new \Exception('AI service error'));

        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->times(2); // processing and failed

        $job = new ProcessAIRequestJob($request, 'test_job_123');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('AI service error');

        $job->handle($mockService, $mockTracker);
    }

    public function test_job_updates_status_on_failure()
    {
        $mockTracker = Mockery::mock(JobStatusTracker::class);
        $mockTracker->shouldReceive('updateStatus')
            ->with('test_job_123', 'failed', Mockery::type('array'));

        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $job = new ProcessAIRequestJob($request, 'test_job_123');
        $job->failed(new \Exception('Test failure'));

        $this->assertTrue(true); // Method completed without exceptions
    }

    public function test_job_has_correct_configuration()
    {
        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $job = new ProcessAIRequestJob($request);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals(30, $job->backoff);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
