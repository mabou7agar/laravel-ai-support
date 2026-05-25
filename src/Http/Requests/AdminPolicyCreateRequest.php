<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminPolicyCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_key' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'template' => ['required', 'string', 'min:10'],
            'status' => ['required', 'string', 'in:draft,active,canary,shadow'],
            'rollout_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tenant_id' => ['nullable', 'string', 'max:120'],
            'app_id' => ['nullable', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', 'string', 'max:40'],
        ];
    }
}
