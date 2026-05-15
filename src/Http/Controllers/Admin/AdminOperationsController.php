<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use LaravelAIEngine\Http\Requests\AdminProviderToolApprovalActionRequest;
use LaravelAIEngine\Http\Requests\AdminProviderToolRunActionRequest;
use LaravelAIEngine\Http\Requests\AdminAgentRunActionRequest;
use LaravelAIEngine\Jobs\ContinueProviderToolRunJob;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AIPromptPolicyVersionRepository;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Repositories\ProviderToolArtifactRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\Admin\NodeAdminService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Services\Node\NodeBulkSyncService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class AdminOperationsController extends Controller
{
    public function providerTools(
        ProviderToolRunRepository $runs,
        ProviderToolApprovalRepository $approvals,
        ProviderToolArtifactRepository $artifacts
    ): View {
        $tableExists = $this->providerToolTablesAvailable();

        return view('ai-engine::admin.provider-tools', [
            'table_exists' => $tableExists,
            'runs' => $tableExists ? $runs->paginate(['status' => request('status')], 10) : null,
            'approvals' => $tableExists ? $approvals->paginate(['status' => request('approval_status', 'pending')], 10) : null,
            'artifacts' => $tableExists ? $artifacts->paginate([], 10) : null,
            'api_base' => url('/api/v1/ai/provider-tools'),
        ]);
    }

    public function agentRuns(AgentRunRepository $runs): View
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

    public function agentRunDetail(string $run, AgentRunRepository $runs): View
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

    public function resumeAgentRun(
        string $run,
        AdminAgentRunActionRequest $request,
        AgentRunRuntimeControlService $control
    ): RedirectResponse {
        if (!Schema::hasTable('ai_agent_runs')) {
            return back()->withErrors(['agent_runs' => 'Agent run tables are missing. Run package migrations first.']);
        }

        try {
            $payload = array_merge($request->validated(), [
                'queue' => $request->boolean('queue', true),
            ]);
            $control->resume($run, $payload);

            return back()->with('status', 'Agent run resume queued.');
        } catch (\Throwable $e) {
            return back()->withErrors(['agent_runs' => $e->getMessage()]);
        }
    }

    public function retryAgentRun(
        string $run,
        AdminAgentRunActionRequest $request,
        AgentRunRuntimeControlService $control
    ): RedirectResponse {
        if (!Schema::hasTable('ai_agent_runs')) {
            return back()->withErrors(['agent_runs' => 'Agent run tables are missing. Run package migrations first.']);
        }

        try {
            $payload = array_merge($request->validated(), [
                'message' => $request->input('message') ?: 'retry failed agent run',
                'reason' => $request->input('reason') ?: 'Admin retry requested.',
                'queue' => $request->boolean('queue', true),
            ]);
            $control->resume($run, $payload);

            return back()->with('status', 'Agent run retry queued.');
        } catch (\Throwable $e) {
            return back()->withErrors(['agent_runs' => $e->getMessage()]);
        }
    }

    public function cancelAgentRun(
        string $run,
        AdminAgentRunActionRequest $request,
        AgentRunRuntimeControlService $control
    ): RedirectResponse {
        if (!Schema::hasTable('ai_agent_runs')) {
            return back()->withErrors(['agent_runs' => 'Agent run tables are missing. Run package migrations first.']);
        }

        try {
            $control->cancel($run, $request->validated());

            return back()->with('status', 'Agent run cancelled.');
        } catch (\Throwable $e) {
            return back()->withErrors(['agent_runs' => $e->getMessage()]);
        }
    }

    public function approveProviderTool(
        AdminProviderToolApprovalActionRequest $request,
        ProviderToolApprovalService $approvals,
        ProviderToolContinuationService $continuations,
        JobStatusTracker $jobs
    ): RedirectResponse {
        if (!$this->providerToolTablesAvailable()) {
            return back()->withErrors(['provider_tools' => 'Provider tool tables are missing. Run package migrations first.']);
        }

        $validated = $request->validated();

        try {
            $approval = $approvals->approve(
                (string) $validated['approval_key'],
                $this->providerToolActorId($validated),
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

    public function rejectProviderTool(
        AdminProviderToolApprovalActionRequest $request,
        ProviderToolApprovalService $approvals
    ): RedirectResponse {
        if (!$this->providerToolTablesAvailable()) {
            return back()->withErrors(['provider_tools' => 'Provider tool tables are missing. Run package migrations first.']);
        }

        $validated = $request->validated();

        try {
            $approvals->reject(
                (string) $validated['approval_key'],
                $this->providerToolActorId($validated),
                $validated['reason'] ?? null,
                ['source' => 'admin_ui']
            );

            return back()->with('status', 'Rejected provider tool request.');
        } catch (\Throwable $e) {
            return back()->withErrors(['provider_tools' => $e->getMessage()]);
        }
    }

    public function continueProviderToolRun(
        AdminProviderToolRunActionRequest $request,
        ProviderToolContinuationService $continuations,
        JobStatusTracker $jobs
    ): RedirectResponse {
        if (!$this->providerToolTablesAvailable()) {
            return back()->withErrors(['provider_tools' => 'Provider tool tables are missing. Run package migrations first.']);
        }

        $validated = $request->validated();

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

    public function nodes(NodeRegistryService $registry, NodeAdminService $nodeAdmin): View
    {
        $tableExists = Schema::hasTable('ai_nodes');

        $stats = $tableExists
            ? $registry->getStatistics()
            : [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'error' => 0,
                'healthy' => 0,
                'by_type' => [],
                'avg_response_time' => null,
            ];

        $nodes = $tableExists
            ? $nodeAdmin->recentNodes()
            : collect();

        return view('ai-engine::admin.nodes', [
            'table_exists' => $tableExists,
            'stats' => $stats,
            'nodes' => $nodes,
            'default_capabilities' => config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag']),
            'is_master_node' => $this->isMasterNode(),
            'default_autofix_strict' => $this->defaultAutofixStrict(),
        ]);
    }

    public function registerNode(Request $request, NodeRegistryService $registry): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', Rule::unique('ai_nodes', 'slug')],
            'type' => 'required|string|in:master,child',
            'url' => 'required|url|max:2048',
            'description' => 'nullable|string|max:1000',
            'capabilities' => 'nullable|string|max:2000',
            'weight' => 'nullable|integer|min:1|max:1000',
            'status' => 'nullable|string|in:active,inactive,error',
            'api_key' => 'nullable|string|max:255',
        ]);

        $node = $registry->register([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'type' => $validated['type'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'capabilities' => $this->parseCsvList(
                $validated['capabilities'] ?? null,
                (array) config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag'])
            ),
            'weight' => (int) ($validated['weight'] ?? 1),
            'api_key' => $this->normalizeNullableString($validated['api_key'] ?? null),
        ]);

        $postCreateStatus = (string) ($validated['status'] ?? '');
        if ($postCreateStatus !== '' && $postCreateStatus !== 'active') {
            $registry->updateStatus($node, $postCreateStatus);
            $node->refresh();
        }

        return back()->with('status', 'Node `' . $node->slug . '` registered.');
    }

    public function updateNode(Request $request, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $nodeId = (int) $request->input('node_id');
        $node = $nodeAdmin->findNode($nodeId);

        if (!$node) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        $validated = $request->validate([
            'node_id' => 'required|integer|min:1',
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('ai_nodes', 'slug')->ignore($node->id),
            ],
            'type' => 'required|string|in:master,child',
            'url' => 'required|url|max:2048',
            'description' => 'nullable|string|max:1000',
            'capabilities' => 'nullable|string|max:2000',
            'weight' => 'nullable|integer|min:1|max:1000',
            'api_key' => 'nullable|string|max:255',
            'status' => 'required|string|in:active,inactive,error',
        ]);

        $node = $nodeAdmin->updateNode($nodeId, $validated);

        return back()->with('status', 'Node `' . $node->slug . '` updated.');
    }

    public function updateNodeStatus(Request $request, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $validated = $request->validate([
            'node_id' => 'required|integer|min:1',
            'status' => 'required|string|in:active,inactive,error',
        ]);

        $node = $nodeAdmin->setStatus((int) $validated['node_id'], (string) $validated['status']);
        if (!$node) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        return back()->with('status', 'Node `' . $node->slug . '` status set to `' . $validated['status'] . '`.');
    }

    public function pingNode(Request $request, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $validated = $request->validate([
            'node_id' => 'required|integer|min:1',
        ]);

        $result = $nodeAdmin->ping((int) $validated['node_id']);
        if (!$result) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        $node = $result['node'];
        $success = (bool) $result['success'];

        return back()->with('status', $success
            ? ('Ping succeeded for `' . $node->slug . '`.')
            : ('Ping failed for `' . $node->slug . '`.')
        );
    }

    public function pingAllNodes(NodeRegistryService $registry): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $results = $registry->pingAll();
        $successCount = count(array_filter($results, static fn (array $item): bool => (bool) ($item['success'] ?? false)));
        $total = count($results);

        return back()->with('status', 'Pinged ' . $total . ' node(s): ' . $successCount . ' healthy, ' . ($total - $successCount) . ' failed.');
    }

    public function deleteNode(Request $request, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $validated = $request->validate([
            'node_id' => 'required|integer|min:1',
        ]);

        $slug = $nodeAdmin->delete((int) $validated['node_id']);
        if ($slug === null) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        return back()->with('status', 'Node `' . $slug . '` removed.');
    }

    public function bulkSyncTemplate(NodeBulkSyncService $bulkSync): JsonResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return response()->json([
                'error' => '`ai_nodes` table is missing. Run package migrations first.',
            ], 422);
        }

        if (!$this->isMasterNode()) {
            return response()->json([
                'error' => 'Bulk sync is only available on master node apps.',
            ], 403);
        }

        return response()->json(
            $bulkSync->templatePayload(),
            200,
            ['Content-Disposition' => 'attachment; filename="ai-engine-nodes-template.json"']
        );
    }

    public function bulkSyncExport(NodeBulkSyncService $bulkSync): JsonResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return response()->json([
                'error' => '`ai_nodes` table is missing. Run package migrations first.',
            ], 422);
        }

        if (!$this->isMasterNode()) {
            return response()->json([
                'error' => 'Bulk sync is only available on master node apps.',
            ], 403);
        }

        return response()->json(
            $bulkSync->exportCurrentNodesPayload(),
            200,
            ['Content-Disposition' => 'attachment; filename="ai-engine-nodes-export.json"']
        );
    }

    public function previewBulkSync(Request $request, NodeBulkSyncService $bulkSync): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        if (!$this->isMasterNode()) {
            return back()->withErrors(['nodes' => 'Bulk sync is only available on master node apps.']);
        }

        $validated = $request->validate([
            'payload' => 'nullable|string|max:500000',
            'payload_file' => 'nullable|file|mimes:json,txt|max:1024',
        ]);

        $payload = $this->resolveBulkSyncPayload($request, $validated);
        if ($payload === null) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Provide JSON payload text or upload a JSON file.']);
        }

        try {
            $normalized = $bulkSync->normalizeDefinitionsWithDiagnostics(
                $bulkSync->loadDefinitionsFromJsonPayload($payload)
            );
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Failed to parse JSON payload: ' . $e->getMessage()]);
        }

        $definitions = (array) ($normalized['definitions'] ?? []);
        $invalid = (array) ($normalized['invalid'] ?? []);

        if ($definitions === [] && $invalid === []) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'No valid node definitions were found in payload.']);
        }

        $plan = $bulkSync->buildPlan($definitions);
        $plan['invalid'] = $invalid;
        $preview = $this->buildBulkSyncPreviewPayload($bulkSync, $plan);
        $status = ($definitions === [] && $invalid !== [])
            ? 'Bulk sync dry-run found invalid rows only. Fix issues below before applying.'
            : 'Bulk sync dry-run prepared. Review the plan below before applying.';

        return back()
            ->withInput()
            ->with('status', $status)
            ->with('bulk_sync_preview', $preview);
    }

    public function autoFixBulkSync(Request $request, NodeBulkSyncService $bulkSync): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        if (!$this->isMasterNode()) {
            return back()->withErrors(['nodes' => 'Bulk sync is only available on master node apps.']);
        }

        $validated = $request->validate([
            'payload' => 'nullable|string|max:500000',
            'payload_file' => 'nullable|file|mimes:json,txt|max:1024',
            'autofix_strict' => 'nullable|boolean',
            'prune' => 'nullable|boolean',
            'ping' => 'nullable|boolean',
        ]);

        $payload = $this->resolveBulkSyncPayload($request, $validated);
        if ($payload === null) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Provide JSON payload text or upload a JSON file.']);
        }

        try {
            $raw = $bulkSync->loadDefinitionsFromJsonPayload($payload);
            $strict = $this->resolveAutofixStrict($request, $validated);
            $fixed = $bulkSync->autoFixPayload($raw, $strict);
            $normalized = $bulkSync->normalizeDefinitionsWithDiagnostics((array) ($fixed['payload'] ?? []));
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Failed to auto-fix payload: ' . $e->getMessage()]);
        }

        $definitions = (array) ($normalized['definitions'] ?? []);
        $invalid = (array) ($normalized['invalid'] ?? []);
        $plan = $bulkSync->buildPlan($definitions);
        $plan['invalid'] = $invalid;

        $fixedPayload = json_encode($fixed['payload'] ?? ['nodes' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($fixedPayload)) {
            $fixedPayload = '{}';
        }

        $status = 'Auto-fix (' . (string) ($fixed['mode'] ?? 'smart') . ') applied with ' . count((array) ($fixed['changes'] ?? [])) . ' change(s).';
        if (!empty($invalid)) {
            $status .= ' Some rows are still invalid; review issues below.';
        }

        return back()
            ->withInput([
                'payload' => $fixedPayload,
                'autofix_strict' => $strict,
                'prune' => $request->boolean('prune'),
                'ping' => $request->boolean('ping'),
            ])
            ->with('status', $status)
            ->with('bulk_sync_preview', $this->buildBulkSyncPreviewPayload($bulkSync, $plan))
            ->with('bulk_sync_autofix', [
                'mode' => (string) ($fixed['mode'] ?? 'smart'),
                'total_changes' => count((array) ($fixed['changes'] ?? [])),
                'changes' => array_slice((array) ($fixed['changes'] ?? []), 0, 50),
            ]);
    }

    public function autoFixBulkSyncDownload(Request $request, NodeBulkSyncService $bulkSync): JsonResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return response()->json([
                'error' => '`ai_nodes` table is missing. Run package migrations first.',
            ], 422);
        }

        if (!$this->isMasterNode()) {
            return response()->json([
                'error' => 'Bulk sync is only available on master node apps.',
            ], 403);
        }

        $validated = $request->validate([
            'payload' => 'nullable|string|max:500000',
            'payload_file' => 'nullable|file|mimes:json,txt|max:1024',
            'autofix_strict' => 'nullable|boolean',
        ]);

        $payload = $this->resolveBulkSyncPayload($request, $validated);
        if ($payload === null) {
            return response()->json([
                'error' => 'Provide JSON payload text or upload a JSON file.',
            ], 422);
        }

        try {
            $raw = $bulkSync->loadDefinitionsFromJsonPayload($payload);
            $strict = $this->resolveAutofixStrict($request, $validated);
            $fixed = $bulkSync->autoFixPayload($raw, $strict);
            $fixedPayload = (array) ($fixed['payload'] ?? ['nodes' => []]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to auto-fix payload: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json(
            $fixedPayload,
            200,
            [
                'Content-Disposition' => 'attachment; filename="ai-engine-nodes-autofixed.json"',
                'X-AI-Engine-Autofix-Changes' => (string) count((array) ($fixed['changes'] ?? [])),
                'X-AI-Engine-Autofix-Mode' => (string) ($fixed['mode'] ?? 'smart'),
            ]
        );
    }

    public function applyBulkSync(
        Request $request,
        NodeBulkSyncService $bulkSync,
        NodeRegistryService $registry
    ): RedirectResponse {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        if (!$this->isMasterNode()) {
            return back()->withErrors(['nodes' => 'Bulk sync is only available on master node apps.']);
        }

        $validated = $request->validate([
            'payload' => 'nullable|string|max:500000',
            'payload_file' => 'nullable|file|mimes:json,txt|max:1024',
            'prune' => 'nullable|boolean',
            'ping' => 'nullable|boolean',
        ]);

        $payload = $this->resolveBulkSyncPayload($request, $validated);
        if ($payload === null) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Provide JSON payload text or upload a JSON file.']);
        }

        try {
            $normalized = $bulkSync->normalizeDefinitionsWithDiagnostics(
                $bulkSync->loadDefinitionsFromJsonPayload($payload)
            );
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Failed to parse JSON payload: ' . $e->getMessage()]);
        }

        $definitions = (array) ($normalized['definitions'] ?? []);
        $invalid = (array) ($normalized['invalid'] ?? []);

        if ($definitions === [] && $invalid === []) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'No valid node definitions were found in payload.']);
        }

        $plan = $bulkSync->buildPlan($definitions);
        $plan['invalid'] = $invalid;

        if ($invalid !== []) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Bulk sync payload contains invalid rows. Run dry-run and fix invalid rows first.'])
                ->with('bulk_sync_preview', $this->buildBulkSyncPreviewPayload($bulkSync, $plan));
        }

        $applied = $bulkSync->applyPlan($plan, (bool) ($validated['prune'] ?? false));
        $pingResults = [];

        if ((bool) ($validated['ping'] ?? false)) {
            $pingResults = $bulkSync->pingTouchedNodes((array) ($applied['touched_slugs'] ?? []), $registry);
        }

        return back()
            ->withInput()
            ->with('status', 'Bulk sync applied: created ' . ($applied['created'] ?? 0) . ', updated ' . ($applied['updated'] ?? 0) . ', deactivated ' . ($applied['deactivated'] ?? 0) . '.')
            ->with('bulk_sync_applied', [
                'summary' => $applied,
                'ping' => $pingResults,
            ]);
    }

    public function health(InfrastructureHealthService $healthService): View
    {
        $report = $healthService->evaluate();

        return view('ai-engine::admin.health', [
            'report' => $report,
        ]);
    }

    public function policies(
        RAGPromptPolicyService $policyService,
        AIPromptPolicyVersionRepository $policyVersions
    ): View
    {
        $storeAvailable = $policyService->storeAvailable();
        $policies = $storeAvailable
            ? $policyVersions->recent()
            : collect();

        return view('ai-engine::admin.policies', [
            'store_available' => $storeAvailable,
            'default_policy_key' => config('ai-engine.rag.decision.policy_store.default_key', 'decision'),
            'policies' => $policies,
        ]);
    }

    public function createPolicy(
        Request $request,
        RAGPromptPolicyService $policyService,
        RAGDecisionPolicy $policyConfig
    ): RedirectResponse {
        $validated = $request->validate([
            'policy_key' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
            'template' => 'required|string|min:10',
            'status' => 'required|string|in:draft,active,canary,shadow',
            'rollout_percentage' => 'nullable|integer|min:0|max:100',
            'tenant_id' => 'nullable|string|max:120',
            'app_id' => 'nullable|string|max:120',
            'domain' => 'nullable|string|max:120',
            'locale' => 'nullable|string|max:40',
        ]);

        if (!$policyService->storeAvailable()) {
            return back()->withErrors(['policy' => 'Policy store is unavailable.']);
        }

        $targetContext = array_filter([
            'tenant_id' => trim((string) ($validated['tenant_id'] ?? '')),
            'app_id' => trim((string) ($validated['app_id'] ?? '')),
            'domain' => trim((string) ($validated['domain'] ?? '')),
            'locale' => trim((string) ($validated['locale'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $created = $policyService->createVersion((string) $validated['template'], [
            'policy_key' => trim((string) ($validated['policy_key'] ?? '')) ?: $policyConfig->decisionPolicyDefaultKey(),
            'name' => trim((string) ($validated['name'] ?? '')) ?: null,
            'status' => (string) $validated['status'],
            'rollout_percentage' => (int) ($validated['rollout_percentage'] ?? 0),
            'target_context' => $targetContext,
            'metadata' => ['created_via' => 'admin_ui'],
        ]);

        if (!$created) {
            return back()->withErrors(['policy' => 'Failed to create policy version.'])->withInput();
        }

        return back()->with('status', 'Created policy #' . $created->id . ' v' . $created->version . ' (' . $created->status . ').');
    }

    public function activatePolicy(Request $request, RAGPromptPolicyService $policyService): RedirectResponse
    {
        $validated = $request->validate([
            'policy_id' => 'required|integer|min:1',
            'status' => 'required|string|in:active,canary,shadow',
        ]);

        if (!$policyService->storeAvailable()) {
            return back()->withErrors(['policy' => 'Policy store is unavailable.']);
        }

        $activated = $policyService->activate((int) $validated['policy_id'], (string) $validated['status']);

        if (!$activated) {
            return back()->withErrors(['policy' => 'Failed to activate policy.']);
        }

        return back()->with('status', 'Policy #' . $activated->id . ' activated as ' . $activated->status . '.');
    }

    protected function parseCsvList(?string $value, array $default = []): array
    {
        if (!is_string($value) || trim($value) === '') {
            return array_values(array_unique(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                $default
            ))));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ))));
    }

    protected function normalizeNullableString(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function isMasterNode(): bool
    {
        return (bool) config('ai-engine.nodes.is_master', true);
    }

    protected function providerToolTablesAvailable(): bool
    {
        return Schema::hasTable('ai_provider_tool_runs')
            && Schema::hasTable('ai_provider_tool_approvals')
            && Schema::hasTable('ai_provider_tool_artifacts');
    }

    protected function providerToolActorId(array $validated): ?string
    {
        if (isset($validated['actor_id']) && trim((string) $validated['actor_id']) !== '') {
            return trim((string) $validated['actor_id']);
        }

        return auth()->id() !== null ? (string) auth()->id() : null;
    }

    protected function resolveBulkSyncPayload(Request $request, array $validated): ?string
    {
        $payload = trim((string) ($validated['payload'] ?? ''));
        if ($payload !== '') {
            return $payload;
        }

        if ($request->hasFile('payload_file')) {
            $uploaded = $request->file('payload_file');
            if ($uploaded && $uploaded->isValid()) {
                $content = file_get_contents($uploaded->getRealPath());
                if (is_string($content) && trim($content) !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    protected function buildBulkSyncPreviewPayload(NodeBulkSyncService $bulkSync, array $plan): array
    {
        return [
            'summary' => $bulkSync->summarizePlan($plan, 'admin_payload'),
            'create_slugs' => array_values(array_map(
                static fn (array $row): string => (string) ($row['slug'] ?? ''),
                (array) ($plan['create'] ?? [])
            )),
            'update_rows' => array_values(array_map(
                static fn (array $row): array => [
                    'slug' => (string) ($row['slug'] ?? ''),
                    'fields' => array_keys((array) ($row['changes'] ?? [])),
                ],
                (array) ($plan['update'] ?? [])
            )),
            'unchanged_slugs' => array_values(array_map(
                static fn (array $row): string => (string) ($row['slug'] ?? ''),
                (array) ($plan['unchanged'] ?? [])
            )),
            'invalid_rows' => array_values(array_map(
                static fn (array $row): array => [
                    'row' => (string) ($row['row'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'reason' => (string) ($row['reason'] ?? 'Unknown'),
                    'suggestion' => (string) ($row['suggestion'] ?? ''),
                ],
                (array) ($plan['invalid'] ?? [])
            )),
        ];
    }

    protected function defaultAutofixStrict(): bool
    {
        return strtolower((string) config('ai-engine.nodes.bulk_sync.autofix_mode', 'smart')) === 'strict';
    }

    protected function resolveAutofixStrict(Request $request, array $validated): bool
    {
        if ($request->has('autofix_strict') || array_key_exists('autofix_strict', $validated)) {
            return (bool) ($validated['autofix_strict'] ?? false);
        }

        return $this->defaultAutofixStrict();
    }
}
