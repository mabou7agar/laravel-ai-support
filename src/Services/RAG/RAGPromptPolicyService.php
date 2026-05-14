<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AIPromptPolicyVersion;

class RAGPromptPolicyService
{
    protected ?bool $tableAvailable = null;

    public function __construct(protected ?RAGDecisionPolicy $policy = null)
    {
        $this->policy = $policy ?? new RAGDecisionPolicy();
    }

    public function createVersion(string $template, array $attributes = []): ?AIPromptPolicyVersion
    {
        if (!$this->storeAvailable()) {
            return null;
        }

        $policyKey = (string) ($attributes['policy_key'] ?? $this->policy->decisionPolicyDefaultKey());
        $targetContext = $this->normalizeTargetContext((array) ($attributes['target_context'] ?? []));
        $scopeKey = $this->scopeKey($targetContext);

        $nextVersion = (int) AIPromptPolicyVersion::query()
            ->policy($policyKey)
            ->max('version') + 1;

        $policyVersion = AIPromptPolicyVersion::query()->create([
            'policy_key' => $policyKey,
            'version' => max(1, $nextVersion),
            'status' => (string) ($attributes['status'] ?? 'draft'),
            'scope_key' => $scopeKey,
            'name' => $attributes['name'] ?? null,
            'template' => trim($template),
            'rules' => (array) ($attributes['rules'] ?? []),
            'target_context' => $targetContext,
            'rollout_percentage' => $this->normalizeRollout($attributes['rollout_percentage'] ?? 0),
            'metrics' => (array) ($attributes['metrics'] ?? []),
            'metadata' => (array) ($attributes['metadata'] ?? []),
            'promoted_from_id' => $attributes['promoted_from_id'] ?? null,
            'activated_at' => null,
            'archived_at' => null,
        ]);

        $status = strtolower((string) ($attributes['status'] ?? 'draft'));
        if (in_array($status, ['active', 'canary', 'shadow'], true)) {
            return $this->activate($policyVersion, $status);
        }

        return $policyVersion->fresh();
    }

