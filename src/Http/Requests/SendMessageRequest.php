<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LaravelAIEngine\DTOs\SendMessageDTO;
use LaravelAIEngine\Enums\EngineEnum;

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
            'engine' => ['sometimes', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (!$this->isSupportedEngine((string) $value)) {
                    $fail('Invalid AI engine selected');
                }
            }],
            'model' => 'sometimes|string',
            'memory' => 'sometimes|boolean',
            'actions' => 'sometimes|boolean',
            'streaming' => 'sometimes|boolean',
            'rag' => 'sometimes|boolean',
            'force_rag' => 'sometimes|boolean',
            'rag_collections' => 'sometimes|array',
            'rag_collections.*' => 'string',
            'search_instructions' => 'sometimes|string|max:500',
            'async' => 'sometimes|boolean',
            'auto_select_model' => 'sometimes|boolean',
            'task_type' => 'sometimes|string|in:vision,coding,reasoning,fast,cheap,quality,default',
            'agent_goal' => 'sometimes|boolean',
            'target' => 'sometimes|string|max:4000',
            'sub_agents' => 'sometimes|array',
            'sub_agents.*' => 'sometimes',
            'goal_agent' => 'sometimes|array',
            'user_id' => 'sometimes|nullable|string|max:255',
            'response_points_format' => 'sometimes|string|in:text,array,both,none',
            'response_suggestions' => 'sometimes|boolean',
            'suggestions' => 'sometimes|boolean',
            'response_suggestion_limit' => 'sometimes|integer|min:0|max:25',
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

    protected function isSupportedEngine(string $engine): bool
    {
        $engine = trim($engine);
        if ($engine === '') {
            return false;
        }

        $configured = array_keys((array) config('ai-engine.engines', []));
        if (in_array($engine, $configured, true)) {
            return true;
        }

        try {
            EngineEnum::from($engine);

            return true;
        } catch (\Throwable) {
            return false;
        }
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
            userId: $validated['user_id'] ?? auth()->user()?->getAuthIdentifier(),
            intelligentRag: $validated['rag'] ?? false,
            forceRag: $validated['force_rag'] ?? false,
            ragCollections: $validated['rag_collections'] ?? null,
            searchInstructions: $validated['search_instructions'] ?? null,
            agentGoal: $validated['agent_goal'] ?? false,
            target: $validated['target'] ?? null,
            subAgents: $validated['sub_agents'] ?? null,
            goalAgent: $validated['goal_agent'] ?? null,
            responsePointsFormat: $validated['response_points_format'] ?? null,
            responseSuggestions: $validated['response_suggestions'] ?? $validated['suggestions'] ?? null,
            responseSuggestionLimit: $validated['response_suggestion_limit'] ?? null
        );
    }
}
