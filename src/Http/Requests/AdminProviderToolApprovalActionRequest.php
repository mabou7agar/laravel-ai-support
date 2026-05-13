<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminProviderToolApprovalActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approval_key' => 'required|string|max:120',
            'actor_id' => 'nullable|string|max:120',
            'reason' => 'nullable|string|max:1000',
            'continue' => 'nullable|boolean',
            'async' => 'nullable|boolean',
            'options' => 'nullable|array',
        ];
    }
}
