<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt' => 'required|string|max:8000',
            'stack' => 'nullable|string|max:20',
            'project_name' => 'nullable|string|max:120',
            'page' => 'nullable|string|max:120',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'max_tokens' => 'nullable|integer|min:256|max:32000',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'quality_review' => 'nullable|boolean',
            'persist' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];
    }
}
