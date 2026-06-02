<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModelCouncilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt' => 'required|string|max:8000',
            'members' => 'required|array|min:1|max:8',
            'members.*.model' => 'required|string|max:255',
            'members.*.engine' => 'sometimes|nullable|string|max:255',
            'members.*.system_prompt' => 'sometimes|nullable|string|max:4000',
            'options' => 'sometimes|array',
            'options.system_prompt' => 'sometimes|nullable|string|max:4000',
            'options.temperature' => 'sometimes|numeric|min:0|max:2',
            'options.max_tokens' => 'sometimes|integer|min:1|max:32000',
            'user_id' => 'sometimes|nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'members.required' => 'Provide at least one council member (engine/model).',
            'members.max' => 'A council can have at most 8 members.',
        ];
    }
}
