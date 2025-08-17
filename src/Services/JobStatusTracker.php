<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JobStatusTracker
{
    private string $cachePrefix = 'ai_job_status:';
    private int $cacheTtl = 3600; // 1 hour

    /**
     * Update job status with metadata
     */
    public function updateStatus(string $jobId, string $status, array $metadata = []): void
    {
        $data = [
            'job_id' => $jobId,
            'status' => $status,
            'updated_at' => now()->toISOString(),
            'metadata' => $metadata,
        ];

        // Store in cache for quick access
        Cache::put($this->cachePrefix . $jobId, $data, $this->cacheTtl);

        // Also store in database if table exists
        $this->storeInDatabase($jobId, $status, $metadata);
    }

    /**
     * Update job progress
     */
    public function updateProgress(string $jobId, int $percentage, string $message = ''): void
    {
        $existingData = $this->getStatus($jobId) ?? [];
        
        $progressData = [
            'progress_percentage' => max(0, min(100, $percentage)),
            'progress_message' => $message,
            'progress_updated_at' => now()->toISOString(),
        ];

        $metadata = array_merge($existingData['metadata'] ?? [], $progressData);
        
        $this->updateStatus($jobId, $existingData['status'] ?? 'processing', $metadata);
    }

    /**
     * Get job status
     */
    public function getStatus(string $jobId): ?array
    {
        // Try cache first
        $cached = Cache::get($this->cachePrefix . $jobId);
        if ($cached) {
            return $cached;
        }

        // Fallback to database
        return $this->getFromDatabase($jobId);
    }

    /**
     * Get multiple job statuses
     */
    public function getMultipleStatuses(array $jobIds): array
    {
        $results = [];
        $missingIds = [];

        // Get from cache first
        foreach ($jobIds as $jobId) {
            $cached = Cache::get($this->cachePrefix . $jobId);
            if ($cached) {
                $results[$jobId] = $cached;
            } else {
                $missingIds[] = $jobId;
            }
        }

        // Get missing ones from database
        if (!empty($missingIds)) {
            $dbResults = $this->getMultipleFromDatabase($missingIds);
            $results = array_merge($results, $dbResults);
        }

        return $results;
    }

    /**
     * Check if job is completed
     */
    public function isCompleted(string $jobId): bool
    {
        $status = $this->getStatus($jobId);
        return $status && in_array($status['status'], ['completed', 'failed']);
    }

    /**
     * Check if job is running
     */
    public function isRunning(string $jobId): bool
    {
        $status = $this->getStatus($jobId);
        return $status && in_array($status['status'], ['queued', 'processing']);
    }

    /**
     * Get job progress percentage
     */
    public function getProgress(string $jobId): int
    {
        $status = $this->getStatus($jobId);
        return $status['metadata']['progress_percentage'] ?? 0;
    }

    /**
     * Clean up old job statuses
     */
    public function cleanup(int $olderThanHours = 24): int
    {
        $cutoff = now()->subHours($olderThanHours);
        $cleaned = 0;

        // Clean from database if table exists
        if ($this->hasJobStatusTable()) {
            $cleaned = DB::table('ai_job_statuses')
                ->where('updated_at', '<', $cutoff)
                ->delete();
        }

        // Note: Cache entries will expire naturally based on TTL
        
        return $cleaned;
    }

    /**
     * Get job statistics
     */
    public function getStatistics(int $lastHours = 24): array
    {
        if (!$this->hasJobStatusTable()) {
            return [
                'total_jobs' => 0,
                'completed_jobs' => 0,
                'failed_jobs' => 0,
                'processing_jobs' => 0,
                'success_rate' => 0,
            ];
        }

        $since = now()->subHours($lastHours);
        
        $stats = DB::table('ai_job_statuses')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status = "processing" THEN 1 ELSE 0 END) as processing_jobs
            ')
            ->first();

        $totalJobs = $stats->total_jobs ?? 0;
        $completedJobs = $stats->completed_jobs ?? 0;
        
        return [
            'total_jobs' => $totalJobs,
            'completed_jobs' => $completedJobs,
            'failed_jobs' => $stats->failed_jobs ?? 0,
            'processing_jobs' => $stats->processing_jobs ?? 0,
            'success_rate' => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 2) : 0,
        ];
    }

    /**
     * Store job status in database if table exists
     */
    private function storeInDatabase(string $jobId, string $status, array $metadata): void
    {
        if (!$this->hasJobStatusTable()) {
            return;
        }

        try {
            DB::table('ai_job_statuses')->updateOrInsert(
                ['job_id' => $jobId],
                [
                    'status' => $status,
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                    'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                ]
            );
        } catch (\Exception $e) {
            // Log error but don't fail the job
            logger()->debug('Failed to store job status in database', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get job status from database
     */
    private function getFromDatabase(string $jobId): ?array
    {
        if (!$this->hasJobStatusTable()) {
            return null;
        }

        try {
            $record = DB::table('ai_job_statuses')
                ->where('job_id', $jobId)
                ->first();

            if (!$record) {
                return null;
            }

            return [
                'job_id' => $record->job_id,
                'status' => $record->status,
                'updated_at' => $record->updated_at,
                'metadata' => json_decode($record->metadata, true) ?? [],
            ];
        } catch (\Exception $e) {
            logger()->debug('Failed to get job status from database', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get multiple job statuses from database
     */
    private function getMultipleFromDatabase(array $jobIds): array
    {
        if (!$this->hasJobStatusTable() || empty($jobIds)) {
            return [];
        }

        try {
            $records = DB::table('ai_job_statuses')
                ->whereIn('job_id', $jobIds)
                ->get();

            $results = [];
            foreach ($records as $record) {
                $results[$record->job_id] = [
                    'job_id' => $record->job_id,
                    'status' => $record->status,
                    'updated_at' => $record->updated_at,
                    'metadata' => json_decode($record->metadata, true) ?? [],
                ];
            }

            return $results;
        } catch (\Exception $e) {
            logger()->debug('Failed to get multiple job statuses from database', [
                'job_ids' => $jobIds,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if job status table exists
     */
    private function hasJobStatusTable(): bool
    {
        static $hasTable = null;
        
        if ($hasTable === null) {
            try {
                $hasTable = DB::getSchemaBuilder()->hasTable('ai_job_statuses');
            } catch (\Exception $e) {
                $hasTable = false;
            }
        }
        
        return $hasTable;
    }
}
