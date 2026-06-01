<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Auth\Authenticatable;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryRetriever;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryScopeResolver;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;

class AgentConversationService
{
    public function __construct(
        protected AIEngineService $ai,
        protected RAGDecisionEngine $ragDecisionEngine,
        protected SelectedEntityContextService $selectedEntityContext,
        protected AgentSelectionService $selectionService,
        protected ?RAGPipelineContract $ragPipeline = null,
        protected ?LocaleResourceService $localeResources = null,
        protected ?RoutingContextResolver $routingContextResolver = null,
        protected ?ConversationMemoryRetriever $memoryRetriever = null,
        protected ?ConversationMemoryPromptBuilder $memoryPromptBuilder = null,
        protected ?ConversationMemoryScopeResolver $memoryScopeResolver = null
    ) {
        $this->routingContextResolver ??= new RoutingContextResolver($this->selectedEntityContext);
        $this->memoryScopeResolver ??= app(ConversationMemoryScopeResolver::class);
    }

    public function executeSearchRAG(
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $reroute
    ): AgentResponse {
        Log::channel('ai-engine')->debug('Executing RAG search', [
            'message' => substr($message, 0, 100),
            'session_id' => $context->sessionId,
        ]);

        $options = $this->routingContextResolver->mergeConversationContext($context, $options);
        $this->recordRoutingMetadata($context, $options);

        if ($this->shouldUseRagPipeline($options)) {
            return $this->formatRagPipelineResponse(
                $this->executeRagPipeline($message, $context, $options),
                $context,
                $options
            );
        }

        $result = $this->ragDecisionEngine->process(
            $message,
            $context->sessionId,
            $context->userId,
            $context->conversationHistory ?? [],
            $options
        );

        if (!empty($result['exit_to_orchestrator'])) {
            $newMessage = $result['message'] ?? $message;

            Log::channel('ai-engine')->debug('RAG agent exiting to orchestrator for CRUD operation', [
                'original_message' => substr($message, 0, 100),
                'new_message' => substr($newMessage, 0, 100),
            ]);

            // Strip the client-supplied conversation_history from reroute options so the
            // processor's hydrateConversationHistory early-return guard does not silently
            // skip re-hydration on the rerouted turn (context already carries the history).
            $rerouteOptions = $options;
            unset($rerouteOptions['conversation_history']);

            return $reroute($newMessage, $context->sessionId, $context->userId, $rerouteOptions);
        }

        if ($result['success'] ?? false) {
            $responseText = $result['response'] ?? $this->translate(
                'ai-engine::messages.agent.no_results_found',
                'No results found.'
            );

            $context->metadata['tool_used'] = $result['tool'] ?? 'unknown';
            $context->metadata['fast_path'] = $result['fast_path'] ?? false;
            if (!empty($result['decision_source'])) {
                $context->metadata['decision_source'] = $result['decision_source'];
            }
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
        $this->recordRoutingMetadata($context, $options);

        $conversationHistory = $context->conversationHistory ?? [];
        $historyText = '';
        $memoryText = $this->retrieveMemoryPrompt($message, $context, $options);
        if ($memoryText !== '') {
            $historyText .= "{$memoryText}\n\n";
        }

        $conversationSummary = trim((string) ($context->metadata['conversation_summary'] ?? $options['conversation_summary'] ?? ''));
        if ($conversationSummary !== '') {
            $historyText .= "Earlier conversation summary:\n{$conversationSummary}\n\n";
        }

        foreach (array_slice($conversationHistory, -$this->recentConversationMessageLimit()) as $msg) {
            $historyText .= "{$msg['role']}: {$msg['content']}\n";
        }

        $userId = $context->userId !== null ? (string) $context->userId : null;
        $locale = $this->locale()->resolveLocale(app()->getLocale());
        $prompt = $this->locale()->renderPromptTemplate(
            'agent/conversational_response',
            [
                'history_text' => trim($historyText),
                'memory_text' => $memoryText,
                'user_message' => $message,
                'user_profile_context' => $this->buildUserProfileContext($userId),
            ],
            $locale
        );

        if ($prompt === '') {
            $userProfileContext = $this->buildUserProfileContext($userId);
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
            maxTokens: (int) ($options['max_tokens'] ?? config('ai-agent.conversational.max_tokens', 200)),
            temperature: 0.7,
        ));

        if (!$aiResponse->isSuccessful()) {
            return AgentResponse::failure(
                message: $aiResponse->getError() ?: 'AI engine failed to generate a response.',
                context: $context
            );
        }

        $content = trim($aiResponse->getContent());
        if ($content === '') {
            return AgentResponse::failure(
                message: 'AI engine returned an empty response.',
                context: $context
            );
        }

        return AgentResponse::conversational(
            message: $content,
            context: $context
        );
    }

    protected function recordRoutingMetadata(UnifiedActionContext $context, array $options): void
    {
        foreach ([
            'preclassified_route_mode' => 'route_mode',
            'decision_path' => 'decision_path',
            'decision_source' => 'decision_source',
        ] as $optionKey => $metadataKey) {
            if (!empty($options[$optionKey])) {
                $context->metadata[$metadataKey] = $options[$optionKey];
            }
        }
    }

