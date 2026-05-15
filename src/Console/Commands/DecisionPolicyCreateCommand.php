<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;

class DecisionPolicyCreateCommand extends Command
{
    protected $signature = 'ai:decision-policy:create
                            {--policy= : Policy key (default: decision)}
                            {--name= : Human-readable policy name}
                            {--template-path= : Prompt template path}
                            {--status=draft : Initial status (draft|active|canary|shadow)}
                            {--rollout=0 : Canary rollout percentage (0-100)}
                            {--tenant= : Tenant scope}
                            {--app= : App scope}
                            {--domain= : Domain scope}
                            {--locale= : Locale scope}
                            {--json : Output JSON payload}';

    protected $description = 'Create a new versioned decision prompt policy';

    public function handle(
        RAGPromptPolicyService $policyService,
        RAGDecisionPolicy $policyConfig
    ): int {
        if (!$policyService->storeAvailable()) {
            $this->warn('Decision policy store is disabled or unavailable.');
            return self::SUCCESS;
        }

        $template = $this->loadTemplate();
        if ($template === null) {
            $this->error('Template not found. Provide --template-path or configure AI_ENGINE_RAG_DECISION_TEMPLATE_PATH.');
            return self::FAILURE;
        }

        $targetContext = array_filter([
            'tenant_id' => $this->option('tenant'),
            'app_id' => $this->option('app'),
            'domain' => $this->option('domain'),
            'locale' => $this->option('locale'),
        ], fn ($value) => is_string($value) && trim($value) !== '');

        $created = $policyService->createVersion($template, [
            'policy_key' => (string) ($this->option('policy') ?: $policyConfig->decisionPolicyDefaultKey()),
            'name' => $this->option('name') ?: null,
            'status' => (string) ($this->option('status') ?: 'draft'),
            'rollout_percentage' => (int) ($this->option('rollout') ?: 0),
            'target_context' => $targetContext,
            'metadata' => ['created_via' => static::class],
        ]);

        if (!$created) {
            $this->error('Failed to create policy version.');
            return self::FAILURE;
        }

        $payload = [
            'id' => $created->id,
            'policy_key' => $created->policy_key,
            'version' => $created->version,
            'status' => $created->status,
            'scope_key' => $created->scope_key,
            'rollout_percentage' => $created->rollout_percentage,
            'target_context' => $created->target_context,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Created policy version #' . $created->version . ' (' . $created->status . ')');
        $this->table(['Field', 'Value'], collect($payload)->map(fn ($value, $key) => [
            $key,
            is_array($value) ? json_encode($value) : (string) $value,
        ])->values()->all());

        return self::SUCCESS;
    }

    protected function loadTemplate(): ?string
    {
        $path = trim((string) ($this->option('template-path') ?: ''));
        if ($path !== '' && is_file($path)) {
            $content = file_get_contents($path);
            return is_string($content) && trim($content) !== '' ? $content : null;
        }

        $configured = config('ai-engine.rag.decision.template_path');
        $candidate = is_string($configured) && is_file($configured)
            ? $configured
            : dirname(__DIR__, 3) . '/resources/prompts/rag/decision_prompt.txt';

        if (!is_file($candidate)) {
            return null;
        }

        $content = file_get_contents($candidate);

        return is_string($content) && trim($content) !== '' ? $content : null;
    }
}
