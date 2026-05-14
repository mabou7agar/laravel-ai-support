<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => 'required|string|max:10000',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'preference' => 'nullable|string|in:cost,cheap,speed,fast,performance,quality',
            'system_prompt' => 'nullable|string|max:4000',
            'max_tokens' => 'nullable|integer|min:1|max:16000',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'parameters' => 'nullable|array',
        ];
    }
}
