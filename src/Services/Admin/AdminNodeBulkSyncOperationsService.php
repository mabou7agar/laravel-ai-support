<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Services\Node\NodeBulkSyncService;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class AdminNodeBulkSyncOperationsService
{
    public function __construct(
        private readonly AdminNodeOperationsService $nodes
    ) {}

    public function template(NodeBulkSyncService $bulkSync): JsonResponse
    {
        if ($error = $this->jsonUnavailableError()) {
            return $error;
        }

        return response()->json(
            $bulkSync->templatePayload(),
            200,
            ['Content-Disposition' => 'attachment; filename="ai-engine-nodes-template.json"']
        );
    }

    public function export(NodeBulkSyncService $bulkSync): JsonResponse
    {
        if ($error = $this->jsonUnavailableError()) {
            return $error;
        }

        return response()->json(
            $bulkSync->exportCurrentNodesPayload(),
            200,
            ['Content-Disposition' => 'attachment; filename="ai-engine-nodes-export.json"']
        );
    }

    public function preview(Request $request, array $validated, NodeBulkSyncService $bulkSync): RedirectResponse
    {
        if ($error = $this->redirectUnavailableError()) {
            return $error;
        }

        $payload = $this->resolvePayload($request, $validated);
        if ($payload === null) {
            return back()->withInput()->withErrors(['nodes' => 'Provide JSON payload text or upload a JSON file.']);
        }

        try {
            $normalized = $bulkSync->normalizeDefinitionsWithDiagnostics(
                $bulkSync->loadDefinitionsFromJsonPayload($payload)
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['nodes' => 'Failed to parse JSON payload: ' . $e->getMessage()]);
        }

        $definitions = (array) ($normalized['definitions'] ?? []);
        $invalid = (array) ($normalized['invalid'] ?? []);
        if ($definitions === [] && $invalid === []) {
            return back()->withInput()->withErrors(['nodes' => 'No valid node definitions were found in payload.']);
        }

        $plan = $bulkSync->buildPlan($definitions);
        $plan['invalid'] = $invalid;
        $status = ($definitions === [] && $invalid !== [])
            ? 'Bulk sync dry-run found invalid rows only. Fix issues below before applying.'
            : 'Bulk sync dry-run prepared. Review the plan below before applying.';

        return back()
            ->withInput()
            ->with('status', $status)
            ->with('bulk_sync_preview', $this->previewPayload($bulkSync, $plan));
    }

    public function autoFix(Request $request, array $validated, NodeBulkSyncService $bulkSync): RedirectResponse
    {
        if ($error = $this->redirectUnavailableError()) {
            return $error;
        }

        $payload = $this->resolvePayload($request, $validated);
        if ($payload === null) {
            return back()->withInput()->withErrors(['nodes' => 'Provide JSON payload text or upload a JSON file.']);
        }

        try {
            $strict = $this->resolveAutofixStrict($request, $validated);
            $fixed = $bulkSync->autoFixPayload($bulkSync->loadDefinitionsFromJsonPayload($payload), $strict);
            $normalized = $bulkSync->normalizeDefinitionsWithDiagnostics((array) ($fixed['payload'] ?? []));
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['nodes' => 'Failed to auto-fix payload: ' . $e->getMessage()]);
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
            ->with('bulk_sync_preview', $this->previewPayload($bulkSync, $plan))
            ->with('bulk_sync_autofix', [
                'mode' => (string) ($fixed['mode'] ?? 'smart'),
                'total_changes' => count((array) ($fixed['changes'] ?? [])),
                'changes' => array_slice((array) ($fixed['changes'] ?? []), 0, 50),
            ]);
    }

    public function autoFixDownload(Request $request, array $validated, NodeBulkSyncService $bulkSync): JsonResponse
    {
        if ($error = $this->jsonUnavailableError()) {
            return $error;
        }

        $payload = $this->resolvePayload($request, $validated);
        if ($payload === null) {
            return response()->json(['error' => 'Provide JSON payload text or upload a JSON file.'], 422);
        }

        try {
            $strict = $this->resolveAutofixStrict($request, $validated);
            $fixed = $bulkSync->autoFixPayload($bulkSync->loadDefinitionsFromJsonPayload($payload), $strict);
            $fixedPayload = (array) ($fixed['payload'] ?? ['nodes' => []]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to auto-fix payload: ' . $e->getMessage()], 422);
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

    public function apply(
        Request $request,
        array $validated,
        NodeBulkSyncService $bulkSync,
        NodeRegistryService $registry
    ): RedirectResponse {
        if ($error = $this->redirectUnavailableError()) {
            return $error;
        }

        $payload = $this->resolvePayload($request, $validated);
        if ($payload === null) {
            return back()->withInput()->withErrors(['nodes' => 'Provide JSON payload text or upload a JSON file.']);
        }

        try {
            $normalized = $bulkSync->normalizeDefinitionsWithDiagnostics(
                $bulkSync->loadDefinitionsFromJsonPayload($payload)
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['nodes' => 'Failed to parse JSON payload: ' . $e->getMessage()]);
        }

        $definitions = (array) ($normalized['definitions'] ?? []);
        $invalid = (array) ($normalized['invalid'] ?? []);
        if ($definitions === [] && $invalid === []) {
            return back()->withInput()->withErrors(['nodes' => 'No valid node definitions were found in payload.']);
        }

        $plan = $bulkSync->buildPlan($definitions);
        $plan['invalid'] = $invalid;
        if ($invalid !== []) {
            return back()
                ->withInput()
                ->withErrors(['nodes' => 'Bulk sync payload contains invalid rows. Run dry-run and fix invalid rows first.'])
                ->with('bulk_sync_preview', $this->previewPayload($bulkSync, $plan));
        }

        $applied = $bulkSync->applyPlan($plan, (bool) ($validated['prune'] ?? false));
        $pingResults = (bool) ($validated['ping'] ?? false)
            ? $bulkSync->pingTouchedNodes((array) ($applied['touched_slugs'] ?? []), $registry)
            : [];

        return back()
            ->withInput()
            ->with('status', 'Bulk sync applied: created ' . ($applied['created'] ?? 0) . ', updated ' . ($applied['updated'] ?? 0) . ', deactivated ' . ($applied['deactivated'] ?? 0) . '.')
            ->with('bulk_sync_applied', [
                'summary' => $applied,
                'ping' => $pingResults,
            ]);
    }

    private function redirectUnavailableError(): ?RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        if (!$this->nodes->isMasterNode()) {
            return back()->withErrors(['nodes' => 'Bulk sync is only available on master node apps.']);
        }

        return null;
    }

    private function jsonUnavailableError(): ?JsonResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return response()->json(['error' => '`ai_nodes` table is missing. Run package migrations first.'], 422);
        }

        if (!$this->nodes->isMasterNode()) {
            return response()->json(['error' => 'Bulk sync is only available on master node apps.'], 403);
        }

        return null;
    }

    private function resolvePayload(Request $request, array $validated): ?string
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

    private function previewPayload(NodeBulkSyncService $bulkSync, array $plan): array
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

    private function resolveAutofixStrict(Request $request, array $validated): bool
    {
        if ($request->has('autofix_strict') || array_key_exists('autofix_strict', $validated)) {
            return (bool) ($validated['autofix_strict'] ?? false);
        }

        return $this->nodes->defaultAutofixStrict();
    }
}
