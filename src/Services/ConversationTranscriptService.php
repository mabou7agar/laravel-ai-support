<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ConversationListResponseDTO;
use LaravelAIEngine\DTOs\ConversationPaginationDTO;
use LaravelAIEngine\DTOs\ConversationSessionPreviewDTO;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Models\Conversation;

class ConversationTranscriptService
{
    public function __construct(
        protected ConversationManager $conversationManager
    ) {}

    public function getOrCreateConversation(
        string $sessionId,
        string|int|null $userId = null,
        string $engine = 'openai',
        string $model = 'gpt-4o-mini'
    ): string {
        $query = DB::table('ai_conversations')
            ->where('title', $sessionId);

        if ($userId !== null) {
            $query->where('user_id', (string) $userId);
        } else {
            $query->whereNull('user_id');
        }

        $existing = $query->first();
        if ($existing) {
            return $existing->conversation_id;
        }

        $conversation = $this->conversationManager->createConversation(
            userId: $userId !== null ? (string) $userId : null,
            title: $sessionId,
            systemPrompt: 'You are a helpful AI assistant.',
            settings: [
                'engine' => $engine,
                'model' => $model,
            ]
        );

        return $conversation->conversation_id;
    }

    public function loadConversationHistory(string $conversationId, int $limit = 20): array
    {
        try {
            $conversation = Engine::memory()->getConversation($conversationId);

            if ($conversation && isset($conversation['messages']) && !empty($conversation['messages'])) {
                return array_slice($conversation['messages'], -$limit);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load conversation history: ' . $e->getMessage());
        }

        return [];
    }

    public function saveMessages(
        string $conversationId,
        string $userMessage,
        AIResponse $aiResponse
    ): void {
        $this->conversationManager->addUserMessage($conversationId, $userMessage);
        $this->conversationManager->addAssistantMessage(
            $conversationId,
            $aiResponse->getContent(),
            $aiResponse
        );
    }

    public function getConversationHistory(string $sessionId, int $limit = 50, string|int|null $userId = null): array
    {
        $query = DB::table('ai_conversations')
            ->where('title', $sessionId);

        if ($userId !== null) {
            $query->where('user_id', (string) $userId);
        } else {
            $query->whereNull('user_id');
        }

        $conversation = $query->first();
        if (!$conversation) {
            return [];
        }

        try {
            $conversationData = Engine::memory()->getConversation($conversation->conversation_id);

            if ($conversationData && isset($conversationData['messages'])) {
                return array_slice($conversationData['messages'], -$limit);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get conversation history: ' . $e->getMessage());
        }

        return [];
    }

    public function clearConversation(string $sessionId): bool
    {
        try {
            $conversation = DB::table('ai_conversations')
                ->where('title', $sessionId)
                ->first();

            if ($conversation) {
                $this->conversationManager->clearConversationHistory($conversation->conversation_id);

                return true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to clear conversation: ' . $e->getMessage());
        }

        return false;
    }

    public function listUserConversations(
        string|int $userId,
        int $limit = 20,
        int $page = 1,
        ?string $folderId = null
    ): ConversationListResponseDTO {
        $query = Conversation::forUser((string) $userId)->active()->inFolder($folderId);

        return $this->paginateConversationQuery($query, $limit, $page);
    }

    /**
     * Search a user's conversations by title or message content.
     */
    public function searchUserConversations(
        string|int $userId,
        string $term,
        int $limit = 20,
        int $page = 1,
        ?string $folderId = null
    ): ConversationListResponseDTO {
        $term = trim($term);

        if ($term === '') {
            return $this->listUserConversations($userId, $limit, $page, $folderId);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        $query = Conversation::forUser((string) $userId)
            ->active()
            ->inFolder($folderId)
            ->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhereHas('messages', function ($mq) use ($like) {
                        $mq->where('content', 'like', $like);
                    });
            });

        return $this->paginateConversationQuery($query, $limit, $page);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function paginateConversationQuery($query, int $limit, int $page): ConversationListResponseDTO
    {
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $total = (clone $query)->count();

        $conversations = $query
            ->withCount('messages')
            ->with(['latestMessage', 'latestAssistantMessage', 'firstUserMessage'])
            ->orderBy('last_activity_at', 'desc')
            ->skip($offset)
            ->limit($limit)
            ->get();

        return new ConversationListResponseDTO(
            conversations: $conversations
                ->map(static fn ($conversation) => ConversationSessionPreviewDTO::fromConversation($conversation))
                ->all(),
            pagination: new ConversationPaginationDTO(
                total: $total,
                perPage: $limit,
                currentPage: $page,
                lastPage: (int) ceil($total / $limit),
                from: $total > 0 ? $offset + 1 : 0,
                to: min($offset + $limit, $total),
            ),
        );
    }
}
