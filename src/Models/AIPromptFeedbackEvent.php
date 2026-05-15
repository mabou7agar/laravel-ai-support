<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;

class AIPromptFeedbackEvent extends Model
{
    protected $table = 'ai_prompt_feedback_events';

    protected $fillable = [
        'channel',
        'event_type',
        'policy_key',
        'policy_id',
        'policy_version',
        'policy_status',
        'session_id',
        'conversation_id',
        'user_id',
        'tenant_id',
        'app_id',
        'request_text',
        'message_excerpt',
        'raw_response_excerpt',
        'decision_tool',
        'decision_source',
        'reasoning',
        'decision_parameters',
        'tool_calls',
        'relist_risk',
        'success',
        'outcome',
        'latency_ms',
        'tokens_used',
        'token_cost',
        'user_rating',
        'metadata',
    ];

    protected $casts = [
        'policy_id' => 'integer',
        'policy_version' => 'integer',
        'decision_parameters' => 'array',
        'tool_calls' => 'array',
        'relist_risk' => 'boolean',
        'success' => 'boolean',
        'latency_ms' => 'integer',
        'tokens_used' => 'integer',
        'token_cost' => 'float',
        'user_rating' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }
}
