<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Auth\Authenticatable;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;

class AgentConversationService
{
    public function __construct(
        protected AIEngineService $ai,
        protected AutonomousRAGAgent $ragAgent,
        protected SelectedEntityContextService $selectedEntityContext,
        protected AgentSelectionService $selectionService,
        protected ?LocaleResourceService $localeResources = null
    ) {
    }

    public function executeSearchRAG(
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $reroute
    ): AgentResponse {
        $conversationHistory = $context->conversationHistory ?? [];

        Log::channel('ai-engine')->debug('Executing RAG search', [
            'message' => substr($message, 0, 100),
            'session_id' => $context->sessionId,
        ]);

        $selectedEntity = $this->selectedEntityContext->getFromContext($context);
        if ($selectedEntity) {
            $options['selected_entity'] = $selectedEntity;
        }

        $result = $this->ragAgent->process(
            $message,
            $context->sessionId,
            $context->userId,
            $conversationHistory,
            $options
        );

        if (!empty($result['exit_to_orchestrator'])) {
            $newMessage = $result['message'] ?? $message;

            Log::channel('ai-engine')->debug('RAG agent exiting to orchestrator for CRUD operation', [
                'original_message' => substr($message, 0, 100),
                'new_message' => substr($newMessage, 0, 100),
            ]);

            $options['skip_ai_decision'] = true;

            return $reroute($newMessage, $context->sessionId, $context->userId, $options);
        }

        if ($result['success'] ?? false) {
            $responseText = $result['response'] ?? $this->translate(
                'ai-engine::messages.agent.no_results_found',
                'No results found.'
            );

            $context->metadata['tool_used'] = $result['tool'] ?? 'unknown';
            $context->metadata['fast_path'] = $result['fast_path'] ?? false;
            if (!empty($result['metadata']) && is_array($result['metadata'])) {
                $context->metadata['rag_last_metadata'] = $result['metadata'];
            }
            if (!empty($result['suggested_actions']) && is_array($result['suggested_actions'])) {
                $context->metadata['suggested_actions'] = array_values($result['suggested_actions']);
            } else {
                unset($context->metadata['suggested_actions']);
            }
            $this->selectionService->captureSelectionStateFromResult($result, $context);

            $messageMetadata = [];
            if (!empty($result['metadata']['entity_ids'])) {
                $messageMetadata['entity_ids'] = $result['metadata']['entity_ids'];
                $messageMetadata['entity_type'] = $result['metadata']['entity_type'] ?? 'item';
            }

            return AgentResponse::conversational(
                message: $responseText,
                context: $context,
                metadata: $messageMetadata
            );
        }

        $errorMessage = $this->formatRagFailureMessage($result);

        return AgentResponse::conversational(
            message: $errorMessage,
            context: $context
        );
    }

    public function executeConversational(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        Log::channel('ai-engine')->debug('Executing conversational response', [
            'message' => substr($message, 0, 100),
            'session_id' => $context->sessionId,
        ]);

        $conversationHistory = $context->conversationHistory ?? [];
        $historyText = '';
        foreach (array_slice($conversationHistory, -3) as $msg) {
            $historyText .= "{$msg['role']}: {$msg['content']}\n";
        }

        $locale = $this->locale()->resolveLocale(app()->getLocale());
        $prompt = $this->locale()->renderPromptTemplate(
            'agent/conversational_response',
            [
                'history_text' => trim($historyText),
                'user_message' => $message,
                'user_profile_context' => $this->buildUserProfileContext($context->userId),
            ],
            $locale
        );

        if ($prompt === '') {
            $userProfileContext = $this->buildUserProfileContext($context->userId);
            $prompt = <<<PROMPT
You are a helpful AI assistant. Respond naturally to the user's message.

Authenticated user context:
{$userProfileContext}

Recent conversation:
{$historyText}

User: {$message}

Respond in a friendly, helpful manner.
If the user asks about their own profile/account details, answer using the authenticated user context above.
PROMPT;
        }

        $aiResponse = $this->ai->generate(new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from($options['engine'] ?? 'openai'),
            model: EntityEnum::from($options['model'] ?? 'gpt-4o-mini'),
            maxTokens: 200,
            temperature: 0.7,
        ));

