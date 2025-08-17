<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Orchestra\Testbench\TestCase;
use LaravelAIEngine\Services\JobStatusTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;

class JobStatusTrackerTest extends TestCase
{
    private JobStatusTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new JobStatusTracker();
        Cache::flush();
    }

    public function test_update_status_stores_in_cache()
    {
        $jobId = 'test_job_123';
        $status = 'processing';
        $metadata = ['engine' => 'openai', 'model' => 'gpt-4o'];

        $this->tracker->updateStatus($jobId, $status, $metadata);

        $cached = Cache::get('ai_job_status:' . $jobId);
        
        $this->assertNotNull($cached);
        $this->assertEquals($jobId, $cached['job_id']);
        $this->assertEquals($status, $cached['status']);
        $this->assertEquals($metadata, $cached['metadata']);
        $this->assertArrayHasKey('updated_at', $cached);
    }

    public function test_get_status_retrieves_from_cache()
    {
        $jobId = 'test_job_123';
        $expectedData = [
            'job_id' => $jobId,
            'status' => 'completed',
            'updated_at' => now()->toISOString(),
            'metadata' => ['credits_used' => 10],
        ];

        Cache::put('ai_job_status:' . $jobId, $expectedData, 3600);

        $result = $this->tracker->getStatus($jobId);

        $this->assertEquals($expectedData, $result);
    }

    public function test_get_status_returns_null_for_nonexistent_job()
    {
        $result = $this->tracker->getStatus('nonexistent_job');
        $this->assertNull($result);
    }

    public function test_update_progress_updates_existing_status()
    {
        $jobId = 'test_job_123';
        
        // First set initial status
        $this->tracker->updateStatus($jobId, 'processing', ['engine' => 'openai']);
        
        // Then update progress
        $this->tracker->updateProgress($jobId, 50, 'Halfway complete');

        $result = $this->tracker->getStatus($jobId);
        
        $this->assertEquals(50, $result['metadata']['progress_percentage']);
        $this->assertEquals('Halfway complete', $result['metadata']['progress_message']);
        $this->assertArrayHasKey('progress_updated_at', $result['metadata']);
    }

    public function test_get_multiple_statuses_returns_correct_data()
    {
        $jobIds = ['job_1', 'job_2', 'job_3'];
        
        // Set up test data
        foreach ($jobIds as $index => $jobId) {
            $this->tracker->updateStatus($jobId, 'processing', ['index' => $index]);
        }

        $results = $this->tracker->getMultipleStatuses($jobIds);

        $this->assertCount(3, $results);
        foreach ($jobIds as $index => $jobId) {
            $this->assertArrayHasKey($jobId, $results);
            $this->assertEquals('processing', $results[$jobId]['status']);
            $this->assertEquals($index, $results[$jobId]['metadata']['index']);
        }
    }

    public function test_is_completed_returns_true_for_completed_jobs()
    {
        $jobId = 'completed_job';
        $this->tracker->updateStatus($jobId, 'completed', []);

        $this->assertTrue($this->tracker->isCompleted($jobId));
    }

    public function test_is_completed_returns_true_for_failed_jobs()
    {
        $jobId = 'failed_job';
        $this->tracker->updateStatus($jobId, 'failed', []);

        $this->assertTrue($this->tracker->isCompleted($jobId));
    }

    public function test_is_completed_returns_false_for_running_jobs()
    {
        $jobId = 'running_job';
        $this->tracker->updateStatus($jobId, 'processing', []);

        $this->assertFalse($this->tracker->isCompleted($jobId));
    }

    public function test_is_running_returns_true_for_processing_jobs()
    {
        $jobId = 'processing_job';
        $this->tracker->updateStatus($jobId, 'processing', []);

        $this->assertTrue($this->tracker->isRunning($jobId));
    }

    public function test_is_running_returns_true_for_queued_jobs()
    {
        $jobId = 'queued_job';
        $this->tracker->updateStatus($jobId, 'queued', []);

        $this->assertTrue($this->tracker->isRunning($jobId));
    }

    public function test_is_running_returns_false_for_completed_jobs()
    {
        $jobId = 'completed_job';
        $this->tracker->updateStatus($jobId, 'completed', []);

        $this->assertFalse($this->tracker->isRunning($jobId));
    }

    public function test_get_progress_returns_correct_percentage()
    {
        $jobId = 'progress_job';
        $this->tracker->updateStatus($jobId, 'processing', []);
        $this->tracker->updateProgress($jobId, 75, 'Almost done');

        $progress = $this->tracker->getProgress($jobId);

        $this->assertEquals(75, $progress);
    }

    public function test_get_progress_returns_zero_for_job_without_progress()
    {
        $jobId = 'no_progress_job';
        $this->tracker->updateStatus($jobId, 'processing', []);

        $progress = $this->tracker->getProgress($jobId);

        $this->assertEquals(0, $progress);
    }

    public function test_get_statistics_returns_default_when_no_table()
    {
        // Mock Schema to return false for table existence
        Schema::shouldReceive('hasTable')
            ->with('ai_job_statuses')
            ->andReturn(false);

        $stats = $this->tracker->getStatistics();

        $expected = [
            'total_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'processing_jobs' => 0,
            'success_rate' => 0,
        ];

        $this->assertEquals($expected, $stats);
    }

    public function test_cleanup_returns_zero_when_no_table()
    {
        $cleaned = $this->tracker->cleanup();
        $this->assertEquals(0, $cleaned);
    }

    public function test_progress_percentage_is_clamped()
    {
        $jobId = 'clamp_test_job';
        $this->tracker->updateStatus($jobId, 'processing', []);
        
        // Test negative value
        $this->tracker->updateProgress($jobId, -10, 'Negative test');
        $this->assertEquals(0, $this->tracker->getProgress($jobId));
        
        // Test over 100
        $this->tracker->updateProgress($jobId, 150, 'Over 100 test');
        $this->assertEquals(100, $this->tracker->getProgress($jobId));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();
        parent::tearDown();
    }
}
