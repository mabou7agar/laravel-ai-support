<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListAgentRunsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:60'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'tenant_id' => ['nullable', 'string', 'max:120'],
            'workspace_id' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
