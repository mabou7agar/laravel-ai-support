<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminAgentRunActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'actor_id' => ['nullable', 'string', 'max:255'],
            'queue' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array'],
        ];
    }
}
