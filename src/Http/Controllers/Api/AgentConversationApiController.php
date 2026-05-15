<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Http\Requests\ListConversationsRequest;
use LaravelAIEngine\Services\ConversationService;

class AgentConversationApiController extends Controller
{
    public function __construct(
        protected ConversationService $conversationService,
    ) {}

    public function index(ListConversationsRequest $request): JsonResponse
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (!$userId) {
            return response()->json([
                'success' => false,
                'error' => 'Authentication required',
            ], 401);
        }

        $payload = $this->conversationService->listUserConversations(
            userId: $userId,
            limit: (int) $request->input('limit', 20),
            page: (int) $request->input('page', 1),
        );

        return response()->json([
            'success' => true,
            'data' => $payload->toArray(),
        ]);
    }
}
