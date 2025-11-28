<?php

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LaravelAIEngine\DTOs\ExecuteActionDTO;

class ExecuteActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action_id' => 'required|string',
            'action_type' => 'required|string',
            'session_id' => 'required|string',
            'payload' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'action_id.required' => 'Action ID is required',
            'action_type.required' => 'Action type is required',
            'session_id.required' => 'Session ID is required',
        ];
    }

    public function toDTO(): ExecuteActionDTO
    {
        return new ExecuteActionDTO(
            actionId: $this->validated('action_id'),
            actionType: $this->validated('action_type'),
            sessionId: $this->validated('session_id'),
            payload: $this->validated('payload', []),
            userId: auth()->id()
        );
    }
}
