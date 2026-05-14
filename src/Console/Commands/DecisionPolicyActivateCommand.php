<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;

class DecisionPolicyActivateCommand extends Command
{
    protected $signature = 'ai-engine:decision-policy:activate
                            {id : Policy version ID}
                            {--status=active : Target status (active|canary|shadow)}
                            {--json : Output JSON payload}';

    protected $description = 'Activate a decision prompt policy version as active/canary/shadow';

    public function handle(RAGPromptPolicyService $policyService): int
    {
        if (!$policyService->storeAvailable()) {
            $this->warn('Decision policy store is disabled or unavailable.');
            return self::SUCCESS;
        }

        $policyId = (int) $this->argument('id');
        $status = (string) ($this->option('status') ?: 'active');

        $activated = $policyService->activate($policyId, $status);
        if (!$activated) {
            $this->error('Policy version not found or activation failed.');
            return self::FAILURE;
        }

        $payload = [
            'id' => $activated->id,
            'policy_key' => $activated->policy_key,
            'version' => $activated->version,
            'status' => $activated->status,
            'scope_key' => $activated->scope_key,
            'activated_at' => optional($activated->activated_at)->toISOString(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Policy version activated.');
        $this->table(['Field', 'Value'], collect($payload)->map(fn ($value, $key) => [
            $key,
            (string) $value,
        ])->values()->all());

        return self::SUCCESS;
    }
}
