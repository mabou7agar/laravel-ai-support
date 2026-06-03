<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Http\Requests\ListConversationsRequest;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\ConversationTranscriptService;

class AgentConversationApiController extends Controller
{
    public function __construct(
        protected ConversationTranscriptService $conversationService,
        protected ConversationManager $conversationManager,
    ) {}

    public function index(ListConversationsRequest $request): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (!$userId) {
            return $this->unauthenticated();
        }

        $folderId = $this->nullableString($request->input('folder_id'));

        $payload = $this->conversationService->listUserConversations(
            userId: $userId,
            limit: (int) $request->input('limit', 20),
            page: (int) $request->input('page', 1),
            folderId: $folderId,
        );

        return response()->json([
            'success' => true,
            'data' => $payload->toArray(),
        ]);
    }

    /**
     * Search the authenticated user's conversations by title or message content.
     */
    public function search(ListConversationsRequest $request): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (!$userId) {
            return $this->unauthenticated();
        }

        $payload = $this->conversationService->searchUserConversations(
            userId: $userId,
            term: (string) $request->input('q', ''),
            limit: (int) $request->input('limit', 20),
            page: (int) $request->input('page', 1),
            folderId: $this->nullableString($request->input('folder_id')),
        );

        return response()->json([
            'success' => true,
            'data' => $payload->toArray(),
        ]);
    }

    /**
     * Assign a conversation to a folder (or clear it when folder_id is null).
     */
    public function moveToFolder(Request $request): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (!$userId) {
            return $this->unauthenticated();
        }

        $validated = $request->validate([
            'conversation_id' => 'required|string|max:255',
            'folder_id' => 'nullable|string|max:255',
        ]);

        $conversation = $this->conversationManager->getConversation($validated['conversation_id']);

        if (!$conversation || (string) $conversation->user_id !== (string) $userId) {
            return response()->json([
                'success' => false,
                'error' => 'Conversation not found',
            ], 404);
        }

        $this->conversationManager->setConversationFolder(
            $validated['conversation_id'],
            $this->nullableString($validated['folder_id'] ?? null),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'conversation_id' => $validated['conversation_id'],
                'folder_id' => $this->nullableString($validated['folder_id'] ?? null),
            ],
        ]);
    }

    protected function unauthenticated(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'Authentication required',
        ], 401);
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
