<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIAgentRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING_APPROVAL = 'waiting_approval';
    public const STATUS_WAITING_INPUT = 'waiting_input';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_WAITING_APPROVAL,
        self::STATUS_WAITING_INPUT,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    protected $table = 'ai_agent_runs';

    protected $fillable = [
        'uuid',
        'session_id',
        'user_id',
        'tenant_id',
        'workspace_id',
        'runtime',
        'status',
        'schema_version',
        'input',
        'final_response',
        'current_step',
        'routing_trace',
        'failure_reason',
        'metadata',
        'started_at',
        'waiting_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'expired_at',
    ];

    protected $casts = [
        'schema_version' => 'integer',
        'input' => 'array',
        'final_response' => 'array',
        'routing_trace' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'waiting_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(AIAgentRunStep::class, 'run_id')->orderBy('sequence');
    }

    public function providerToolRuns(): HasMany
    {
        return $this->hasMany(AIProviderToolRun::class, 'agent_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ], true);
    }
}
