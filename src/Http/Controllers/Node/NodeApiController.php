<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Node;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\Node\NodeApiOperationsService;
use LaravelAIEngine\Services\Node\NodeAuthService;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class NodeApiController extends Controller
{
    public function __construct(
        private readonly NodeApiOperationsService $operations
    ) {}

    public function health(): JsonResponse
    {
        return $this->operations->health();
    }

    public function manifest(): JsonResponse
    {
        return $this->operations->manifest();
    }

    public function collections(): JsonResponse
    {
        return $this->operations->collections();
    }

    public function search(Request $request): JsonResponse
    {
        return $this->operations->search($request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:100',
            'options' => 'array',
        ]));
    }

    public function aggregate(Request $request): JsonResponse
    {
        return $this->operations->aggregate($request->validate([
            'collections' => 'required|array',
            'collections.*' => 'string',
            'user_id' => 'nullable|integer',
        ]));
    }

    public function chat(Request $request): JsonResponse
    {
        return $this->operations->chat($request, $request->validate([
            'message' => 'required|string',
            'session_id' => 'required|string',
            'user_id' => 'nullable',
            'token' => 'nullable|string',
            'options' => 'array',
        ]));
    }

    public function executeTool(Request $request): JsonResponse
    {
        return $this->operations->executeTool($request, $request->validate([
            'action_type' => 'required|string',
            'data' => 'required|array',
            'session_id' => 'nullable|string',
            'user_id' => 'nullable',
        ]));
    }

    public function register(Request $request, NodeRegistryService $registry, NodeAuthService $authService): JsonResponse
    {
        return $this->operations->register($request->validate([
            'name' => 'required|string',
            'url' => 'required|url',
            'capabilities' => 'array',
            'metadata' => 'array',
            'version' => 'string',
        ]), $registry, $authService);
    }

    public function status(Request $request, NodeRegistryService $registry): JsonResponse
    {
        return $this->operations->status($request, $registry);
    }

    public function refreshToken(Request $request, NodeAuthService $authService): JsonResponse
    {
        return $this->operations->refreshToken($request->validate([
            'refresh_token' => 'required|string',
        ]), $authService);
    }
}
