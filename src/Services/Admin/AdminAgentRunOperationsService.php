<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;

class AdminAgentRunOperationsService
{
    public function index(AgentRunRepository $runs): View
    {
        $tableExists = Schema::hasTable('ai_agent_runs');

        return view('ai-engine::admin.agent-runs', [
            'table_exists' => $tableExists,
            'runs' => $tableExists ? $runs->paginate([
                'status' => request('status'),
                'session_id' => request('session_id'),
                'user_id' => request('user_id'),
                'tenant_id' => request('tenant_id'),
                'workspace_id' => request('workspace_id'),
            ], 15) : null,
        ]);
    }

    public function show(string $run, AgentRunRepository $runs): View
    {
        if (!Schema::hasTable('ai_agent_runs')) {
            return view('ai-engine::admin.agent-runs', [
                'table_exists' => false,
                'runs' => null,
            ]);
        }

        return view('ai-engine::admin.agent-run-detail', [
            'run' => $runs->findOrFail($run)->load([
                'steps.approvals',
                'steps.artifacts',
                'steps.auditEvents',
                'steps.linkedProviderToolRuns',
                'providerToolRuns.approvals',
                'providerToolRuns.artifacts',
            ]),
        ]);
    }

    public function resume(string $run, array $validated, AgentRunRuntimeControlService $control): RedirectResponse
    {
        if (!Schema::hasTable('ai_agent_runs')) {
            return back()->withErrors(['agent_runs' => 'Agent run tables are missing. Run package migrations first.']);
        }

        try {
            $control->resume($run, $validated);

            return back()->with('status', 'Agent run resume queued.');
        } catch (\Throwable $e) {
            return back()->withErrors(['agent_runs' => $e->getMessage()]);
        }
    }

    public function retry(string $run, array $validated, AgentRunRuntimeControlService $control): RedirectResponse
    {
        if (!Schema::hasTable('ai_agent_runs')) {
            return back()->withErrors(['agent_runs' => 'Agent run tables are missing. Run package migrations first.']);
        }

        try {
            $message = trim((string) ($validated['message'] ?? ''));
            $reason = trim((string) ($validated['reason'] ?? ''));
            $payload = array_merge($validated, [
                'message' => $message !== '' ? $message : 'retry failed agent run',
                'reason' => $reason !== '' ? $reason : 'Admin retry requested.',
            ]);
            $control->resume($run, $payload);

            return back()->with('status', 'Agent run retry queued.');
        } catch (\Throwable $e) {
            return back()->withErrors(['agent_runs' => $e->getMessage()]);
        }
    }

    public function cancel(string $run, array $validated, AgentRunRuntimeControlService $control): RedirectResponse
    {
        if (!Schema::hasTable('ai_agent_runs')) {
            return back()->withErrors(['agent_runs' => 'Agent run tables are missing. Run package migrations first.']);
        }

        try {
            $control->cancel($run, $validated);

            return back()->with('status', 'Agent run cancelled.');
        } catch (\Throwable $e) {
            return back()->withErrors(['agent_runs' => $e->getMessage()]);
        }
    }
}
