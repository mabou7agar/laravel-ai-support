<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FalCatalogWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_tool_run_id' => 'nullable|string|max:120',
            'request_id' => 'nullable|string|max:120',
            'status' => 'nullable|string|max:60',
            'payload' => 'nullable|array',
            'error' => 'nullable',
        ];
    }
}
