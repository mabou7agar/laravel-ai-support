<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FalCatalogExecuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'model' => 'required|string|max:255',
            'prompt' => 'nullable|string|max:10000',
            'input' => 'nullable|array',
            'parameters' => 'nullable|array',
            'async' => 'nullable|boolean',
            'webhook_url' => 'nullable|url|max:2048',
            'metadata' => 'nullable|array',
        ];
    }
}
