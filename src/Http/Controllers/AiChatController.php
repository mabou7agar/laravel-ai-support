<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Events\AIActionTriggered;

class AiChatController extends Controller
{
    /**
     * Send a message to the AI and get a response
     */
    public function sendMessage(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'message' => 'required|string|max:4000',
                'session_id' => 'required|string|max:255',
                'engine' => 'sometimes|string|in:openai,anthropic,gemini',
                'model' => 'sometimes|string',
                'memory' => 'sometimes|boolean',
                'actions' => 'sometimes|boolean',
                'streaming' => 'sometimes|boolean',
            ]);

            $engine = $validated['engine'] ?? 'openai';
            $model = $validated['model'] ?? 'gpt-4o';
            $useMemory = $validated['memory'] ?? true;
            $useActions = $validated['actions'] ?? true;
            $useStreaming = $validated['streaming'] ?? false;

            // Create AI request
            $aiRequest = new AIRequest(
                prompt: $validated['message'],
                engine: $engine,
                model: $model,
                maxTokens: 1000,
                temperature: 0.7,
                systemPrompt: $this->getSystemPrompt($useActions),
                conversationId: $useMemory ? $validated['session_id'] : null
            );

            // Fire session started event
            event(new AISessionStarted(
                sessionId: $validated['session_id'],
                engine: $engine,
                model: $model,
                options: ['memory' => $useMemory, 'actions' => $useActions]
            ));

            if ($useStreaming) {
                return $this->handleStreamingRequest($aiRequest, $validated);
            }

            // Get AI response
            $response = Engine::engine($engine)->send($aiRequest);

            // Add interactive actions if enabled
            if ($useActions) {
                $actions = $this->generateSuggestedActions($response->content, $validated['session_id']);
                $response = $response->withActions($actions);
            }

            // Track analytics
            Engine::trackRequest([
                'session_id' => $validated['session_id'],
                'engine' => $engine,
                'model' => $model,
                'tokens' => $response->usage['total_tokens'] ?? 0,
                'cost' => $response->cost ?? 0,
                'duration' => $response->responseTime ?? 0,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'response' => $response->content,
                'actions' => $response->actions ?? [],
                'usage' => $response->usage ?? [],
                'session_id' => $validated['session_id'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute an interactive action
     */
    public function executeAction(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'action_id' => 'required|string',
                'action_type' => 'required|string',
                'session_id' => 'required|string',
                'payload' => 'sometimes|array',
            ]);

            // Create action object
            $action = new InteractiveAction(
                id: $validated['action_id'],
                type: ActionTypeEnum::from($validated['action_type']),
                label: $validated['payload']['label'] ?? 'Action',
                data: $validated['payload'] ?? []
            );

            // Execute action
            $actionResponse = Engine::executeAction($action, $validated['payload'] ?? []);

            // Fire action triggered event
            event(new AIActionTriggered(
                sessionId: $validated['session_id'],
                actionId: $validated['action_id'],
                actionType: $validated['action_type'],
                payload: $validated['payload'] ?? []
            ));

            // Track action analytics
            Engine::trackAction([
                'session_id' => $validated['session_id'],
                'action_id' => $validated['action_id'],
                'action_type' => $validated['action_type'],
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => $actionResponse->success,
                'data' => $actionResponse->data,
                'message' => $actionResponse->message,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get chat history for a session
     */
    public function getHistory(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id' => 'required|string',
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);

            $limit = $validated['limit'] ?? 50;
            
            // Get conversation history from memory
            $conversation = Engine::memory()->getConversation($validated['session_id']);
            
            if (!$conversation) {
                return response()->json([
                    'success' => true,
                    'messages' => [],
                    'session_id' => $validated['session_id'],
                ]);
            }

            $messages = collect($conversation['messages'] ?? [])
                ->take(-$limit)
                ->map(function ($message) {
                    return [
                        'role' => $message['role'],
                        'content' => $message['content'],
                        'timestamp' => $message['timestamp'] ?? null,
                        'actions' => $message['actions'] ?? [],
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'messages' => $messages,
                'session_id' => $validated['session_id'],
                'total_messages' => count($conversation['messages'] ?? []),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear chat history for a session
     */
    public function clearHistory(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id' => 'required|string',
            ]);

            // Clear conversation from memory
            Engine::memory()->clearConversation($validated['session_id']);

            return response()->json([
                'success' => true,
                'message' => 'Chat history cleared successfully',
                'session_id' => $validated['session_id'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available engines and models
     */
    public function getEngines(): JsonResponse
    {
        try {
            $engines = [
                'openai' => [
                    'name' => 'OpenAI',
                    'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'],
                    'capabilities' => ['text', 'vision', 'function_calling'],
                ],
                'anthropic' => [
                    'name' => 'Anthropic',
                    'models' => ['claude-3-5-sonnet-20241022', 'claude-3-haiku-20240307'],
                    'capabilities' => ['text', 'vision', 'function_calling'],
                ],
                'gemini' => [
                    'name' => 'Google Gemini',
                    'models' => ['gemini-1.5-pro', 'gemini-1.5-flash'],
                    'capabilities' => ['text', 'vision', 'function_calling'],
                ],
            ];

            return response()->json([
                'success' => true,
                'engines' => $engines,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle streaming request
     */
    protected function handleStreamingRequest(AIRequest $aiRequest, array $validated): JsonResponse
    {
        try {
            // Start streaming response
            Engine::streamResponse(
                sessionId: $validated['session_id'],
                generator: function() use ($aiRequest) {
                    $response = Engine::engine($aiRequest->engine->value)->send($aiRequest);
                    
                    // Simulate streaming by chunking the response
                    $chunks = str_split($response->content, 10);
                    foreach ($chunks as $chunk) {
                        yield $chunk;
                        usleep(50000); // 50ms delay between chunks
                    }
                }
            );

            return response()->json([
                'success' => true,
                'streaming' => true,
                'session_id' => $validated['session_id'],
                'message' => 'Streaming started',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate suggested actions based on AI response
     */
    protected function generateSuggestedActions(string $content, string $sessionId): array
    {
        $actions = [];

        // Add common actions
        $actions[] = new InteractiveAction(
            id: 'regenerate_' . uniqid(),
            type: ActionTypeEnum::BUTTON,
            label: '🔄 Regenerate',
            data: ['action' => 'regenerate', 'session_id' => $sessionId]
        );

        $actions[] = new InteractiveAction(
            id: 'copy_' . uniqid(),
            type: ActionTypeEnum::BUTTON,
            label: '📋 Copy',
            data: ['action' => 'copy', 'content' => $content]
        );

        // Add context-specific actions based on content
        if (str_contains(strtolower($content), 'code') || str_contains($content, '```')) {
            $actions[] = new InteractiveAction(
                id: 'explain_code_' . uniqid(),
                type: ActionTypeEnum::BUTTON,
                label: '💡 Explain Code',
                data: ['action' => 'explain_code']
            );
        }

        if (str_contains(strtolower($content), 'question') || str_contains($content, '?')) {
            $actions[] = new InteractiveAction(
                id: 'more_details_' . uniqid(),
                type: ActionTypeEnum::BUTTON,
                label: '📖 More Details',
                data: ['action' => 'more_details']
            );
        }

        // Add quick reply suggestions
        $quickReplies = ['Thank you!', 'Tell me more', 'What else?', 'Can you explain?'];
        foreach ($quickReplies as $reply) {
            $actions[] = new InteractiveAction(
                id: 'quick_reply_' . uniqid(),
                type: ActionTypeEnum::QUICK_REPLY,
                label: $reply,
                data: ['reply' => $reply]
            );
        }

        return $actions;
    }

    /**
     * Get system prompt for AI requests
     */
    protected function getSystemPrompt(bool $useActions): string
    {
        $prompt = "You are a helpful AI assistant. Provide clear, accurate, and helpful responses to user questions.";

        if ($useActions) {
            $prompt .= " When appropriate, suggest follow-up actions or questions that might be helpful to the user.";
        }

        return $prompt;
    }
}
