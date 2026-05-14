<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Services\Actions\ActionReplyMcpEnhancer;
use LaravelAIEngine\Tests\TestCase;

class ActionReplyMcpEnhancerTest extends TestCase
{
    public function test_mcp_enhancer_calls_configured_tool_and_restores_protected_terms(): void
    {
        config()->set('ai-agent.action_reply.mcp.enabled', true);
        config()->set('ai-agent.action_reply.mcp.url', 'https://humanize.test/mcp');
        config()->set('ai-agent.action_reply.mcp.tool_name', 'humanize_text');

        Http::fake([
            'https://humanize.test/mcp' => Http::response([
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'Please ask __AI_ACTION_REPLY_TOKEN_1__ for __AI_ACTION_REPLY_TOKEN_0__.'],
                    ],
                ],
            ]),
        ]);

        $result = (new ActionReplyMcpEnhancer())(
            'ask Smoke Customer for smoke.customer@example.test',
            [
                'style' => 'action_reply',
                'preserve_terms' => ['Smoke Customer', 'smoke.customer@example.test'],
            ]
        );

        $this->assertIsArray($result);
        $this->assertSame('mcp', $result['provider']);
        $this->assertSame('Please ask Smoke Customer for smoke.customer@example.test.', $result['text']);
        $this->assertSame(2, $result['metadata']['protected_terms_count']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://humanize.test/mcp'
                && $payload['method'] === 'tools/call'
                && $payload['params']['name'] === 'humanize_text'
                && str_contains($payload['params']['arguments']['text'], '__AI_ACTION_REPLY_TOKEN_');
        });
    }

    public function test_mcp_enhancer_returns_null_when_not_configured(): void
    {
        config()->set('ai-agent.action_reply.mcp.enabled', false);
        config()->set('ai-agent.action_reply.mcp.url', null);

        $this->assertNull((new ActionReplyMcpEnhancer())('hello', []));
    }
}
