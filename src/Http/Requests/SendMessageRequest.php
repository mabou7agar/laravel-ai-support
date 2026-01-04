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
            'search_instructions' => 'sometimes|string|max:500',
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
        $validated = $this->validated();
        
        return new SendMessageDTO(
            message: $validated['message'],
            sessionId: $validated['session_id'],
            engine: $validated['engine'] ?? 'openai',
            model: $validated['model'] ?? 'gpt-4o',
            memory: $validated['memory'] ?? true,
            actions: $validated['actions'] ?? true,
            streaming: $validated['streaming'] ?? false,
            userId: auth()->user()?->id ?? config('ai-engine.demo_user_id', '1'),
            intelligentRag: $validated['intelligent_rag'] ?? false,
            forceRag: $validated['force_rag'] ?? false,
            ragCollections: $validated['rag_collections'] ?? null,
            searchInstructions: $validated['search_instructions'] ?? null
        );
    }
}
