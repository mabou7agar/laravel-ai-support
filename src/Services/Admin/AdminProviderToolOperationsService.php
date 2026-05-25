<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use LaravelAIEngine\Jobs\ContinueProviderToolRunJob;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Repositories\ProviderToolArtifactRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService;

class AdminProviderToolOperationsService
{
    public function index(
        ProviderToolRunRepository $runs,
        ProviderToolApprovalRepository $approvals,
        ProviderToolArtifactRepository $artifacts
    ): View {
        $tableExists = $this->tablesAvailable();

        return view('ai-engine::admin.provider-tools', [
            'table_exists' => $tableExists,
            'runs' => $tableExists ? $runs->paginate(['status' => request('status')], 10) : null,
            'approvals' => $tableExists ? $approvals->paginate(['status' => request('approval_status', 'pending')], 10) : null,
            'artifacts' => $tableExists ? $artifacts->paginate([], 10) : null,
            'api_base' => url('/api/v1/ai/provider-tools'),
        ]);
    }

    public function approve(
        array $validated,
        ProviderToolApprovalService $approvals,
        ProviderToolContinuationService $continuations,
        JobStatusTracker $jobs
    ): RedirectResponse {
        if (!$this->tablesAvailable()) {
            return back()->withErrors(['provider_tools' => 'Provider tool tables are missing. Run package migrations first.']);
        }

        try {
            $approval = $approvals->approve(
                (string) $validated['approval_key'],
                $this->actorId($validated),
                $validated['reason'] ?? null,
                ['source' => 'admin_ui']
            );

            if ((bool) ($validated['continue'] ?? false)) {
                if ((bool) ($validated['async'] ?? true)) {
                    $jobId = (string) Str::uuid();
                    ContinueProviderToolRunJob::dispatch($jobId, $approval->tool_run_id, $validated['options'] ?? []);
                    $jobs->updateStatus($jobId, 'queued', [
                        'provider_tool_run_id' => $approval->tool_run_id,
                        'queued_at' => now()->toISOString(),
                        'source' => 'admin_ui',
                    ]);

                    return back()->with('status', 'Approved provider tool request and queued continuation job ' . $jobId . '.');
                }

                $continuations->continueRun($approval->tool_run_id, $validated['options'] ?? []);

                return back()->with('status', 'Approved provider tool request and continued run.');
            }

            return back()->with('status', 'Approved provider tool request.');
        } catch (\Throwable $e) {
            return back()->withErrors(['provider_tools' => $e->getMessage()]);
        }
    }

    public function reject(array $validated, ProviderToolApprovalService $approvals): RedirectResponse
    {
        if (!$this->tablesAvailable()) {
            return back()->withErrors(['provider_tools' => 'Provider tool tables are missing. Run package migrations first.']);
        }

        try {
            $approvals->reject(
                (string) $validated['approval_key'],
                $this->actorId($validated),
                $validated['reason'] ?? null,
                ['source' => 'admin_ui']
            );

            return back()->with('status', 'Rejected provider tool request.');
        } catch (\Throwable $e) {
            return back()->withErrors(['provider_tools' => $e->getMessage()]);
        }
    }

    public function continueRun(
        array $validated,
        ProviderToolContinuationService $continuations,
        JobStatusTracker $jobs
    ): RedirectResponse {
        if (!$this->tablesAvailable()) {
            return back()->withErrors(['provider_tools' => 'Provider tool tables are missing. Run package migrations first.']);
        }

        try {
            if ((bool) ($validated['async'] ?? true)) {
                $jobId = (string) Str::uuid();
                ContinueProviderToolRunJob::dispatch($jobId, (string) $validated['run'], $validated['options'] ?? []);
                $jobs->updateStatus($jobId, 'queued', [
                    'provider_tool_run_id' => (string) $validated['run'],
                    'queued_at' => now()->toISOString(),
                    'source' => 'admin_ui',
                ]);

                return back()->with('status', 'Queued provider tool continuation job ' . $jobId . '.');
            }

            $continuations->continueRun((string) $validated['run'], $validated['options'] ?? []);

            return back()->with('status', 'Continued provider tool run.');
        } catch (\Throwable $e) {
            return back()->withErrors(['provider_tools' => $e->getMessage()]);
        }
    }

    private function tablesAvailable(): bool
    {
        return Schema::hasTable('ai_provider_tool_runs')
            && Schema::hasTable('ai_provider_tool_approvals')
            && Schema::hasTable('ai_provider_tool_artifacts');
    }

    private function actorId(array $validated): ?string
    {
        if (isset($validated['actor_id']) && trim((string) $validated['actor_id']) !== '') {
            return trim((string) $validated['actor_id']);
        }

        return auth()->id() !== null ? (string) auth()->id() : null;
    }
}