        return AgentResponse::conversational(
            message: $aiResponse->getContent(),
            context: $context
        );
    }

    protected function formatRagFailureMessage(array $result): string
    {
        $error = trim((string) ($result['error'] ?? ''));
        if ($error === '') {
            return $this->translate(
                'ai-engine::messages.agent.rag_no_relevant_info',
                "I couldn't find any relevant information. Could you please rephrase your question?"
            );
        }

        $errorLower = strtolower($error);
        if (str_contains($errorLower, "couldn't reach remote node") || str_contains($errorLower, 'http')) {
            return $this->translate(
                'ai-engine::messages.agent.rag_remote_unreachable',
                "I couldn't reach the remote node right now. Please try again in a moment."
            );
        }

        if (str_contains($errorLower, 'no node found')) {
            return $this->translate(
                'ai-engine::messages.agent.rag_node_not_found',
                "I couldn't find a connected node that owns this data."
            );
        }

        if (str_contains($errorLower, 'model') && str_contains($errorLower, 'not found')) {
            return $this->translate(
                'ai-engine::messages.agent.rag_model_not_found',
                "I couldn't match this request to an available data model."
            );
        }

        return $this->translate(
            'ai-engine::messages.agent.rag_lookup_failed',
            "I couldn't complete the data lookup right now. Please try again."
        );
    }

    protected function translate(string $key, string $fallback): string
    {
        $translated = __($key);

        if (!is_string($translated) || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }

    protected function buildUserProfileContext(?string $userId): string
    {
        if (!config('ai-engine.inject_user_context', true)) {
            return 'User context injection is disabled.';
        }

        $user = $this->resolveUser($userId);
        if (!$user) {
            return 'No authenticated user profile is available.';
        }

        $profile = $this->sanitizeProfile($user);
        if ($profile === []) {
            return 'Authenticated user exists, but profile fields are not available.';
        }

        $lines = [
            'This profile belongs to the current authenticated user.',
            'Use it for questions like "what is my name/email/role".',
            'If a field is missing, say it is unavailable.',
            'PROFILE:',
            json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return implode("\n", $lines);
    }

    protected function resolveUser(?string $userId): ?Authenticatable
    {
        $authenticatedUser = auth()->user();
        if ($authenticatedUser instanceof Authenticatable) {
            if ($userId === null || $userId === '' || (string) $authenticatedUser->getAuthIdentifier() === (string) $userId) {
                return $authenticatedUser;
            }
        }

        if (!$userId) {
            return null;
        }

        $modelClass = config('auth.providers.users.model', \App\Models\User::class);
        if (!is_string($modelClass) || !class_exists($modelClass) || !method_exists($modelClass, 'find')) {
            return null;
        }

        $user = $modelClass::find($userId);

        return $user instanceof Authenticatable ? $user : null;
    }

    protected function sanitizeProfile(Authenticatable $user): array
    {
        $profile = [];

        if (method_exists($user, 'toArray')) {
            $profile = (array) $user->toArray();
        }

        if ($profile === []) {
            $profile = [
                'id' => $user->getAuthIdentifier(),
            ];

            foreach (['name', 'email', 'username', 'first_name', 'last_name', 'phone', 'role'] as $key) {
                if (isset($user->{$key})) {
                    $profile[$key] = $user->{$key};
                }
            }
        }

        return $this->removeSensitiveFields($profile);
    }

    protected function removeSensitiveFields(array $profile): array
    {
        $blockedTokens = [
            'password',
            'remember_token',
            'token',
            'secret',
            'recovery_code',
            'api_key',
        ];

        foreach (array_keys($profile) as $key) {
            $normalized = strtolower((string) $key);
            foreach ($blockedTokens as $token) {
                if (str_contains($normalized, $token)) {
                    unset($profile[$key]);
                    continue 2;
                }
            }

            if (is_array($profile[$key])) {
                $profile[$key] = $this->removeSensitiveFields($profile[$key]);
            }
        }

        return $profile;
    }
}