    protected function retrieveMemoryPrompt(string $message, UnifiedActionContext $context, array $options): string
    {
        if (!(bool) config('ai-agent.conversation_memory.enabled', true)) {
            unset($context->metadata['retrieved_memory']);

            return '';
        }

        try {
            $scope = $this->memoryScopeResolver()->fromContext($context, $options);
            $results = $this->memoryRetriever()->retrieve(new ConversationMemoryQuery(
                message: $message,
                scopeType: $scope['scope_type'],
                scopeId: $scope['scope_id'],
                userId: $context->userId !== null ? (string) $context->userId : null,
                tenantId: $this->scopeValue($context, $options, 'tenant_id', 'tenant_id'),
                workspaceId: $this->scopeValue($context, $options, 'workspace_id', 'workspace_id'),
                sessionId: $context->sessionId,
                limit: (int) config('ai-agent.conversation_memory.max_memories_per_turn', 6),
                metadata: $options,
            ));

            $text = $this->memoryPromptBuilder()->build($results);
            if ($text === '') {
                unset($context->metadata['retrieved_memory']);
            } else {
                $context->metadata['retrieved_memory'] = $text;
            }

            return $text;
        } catch (\Throwable $exception) {
            $context->metadata['retrieved_memory_error'] = $exception->getMessage();

            return '';
        }
    }

    protected function scopeValue(UnifiedActionContext $context, array $options, string $configuredKey, string $fallbackKey): ?string
    {
        $value = $options[$configuredKey]
            ?? $options[$fallbackKey]
            ?? $context->metadata[$configuredKey]
            ?? $context->metadata[$fallbackKey]
            ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    protected function memoryRetriever(): ConversationMemoryRetriever
    {
        return $this->memoryRetriever ??= app(ConversationMemoryRetriever::class);
    }

    protected function memoryPromptBuilder(): ConversationMemoryPromptBuilder
    {
        return $this->memoryPromptBuilder ??= app(ConversationMemoryPromptBuilder::class);
    }

    protected function memoryScopeResolver(): ConversationMemoryScopeResolver
    {
        return $this->memoryScopeResolver ??= app(ConversationMemoryScopeResolver::class);
    }

    protected function shouldUseRagPipeline(array $options): bool
    {
        return empty($options['allow_rag_exit_to_orchestrator'])
            && empty($options['selected_entity'])
            && empty($options['selected_entity_context'])
            && ($options['preclassified_route_mode'] ?? null) !== 'structured_query';
    }

    protected function executeRagPipeline(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        if (!$this->ragPipeline instanceof RAGPipelineContract) {
            return AgentResponse::failure(
                message: 'RAG pipeline is not available.',
                context: $context
            );
        }

        return $this->ragPipeline->answer($message, array_merge($options, [
            'session_id' => $context->sessionId,
            'conversation_history' => $context->conversationHistory ?? [],
        ]), is_int($context->userId) || is_string($context->userId) ? $context->userId : null);
    }

    protected function formatRagPipelineResponse(
        AgentResponse $response,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $context->metadata['tool_used'] = 'rag_pipeline';
        $context->metadata['fast_path'] = false;
        $context->metadata['decision_source'] = $options['decision_source'] ?? $context->metadata['decision_source'] ?? 'rag_pipeline';
        $context->metadata['rag_last_metadata'] = $response->metadata ?? [];

        if (!$response->success) {
            return $response;
        }

        return AgentResponse::conversational(
            message: $response->message,
            context: $context,
            metadata: array_merge($response->metadata ?? [], [
                'rag_pipeline' => true,
            ])
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

    protected function buildUserProfileContext(int|string|null $userId): string
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

    protected function resolveUser(int|string|null $userId): ?Authenticatable
    {
        $userId = $userId !== null ? (string) $userId : null;
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

    protected function recentConversationMessageLimit(): int
    {
        $configured = (int) config(
            'ai-engine.conversation_history.recent_messages',
            config('ai-agent.context_compaction.keep_recent_messages', 6)
        );

        return max(3, $configured);
    }

    protected function sanitizeProfile(Authenticatable $user): array
    {
        $profile = [
            'id' => $user->getAuthIdentifier(),
        ];

        $allowedFields = [
            'name',
            'email',
            'username',
            'first_name',
            'last_name',
            'phone',
            'mobile_no',
            'type',
            'role',
            'lang',
            'locale',
            'timezone',
            'created_by',
            'creator_id',
        ];

        if (method_exists($user, 'getAttribute')) {
            foreach ($allowedFields as $field) {
                $value = $user->getAttribute($field);
                if ($value !== null && !is_array($value) && !is_object($value)) {
                    $profile[$field] = $value;
                }
            }

            return $this->removeSensitiveFields(array_filter(
                $profile,
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ));
        }

        foreach ($allowedFields as $field) {
            if (isset($user->{$field}) && !is_array($user->{$field}) && !is_object($user->{$field})) {
                $profile[$field] = $user->{$field};
            }
        }

        return $this->removeSensitiveFields(array_filter(
            $profile,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        ));
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
