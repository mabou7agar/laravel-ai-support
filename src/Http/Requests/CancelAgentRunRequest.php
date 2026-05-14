<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelAgentRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actor_id' => 'nullable|string|max:120',
            'reason' => 'nullable|string|max:2000',
            'langgraph_run_id' => 'nullable|string|max:160',
        ];
    }
}
