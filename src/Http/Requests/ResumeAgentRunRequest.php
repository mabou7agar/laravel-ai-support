<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResumeAgentRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => 'nullable|string|max:20000',
            'approval_key' => 'nullable|string|max:120',
            'actor_id' => 'nullable|string|max:120',
            'reason' => 'nullable|string|max:2000',
            'queue' => 'nullable|boolean',
            'idempotency_key' => 'nullable|string|max:160',
            'langgraph_run_id' => 'nullable|string|max:160',
            'options' => 'nullable|array',
            'payload' => 'nullable|array',
        ];
    }
}
