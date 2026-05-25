<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminPolicyActivateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:active,canary,shadow'],
        ];
    }
}