    public function activate(int|AIPromptPolicyVersion $policyVersion, string $status = 'active'): ?AIPromptPolicyVersion
    {
        if (!$this->storeAvailable()) {
            return null;
        }

        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'canary', 'shadow'], true)) {
            $status = 'active';
        }

        $policyVersion = $policyVersion instanceof AIPromptPolicyVersion
            ? $policyVersion
            : AIPromptPolicyVersion::query()->find($policyVersion);

        if (!$policyVersion) {
            return null;
        }

        AIPromptPolicyVersion::query()
            ->where('policy_key', $policyVersion->policy_key)
            ->where('scope_key', $policyVersion->scope_key)
            ->where('status', $status)
            ->where('id', '!=', $policyVersion->id)
            ->update([
                'status' => 'archived',
                'archived_at' => now(),
            ]);

        $policyVersion->status = $status;
        $policyVersion->activated_at = now();
        $policyVersion->archived_at = null;
        $policyVersion->save();

        return $policyVersion->fresh();
    }

    public function ensureActiveDefault(string $template, ?string $policyKey = null): ?AIPromptPolicyVersion
    {
        if (!$this->storeAvailable() || !$this->policy->decisionPolicyAutoSeedEnabled()) {
            return null;
        }

        $policyKey = $policyKey ?: $this->policy->decisionPolicyDefaultKey();

        $existing = AIPromptPolicyVersion::query()
            ->policy($policyKey)
            ->where('scope_key', 'global')
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createVersion($template, [
            'policy_key' => $policyKey,
            'name' => 'Bootstrapped default policy',
            'status' => 'active',
            'target_context' => [],
            'metadata' => ['seeded' => true],
        ]);
    }

    public function resolveForRuntime(array $context, ?string $policyKey = null): array
    {
        $policyKey = $policyKey ?: $this->policy->decisionPolicyDefaultKey();
        $runtime = $this->runtimeContext($context);

        if (!$this->storeAvailable()) {
            return [
                'selected' => null,
                'active' => null,
                'canary' => null,
                'shadow' => null,
                'selection' => 'template_file',
            ];
        }

        $candidates = AIPromptPolicyVersion::query()
            ->policy($policyKey)
            ->whereIn('status', ['active', 'canary', 'shadow'])
            ->orderByDesc('version')
            ->get();

        $matched = $candidates
            ->filter(fn (AIPromptPolicyVersion $item) => $this->matchesContext((array) $item->target_context, $runtime))
            ->sortByDesc(fn (AIPromptPolicyVersion $item) => $this->specificityScore((array) $item->target_context, $runtime));

        $active = $matched->first(fn (AIPromptPolicyVersion $item) => $item->status === 'active');
        $canary = $matched->first(fn (AIPromptPolicyVersion $item) => $item->status === 'canary');
        $shadow = $matched->first(fn (AIPromptPolicyVersion $item) => $item->status === 'shadow');

        $selected = $active;
        $selection = $active ? 'active' : 'template_file';

        if ($canary && $this->shouldRouteCanary($canary, $runtime)) {
            $selected = $canary;
            $selection = 'canary';
        }

        return [
            'selected' => $selected,
            'active' => $active,
            'canary' => $canary,
            'shadow' => $shadow,
            'selection' => $selection,
        ];
    }

    public function storeAvailable(): bool
    {
        if (!$this->policy->decisionPolicyStoreEnabled()) {
            return false;
        }

        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            $this->tableAvailable = Schema::hasTable($this->policy->decisionPolicyTable());
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Decision policy store unavailable', [
                'error' => $e->getMessage(),
            ]);
            $this->tableAvailable = false;
        }

        return $this->tableAvailable;
    }

    public function normalizeTargetContext(array $targetContext): array
    {
        $target = Arr::only($targetContext, ['tenant_id', 'app_id', 'domain', 'locale']);

        foreach ($target as $key => $value) {
            if (is_string($value)) {
                $target[$key] = trim($value);
            }

            if (is_array($value)) {
                $target[$key] = array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value)));
            }
        }

        return array_filter($target, function ($value) {
            if (is_array($value)) {
                return !empty($value);
            }

            return $value !== null && $value !== '';
        });
    }

    public function scopeKey(array $targetContext): string
    {
        $normalized = $this->normalizeTargetContext($targetContext);
        if (empty($normalized)) {
            return 'global';
        }

        ksort($normalized);

        return substr(hash('sha256', json_encode($normalized)), 0, 16);
    }

    protected function runtimeContext(array $context): array
    {
        return [
            'session_id' => $this->toNullableString($context['session_id'] ?? null),
            'user_id' => $this->toNullableString($context['user_id'] ?? null),
            'tenant_id' => $this->toNullableString($context['tenant_id'] ?? null),
            'app_id' => $this->toNullableString($context['app_id'] ?? null),
            'domain' => $this->toNullableString($context['domain'] ?? null),
            'locale' => $this->toNullableString($context['locale'] ?? null),
        ];
    }

    protected function matchesContext(array $targetContext, array $runtime): bool
    {
        $targetContext = $this->normalizeTargetContext($targetContext);

        if (empty($targetContext)) {
            return true;
        }

        foreach ($targetContext as $key => $value) {
            $runtimeValue = $runtime[$key] ?? null;
            if (is_array($value)) {
                if (!in_array((string) $runtimeValue, $value, true)) {
                    return false;
                }

                continue;
            }

            if ((string) $runtimeValue !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    protected function specificityScore(array $targetContext, array $runtime): int
    {
        $targetContext = $this->normalizeTargetContext($targetContext);
        if (empty($targetContext)) {
            return 1;
        }

        $weights = [
            'tenant_id' => 400,
            'app_id' => 300,
            'domain' => 200,
            'locale' => 100,
        ];

        $score = 0;
        foreach ($targetContext as $key => $value) {
            if (!array_key_exists($key, $weights)) {
                continue;
            }

            $runtimeValue = $runtime[$key] ?? null;
            if (is_array($value) && in_array((string) $runtimeValue, $value, true)) {
                $score += $weights[$key];
            } elseif (!is_array($value) && (string) $runtimeValue === (string) $value) {
                $score += $weights[$key];
            }
        }

        return $score + count($targetContext);
    }

    protected function shouldRouteCanary(AIPromptPolicyVersion $canary, array $runtime): bool
    {
        $rollout = $this->normalizeRollout($canary->rollout_percentage);
        if ($rollout <= 0) {
            return false;
        }

        $seed = (string) ($runtime['session_id'] ?: $runtime['user_id'] ?: microtime(true));
        $bucket = (abs(crc32($seed)) % 100) + 1;

        return $bucket <= $rollout;
    }

    protected function normalizeRollout(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }

    protected function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
