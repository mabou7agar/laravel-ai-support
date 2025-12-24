<?php

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LaravelAIEngine\DTOs\SendMessageDTO;

class SendMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'message' => 'required|string|max:4000',
            'session_id' => 'required|string|max:255',
            'engine' => 'sometimes|string|in:openai,anthropic,gemini',
            'model' => 'sometimes|string',
            'memory' => 'sometimes|boolean',
            'actions' => 'sometimes|boolean',
            'streaming' => 'sometimes|boolean',
            'intelligent_rag' => 'sometimes|boolean',
            'force_rag' => 'sometimes|boolean',
            'rag_collections' => 'sometimes|array',
            'rag_collections.*' => 'string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Please enter a message',
            'message.max' => 'Message cannot exceed 4000 characters',
            'session_id.required' => 'Session ID is required',
            'engine.in' => 'Invalid AI engine selected',
        ];
    }

    /**
     * Convert validated data to DTO
     */
    public function toDTO(): SendMessageDTO
    {
        return new SendMessageDTO(
            message: $this->validated('message'),
            sessionId: $this->validated('session_id'),
            engine: $this->validated('engine', 'openai'),
            model: $this->validated('model', 'gpt-4o'),
            memory: $this->validated('memory', true),
            actions: $this->validated('actions', true),
            streaming: $this->validated('streaming', false),
            userId: auth()->user()?->id ?? config('ai-engine.demo_user_id', '1'),
            intelligentRag: $this->validated('intelligent_rag', false),
            forceRag: $this->validated('force_rag', false),
            ragCollections: $this->validated('rag_collections', null)
        );
    }
}
