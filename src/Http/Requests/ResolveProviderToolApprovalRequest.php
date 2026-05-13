<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveProviderToolApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:1000',
            'actor_id' => 'nullable|string|max:120',
            'continue' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];
    }
}
