<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use LaravelAIEngine\Repositories\AIPromptPolicyVersionRepository;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;

class AdminPolicyOperationsService
{
    public function index(
        RAGPromptPolicyService $policyService,
        AIPromptPolicyVersionRepository $policyVersions
    ): View {
        $storeAvailable = $policyService->storeAvailable();

        return view('ai-engine::admin.policies', [
            'store_available' => $storeAvailable,
            'default_policy_key' => config('ai-engine.rag.decision.policy_store.default_key', 'decision'),
            'policies' => $storeAvailable ? $policyVersions->recent() : collect(),
        ]);
    }

    public function create(
        array $validated,
        RAGPromptPolicyService $policyService,
        RAGDecisionPolicy $policyConfig
    ): RedirectResponse {
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

    public function activate(array $validated, RAGPromptPolicyService $policyService): RedirectResponse
    {
        if (!$policyService->storeAvailable()) {
            return back()->withErrors(['policy' => 'Policy store is unavailable.']);
        }

        $activated = $policyService->activate((int) $validated['policy_id'], (string) $validated['status']);
        if (!$activated) {
            return back()->withErrors(['policy' => 'Failed to activate policy.']);
        }

        return back()->with('status', 'Policy #' . $activated->id . ' activated as ' . $activated->status . '.');
    }
}
