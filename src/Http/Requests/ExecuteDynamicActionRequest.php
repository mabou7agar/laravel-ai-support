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
        $validated = $this->validated();
        
        return new ExecuteDynamicActionDTO(
            actionId: $validated['action_id'],
            parameters: $validated['parameters'] ?? [],
            userId: auth()->id()
        );
    }
}
