<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListProviderToolArtifactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tool_run_id' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:60'],
            'artifact_type' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
