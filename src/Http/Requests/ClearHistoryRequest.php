<?php

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LaravelAIEngine\DTOs\ClearHistoryDTO;

class ClearHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'session_id.required' => 'Session ID is required',
        ];
    }

    public function toDTO(): ClearHistoryDTO
    {
        return new ClearHistoryDTO(
            sessionId: $this->validated('session_id'),
            userId: auth()->id()
        );
    }
}
