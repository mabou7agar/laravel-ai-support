<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Http\Controllers\Api\AgentChatApiController;
use LaravelAIEngine\Http\Controllers\Api\AgentConversationApiController;
use LaravelAIEngine\Http\Controllers\Api\FileAnalysisApiController;
use LaravelAIEngine\Http\Controllers\Api\HealthApiController;
use LaravelAIEngine\Http\Controllers\Api\VectorStoreApiController;
use LaravelAIEngine\Tests\TestCase;

class CapabilityApiRouteCleanupTest extends TestCase
{
    public function test_rag_controller_and_rag_api_routes_are_removed(): void
    {
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Controllers\\Api\\RagChatApiController'));
        $this->assertFalse(class_exists('LaravelAIEngine\\Http\\Controllers\\Api\\RagApiController'));

        $this->postJson('/api/v1/rag/analyze-file')->assertNotFound();
        $this->getJson('/api/v1/rag/collections')->assertNotFound();
        $this->getJson('/api/v1/rag/engines')->assertNotFound();
        $this->getJson('/api/v1/rag/health')->assertNotFound();
        $this->getJson('/api/v1/rag/conversations')->assertNotFound();
    }

    public function test_capability_routes_use_focused_controllers(): void
    {
        $this->assertSame(AgentChatApiController::class, $this->routeController('ai-engine.agent.api.chat.send'));
        $this->assertSame(AgentConversationApiController::class, $this->routeController('ai-engine.agent.api.conversations.list'));
        $this->assertSame(FileAnalysisApiController::class, $this->routeController('ai-engine.files.api.analyze'));
        $this->assertSame(VectorStoreApiController::class, $this->routeController('ai-engine.vector-stores.api.collections'));
        $this->assertSame(HealthApiController::class, $this->routeController('ai-engine.health.api.show'));
    }

    public function test_capability_endpoints_respond_without_rag_url_prefix(): void
    {
        $this->getJson('/api/v1/ai/health')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/ai/vector-stores/collections')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    protected function routeController(string $name): ?string
    {
        $action = app('router')->getRoutes()->getByName($name)?->getActionName();

        return is_string($action) && str_contains($action, '@')
            ? explode('@', $action, 2)[0]
            : null;
    }
}
