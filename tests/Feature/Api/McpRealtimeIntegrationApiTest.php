<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\SDK\McpAppToolAdapter;
use LaravelAIEngine\Services\SDK\RealtimeSessionService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class McpRealtimeIntegrationApiTest extends TestCase
{
    public function test_mcp_tools_api_lists_and_calls_registered_tools(): void
    {
        $this->registerEchoTool();

        $this->getJson('/api/v1/ai/mcp/tools')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'echo_tool']);

        $this->postJson('/api/v1/ai/mcp/tools/echo_tool/call', [
            'arguments' => ['text' => 'hello'],
            'session_id' => 'mcp-api-session',
            'user_id' => 'user-7',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.data.text', 'hello')
            ->assertJsonPath('data.result.data.user_id', 'user-7');
    }

    public function test_realtime_tool_dispatch_api_dispatches_registered_tool(): void
    {
        $this->registerEchoTool();

        $this->postJson('/api/v1/ai/realtime/tools/dispatch', [
            'event' => [
                'id' => 'call_api_1',
                'name' => 'echo_tool',
                'arguments' => ['text' => 'live hello'],
            ],
            'session_id' => 'realtime-api-session',
            'user_id' => 'user-8',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.tool_call_id', 'call_api_1')
            ->assertJsonPath('data.result.output.data.text', 'live hello');
    }

    public function test_realtime_tool_dispatch_api_can_route_skill_tools_through_run_skill(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register('run_skill', new class extends SimpleAgentTool {
            public string $name = 'run_skill';
            public string $description = 'Fake skill runner.';
            public array $parameters = [
                'skill_id' => ['type' => 'string', 'required' => true],
                'message' => ['type' => 'string', 'required' => false],
            ];

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Skill routed.', [
                    'skill_id' => $parameters['skill_id'],
                    'message' => $parameters['message'] ?? null,
                ]);
            }
        });

        $this->postJson('/api/v1/ai/realtime/tools/dispatch', [
            'event' => [
                'id' => 'call_skill_1',
                'name' => 'skill.create_invoice',
                'arguments' => ['message' => 'Create an invoice for Ahmed'],
            ],
            'session_id' => 'realtime-api-session',
            'user_id' => 'user-8',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.output.data.skill_id', 'create_invoice')
            ->assertJsonPath('data.result.output.data.message', 'Create an invoice for Ahmed');

        $this->postJson('/api/v1/ai/realtime/tools/dispatch', [
            'event' => [
                'id' => 'call_skill_2',
                'name' => 'skill_create_invoice',
                'arguments' => ['message' => 'Create an invoice for Mona'],
            ],
            'session_id' => 'realtime-api-session',
            'user_id' => 'user-8',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.output.data.skill_id', 'create_invoice')
            ->assertJsonPath('data.result.output.data.message', 'Create an invoice for Mona');
    }

    public function test_mcp_tools_include_provider_safe_names_for_realtime_clients(): void
    {
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->once()->andReturn([
            new AgentSkillDefinition(
                id: 'create_invoice',
                name: 'Create Invoice',
                description: 'Create invoices through a multi-step flow.',
            ),
        ]);

        $adapter = new McpAppToolAdapter(app(ToolRegistry::class), $skills);
        $tools = $adapter->listTools();
        $skill = collect($tools)->firstWhere('name', 'skill.create_invoice');

        $this->assertSame('skill', $skill['metadata']['source'] ?? null);
        $this->assertSame('skill.create_invoice', $skill['metadata']['dispatch_name'] ?? null);
        $this->assertSame('skill_create_invoice', $skill['metadata']['provider_name'] ?? null);
    }

    public function test_realtime_tool_dispatch_reports_needs_user_input_without_failed_status(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register('collect_invoice_details', new class extends SimpleAgentTool {
            public string $name = 'collect_invoice_details';
            public string $description = 'Collect missing invoice details.';
            public array $parameters = [
                'customer_name' => ['type' => 'string', 'required' => true],
            ];

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::needsUserInput('Which products should I add?', [
                    'customer_name' => $parameters['customer_name'],
                ], ['required_inputs' => ['items']]);
            }
        });

        $this->postJson('/api/v1/ai/realtime/tools/dispatch', [
            'event' => [
                'id' => 'call_input_1',
                'name' => 'collect_invoice_details',
                'arguments' => ['customer_name' => 'Ahmed'],
            ],
            'session_id' => 'realtime-api-session',
            'user_id' => 'user-8',
        ])
            ->assertAccepted()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.result.status', 'needs_user_input')
            ->assertJsonPath('data.result.message', 'Which products should I add?')
            ->assertJsonPath('data.result.output.metadata.needs_user_input', true);
    }

    public function test_realtime_tool_dispatch_can_route_agent_chat_without_frontend_magic(): void
    {
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                return $args[0] === 'Create an invoice for Mohamed'
                    && $args[1] === 'realtime-api-session'
                    && $args[2] === 'openai'
                    && $args[3] === 'gpt-4o-mini'
                    && $args[8] === 'user-8';
            })
            ->andReturn(AIResponse::success('Please provide the customer email.', 'openai', 'gpt-4o-mini', [
                'needs_user_input' => true,
                'required_inputs' => ['email'],
            ]));

        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/ai/realtime/tools/dispatch', [
            'event' => [
                'id' => 'call_agent_chat_1',
                'name' => 'agent_chat',
                'arguments' => ['message' => 'Create an invoice for Mohamed'],
            ],
            'session_id' => 'realtime-api-session',
            'user_id' => 'user-8',
            'metadata' => [
                'engine' => 'openai',
                'model' => 'gpt-4o-mini',
            ],
        ])
            ->assertAccepted()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.result.status', 'needs_user_input')
            ->assertJsonPath('data.result.output.message', 'Please provide the customer email.')
            ->assertJsonPath('data.result.output.metadata.required_inputs.0', 'email');
    }

    public function test_realtime_session_api_returns_openai_voice_descriptor(): void
    {
        $response = $this->postJson('/api/v1/ai/realtime/sessions', [
            'provider' => 'openai',
            'model' => 'gpt-realtime',
            'mode' => 'voice_chat',
            'transport' => 'webrtc',
            'voice' => 'marin',
            'modalities' => ['audio'],
            'input_audio_transcription' => ['model' => 'gpt-realtime-whisper'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session.provider', 'openai')
            ->assertJsonPath('data.session.native_realtime', true)
            ->assertJsonPath('data.session.connect.recommended', 'webrtc')
            ->assertJsonPath('data.session.session.type', 'realtime')
            ->assertJsonPath('data.session.session.audio.output.voice', 'marin');

        $this->assertNull($response->json('data.session.session.audio.input.format'));
        $this->assertNull($response->json('data.session.session.audio.output.format'));
    }

    public function test_realtime_session_api_returns_livekit_descriptor(): void
    {
        config()->set('ai-engine.realtime.livekit', [
            'url' => 'wss://voice.example.test',
            'api_key' => 'lk_key',
            'api_secret' => 'lk_secret',
            'default_agent_name' => 'laravel-agent',
        ]);

        $this->postJson('/api/v1/ai/realtime/sessions', [
            'provider' => 'livekit',
            'model' => 'voice-pipeline',
            'transport' => 'livekit',
            'metadata' => [
                'room' => 'api-room',
                'participant_identity' => 'api-user',
            ],
            'fallback_pipeline' => [
                'stt' => ['provider' => 'local_audio', 'model' => 'local-whisper'],
                'chat' => ['provider' => 'ollama', 'model' => 'gemma3:4b'],
                'tts' => ['provider' => 'local_audio', 'model' => 'local-tts'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session.provider', 'livekit')
            ->assertJsonPath('data.session.native_realtime', false)
            ->assertJsonPath('data.session.connect.recommended', 'livekit')
            ->assertJsonPath('data.session.connect.livekit.room', 'api-room')
            ->assertJsonPath('data.session.pipeline.chat.provider', 'ollama');
    }

    public function test_realtime_session_api_refuses_room_outside_allow_list_with_422(): void
    {
        config()->set('ai-engine.realtime.livekit', [
            'url' => 'wss://voice.example.test',
            'api_key' => 'lk_key',
            'api_secret' => 'lk_secret',
            'allowed_rooms' => ['room-a', 'room-b'],
        ]);

        $this->postJson('/api/v1/ai/realtime/sessions', [
            'provider' => 'livekit',
            'transport' => 'livekit',
            'metadata' => [
                'room' => 'other-users-room',
                'participant_identity' => 'api-user',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.room', 'other-users-room');
    }

    public function test_realtime_session_api_can_mint_openai_client_secret(): void
    {
        config()->set('ai-engine.engines.openai.api_key', 'test-openai-key');

        Http::fake([
            'https://api.openai.com/v1/realtime/client_secrets' => Http::response([
                'value' => 'ephemeral-key',
            ]),
        ]);

        $this->postJson('/api/v1/ai/realtime/sessions', [
            'provider' => 'openai',
            'model' => 'gpt-realtime',
            'voice' => 'marin',
            'mint_client_secret' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session.client_secret.response.value', 'ephemeral-key');
    }

    public function test_realtime_sdp_api_exchanges_openai_offer(): void
    {
        config()->set('ai-engine.engines.openai.api_key', 'test-openai-key');

        app()->instance(RealtimeSessionService::class, new class extends RealtimeSessionService {
            public array $sent = [];

            protected function sendOpenAIRealtimeCall(string $url, string $apiKey, string $sdp, array $session): string
            {
                $this->sent = compact('url', 'apiKey', 'sdp', 'session');

                return 'answer-sdp';
            }
        });

        $this->postJson('/api/v1/ai/realtime/sdp', [
            'provider' => 'openai',
            'model' => 'gpt-realtime',
            'voice' => 'marin',
            'metadata' => ['session_id' => 'api-session'],
            'sdp' => 'offer-sdp',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session.sdp.answer', 'answer-sdp')
            ->assertJsonPath('data.session.session.metadata.session_id', 'api-session');

        $service = app(RealtimeSessionService::class);
        $this->assertArrayNotHasKey('metadata', $service->sent['session']);
    }

    protected function registerEchoTool(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register('echo_tool', new class extends SimpleAgentTool {
            public string $name = 'echo_tool';
            public string $description = 'Echo input for integration tests.';
            public array $parameters = [
                'text' => ['type' => 'string', 'required' => true],
            ];

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Echoed.', [
                    'text' => $parameters['text'],
                    'user_id' => $context->userId,
                ]);
            }
        });
    }
}
