<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;

class AIPromptPolicyVersion extends Model
{
    protected $table = 'ai_prompt_policy_versions';

    protected $fillable = [
        'policy_key',
        'version',
        'status',
        'scope_key',
        'name',
        'template',
        'rules',
        'target_context',
        'rollout_percentage',
        'metrics',
        'metadata',
        'promoted_from_id',
        'activated_at',
        'archived_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'rules' => 'array',
        'target_context' => 'array',
        'rollout_percentage' => 'integer',
        'metrics' => 'array',
        'metadata' => 'array',
        'promoted_from_id' => 'integer',
        'activated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function scopePolicy($query, string $policyKey)
    {
        return $query->where('policy_key', $policyKey);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCanary(): bool
    {
        return $this->status === 'canary';
    }

    public function isShadow(): bool
    {
        return $this->status === 'shadow';
    }
}
