<?php

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LaravelAIEngine\DTOs\ExecuteDynamicActionDTO;

class ExecuteDynamicActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action_id' => 'required|string',
            'parameters' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'action_id.required' => 'Action ID is required',
        ];
    }

    public function toDTO(): ExecuteDynamicActionDTO
    {
        return new ExecuteDynamicActionDTO(
            actionId: $this->validated('action_id'),
            parameters: $this->validated('parameters', []),
            userId: auth()->id()
        );
    }
}
