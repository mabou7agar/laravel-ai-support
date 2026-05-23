# Durable Agent Chat Realtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire chat to durable queued agent runs and expose realtime progress through polling, SSE, and driver-agnostic Laravel Broadcasting for Reverb, Pusher, Soketi, Ably, or any Laravel broadcast driver.

**Architecture:** Keep `ChatService` as the synchronous default for normal chat. Add an async chat branch in the API controller that creates an `ai_agent_runs` record, dispatches `RunAgentJob`, returns the run id plus inspection/stream URLs, and resumes through the existing `ContinueAgentRunJob` flow. Use persisted run events as the source of truth, add SSE as a lightweight server-to-browser stream, and make `AgentRunStreamed` broadcastable through Laravel's broadcasting abstraction without depending on Reverb.

**Tech Stack:** Laravel package services, Form Requests, DTOs, repositories, queued jobs, Eloquent run/step models, Symfony streamed responses, Laravel events/broadcasting, PHPUnit, Bruno API collection, MDX docs.

---

## Implementation Status

- [x] `async` is carried from `SendMessageRequest` into `SendMessageDTO`.
- [x] `POST /api/v1/agent/chat` can create a durable queued agent run and return `202 Accepted`.
- [x] `RunAgentJob` is dispatched through `AgentChatRunService` with tenant/workspace scope preserved in options.
- [x] `GET /api/v1/ai/agent-runs/{run}/stream` streams persisted fallback events as SSE.
- [x] `AgentRunStreamed` broadcasts through Laravel Broadcasting using configurable public/private channels.
- [x] Docs and Bruno examples cover async chat, SSE, and broadcast usage.
- [x] Focused async chat, SSE, and broadcast tests pass.

---

## Current Package Features To Reuse

- `src/Jobs/RunAgentJob.php` already creates an `agent_run` step, calls `AgentRuntimeContract::process()`, persists the response, and transitions the run to `completed`, `waiting_input`, `waiting_approval`, or `failed`.
- `src/Jobs/ContinueAgentRunJob.php` already resumes a persisted run by reusing its `session_id` and `user_id`.
- `src/Models/AIAgentRun.php` and `src/Models/AIAgentRunStep.php` already store durable status, metadata, trace ids, and step output.
- `src/Repositories/AgentRunRepository.php` and `src/Repositories/AgentRunStepRepository.php` already enforce run/step creation and status transitions.
- `src/Services/Agent/AgentRunEventStreamService.php` already emits run events and persists them into run/step metadata.
- `src/Events/AgentRunStreamed.php` already exists as the package event object but is not broadcastable yet.
- `src/Http/Controllers/Api/AgentRunController.php` already exposes list, show, trace, resume, cancel, and capabilities.
- `src/Http/Requests/SendMessageRequest.php` already validates `async`, but `SendMessageDTO` does not carry it and `AgentChatApiController` does not branch on it.
- `bruno/laravel-ai-engine/V1 Agent Runs` already has run inspection/resume/cancel examples.

Do not create another orchestration engine. This plan only wires existing durable run infrastructure into chat and realtime delivery.

## File Structure

- Modify: `src/DTOs/SendMessageDTO.php` to carry `async` and expose it in `toArray()`.
- Modify: `src/Http/Requests/SendMessageRequest.php` to pass `async` into `SendMessageDTO`.
- Create: `src/Services/Agent/AgentChatRunService.php` to create runs, dispatch `RunAgentJob`, and build API payloads.
- Modify: `src/Http/Controllers/Api/AgentChatApiController.php` to route async chat through `AgentChatRunService`.
- Create: `src/Services/Agent/AgentRunSseStreamService.php` to stream persisted run events.
- Modify: `src/Http/Controllers/Api/AgentRunController.php` to add `stream()`.
- Create: `src/Http/Requests/StreamAgentRunRequest.php` for SSE query validation.
- Modify: `routes/api.php` to add `GET /api/v1/ai/agent-runs/{run}/stream`.
- Modify: `src/Events/AgentRunStreamed.php` to implement Laravel broadcasting interfaces conditionally.
- Modify: `config/ai-agent.php` to add chat async, SSE, and broadcast settings under existing `event_stream`.
- Modify: `src/Support/Providers/AgentServiceRegistrar.php` if explicit singleton registration is needed for new services.
- Create: `tests/Feature/Api/AgentChatAsyncRunApiTest.php`.
- Create: `tests/Feature/Api/AgentRunSseStreamApiTest.php`.
- Create: `tests/Unit/Events/AgentRunStreamedBroadcastTest.php`.
- Modify: `tests/Feature/Api/AgentRunApiTest.php` only if route assertions need coverage for stream URL.
- Modify: `docs/orchestration-v2.mdx`.
- Modify: `docs/realtime-observability.mdx`.
- Modify: `docs/api-reference.mdx`.
- Modify: `docs/quickstart-chat-actions.mdx`.
- Modify: `README.md`.
- Create: `bruno/laravel-ai-engine/V1 Agent/05 Async Chat.bru`.
- Create: `bruno/laravel-ai-engine/V1 Agent Runs/07 Stream Run.bru`.

## API Contract

Async chat request:

```http
POST /api/v1/agent/chat
Content-Type: application/json

{
  "message": "Create invoice and generate preview",
  "session_id": "s1",
  "user_id": "user-1",
  "engine": "openai",
  "model": "gpt-4o-mini",
  "async": true,
  "memory": true,
  "actions": true,
  "use_rag": true,
  "response_points_format": "array",
  "response_suggestions": true
}
```

Async chat response:

```json
{
  "success": true,
  "data": {
    "queued": true,
    "run": {
      "uuid": "run-uuid",
      "status": "pending",
      "session_id": "s1"
    },
    "agent_run_id": "run-uuid",
    "status_url": "/api/v1/ai/agent-runs/run-uuid",
    "trace_url": "/api/v1/ai/agent-runs/run-uuid/trace",
    "stream_url": "/api/v1/ai/agent-runs/run-uuid/stream",
    "broadcast": {
      "enabled": false,
      "driver": null,
      "channel": "private-agent-run.run-uuid",
      "events": [
        "run.started",
        "tool.started",
        "approval.required",
        "artifact.created",
        "run.completed",
        "run.failed"
      ]
    }
  }
}
```

SSE endpoint:

```http
GET /api/v1/ai/agent-runs/{run}/stream?timeout=30&poll=500
Accept: text/event-stream
```

Example SSE frame:

```text
event: run.completed
id: 018f1b09-9f85-7bc2-bc52-c3b6f2b62f1d
data: {"name":"run.completed","run_id":"run-uuid","payload":{"success":true}}

```

## Configuration Contract

Add these keys to `config/ai-agent.php`:

```php
'chat' => [
    'async_enabled' => env('AI_AGENT_CHAT_ASYNC_ENABLED', true),
    'async_default' => env('AI_AGENT_CHAT_ASYNC_DEFAULT', false),
],

'event_stream' => [
    'persisted_events_limit' => (int) env('AI_AGENT_EVENT_STREAM_PERSISTED_LIMIT', 200),
    'sse' => [
        'enabled' => env('AI_AGENT_EVENT_STREAM_SSE_ENABLED', true),
        'max_seconds' => (int) env('AI_AGENT_EVENT_STREAM_SSE_MAX_SECONDS', 30),
        'poll_milliseconds' => (int) env('AI_AGENT_EVENT_STREAM_SSE_POLL_MS', 500),
        'heartbeat_seconds' => (int) env('AI_AGENT_EVENT_STREAM_SSE_HEARTBEAT_SECONDS', 10),
    ],
    'broadcast' => [
        'enabled' => env('AI_AGENT_EVENT_STREAM_BROADCAST_ENABLED', false),
        'connection' => env('AI_AGENT_EVENT_STREAM_BROADCAST_CONNECTION'),
        'queue' => env('AI_AGENT_EVENT_STREAM_BROADCAST_QUEUE', 'ai-agent-events'),
        'private' => env('AI_AGENT_EVENT_STREAM_BROADCAST_PRIVATE', true),
        'channel_prefix' => env('AI_AGENT_EVENT_STREAM_BROADCAST_CHANNEL_PREFIX', 'agent-run'),
    ],
],
```

If `event_stream` already exists, merge these settings into the existing array without deleting existing keys.

## Task 1: Carry `async` From Request To DTO

**Files:**
- Modify: `src/DTOs/SendMessageDTO.php`
- Modify: `src/Http/Requests/SendMessageRequest.php`
- Test: `tests/Feature/Api/AgentChatAsyncRunApiTest.php`

- [ ] **Step 1: Write the failing DTO/API forwarding test**

Create `tests/Feature/Api/AgentChatAsyncRunApiTest.php` with the first test proving `async=true` reaches the controller branch by mocking the new service after Task 2 defines it. Start with this expected shape:

```php
<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Services\Agent\AgentChatRunService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentChatAsyncRunApiTest extends TestCase
{
    public function test_agent_chat_api_queues_durable_run_when_async_is_true(): void
    {
        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldReceive('start')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool =>
                ($payload['message'] ?? null) === 'Create invoice from this conversation'
                && ($payload['session_id'] ?? null) === 'async-chat-api'
                && ($payload['user_id'] ?? null) === 'user-async'
                && ($payload['options']['engine'] ?? null) === 'openai'
                && ($payload['options']['model'] ?? null) === 'gpt-4o-mini'
                && ($payload['options']['use_rag'] ?? null) === false
            ))
            ->andReturn([
                'queued' => true,
                'run' => [
                    'uuid' => 'run-async-1',
                    'status' => 'pending',
                    'session_id' => 'async-chat-api',
                ],
                'agent_run_id' => 'run-async-1',
                'status_url' => '/api/v1/ai/agent-runs/run-async-1',
                'trace_url' => '/api/v1/ai/agent-runs/run-async-1/trace',
                'stream_url' => '/api/v1/ai/agent-runs/run-async-1/stream',
                'broadcast' => ['enabled' => false],
            ]);

        $this->app->instance(AgentChatRunService::class, $service);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Create invoice from this conversation',
            'session_id' => 'async-chat-api',
            'user_id' => 'user-async',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'memory' => false,
            'actions' => true,
            'use_rag' => false,
            'async' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.agent_run_id', 'run-async-1')
            ->assertJsonPath('data.stream_url', '/api/v1/ai/agent-runs/run-async-1/stream');
    }
}
```

- [ ] **Step 2: Run the failing test**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Feature/Api/AgentChatAsyncRunApiTest.php --display-errors --display-warnings
```

Expected: failure because `AgentChatRunService` does not exist and the controller does not branch on `async`.

- [ ] **Step 3: Update the DTO**

Modify `src/DTOs/SendMessageDTO.php`:

```php
public function __construct(
    public readonly string $message,
    public readonly string $sessionId,
    public readonly string $engine = 'openai',
    public readonly string $model = 'gpt-4o',
    public readonly bool $memory = true,
    public readonly bool $actions = true,
    public readonly bool $streaming = false,
    public readonly bool $async = false,
    public readonly ?string $userId = null,
    public readonly bool $intelligentRag = false,
    public readonly bool $forceRag = false,
    public readonly ?array $ragCollections = null,
    public readonly ?string $searchInstructions = null,
    public readonly bool $agentGoal = false,
    public readonly ?string $target = null,
    public readonly ?array $subAgents = null,
    public readonly ?array $goalAgent = null,
    public readonly ?string $responsePointsFormat = null,
    public readonly ?bool $responseSuggestions = null,
    public readonly ?int $responseSuggestionLimit = null,
    public readonly ?array $collection = null
) {}
```

Add `'async' => $this->async` to `toArray()`.

- [ ] **Step 4: Update the request mapper**

Modify `src/Http/Requests/SendMessageRequest.php` in `toDTO()`:

```php
streaming: $validated['streaming'] ?? false,
async: $validated['async'] ?? false,
userId: $validated['user_id'] ?? auth()->user()?->getAuthIdentifier(),
```

- [ ] **Step 5: Do not break existing sync API tests**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Feature/Api/AgentChatResponsePresentationApiTest.php --display-errors --display-warnings
```

Expected: all existing chat API tests still pass.

## Task 2: Add `AgentChatRunService`

**Files:**
- Create: `src/Services/Agent/AgentChatRunService.php`
- Modify: `src/Support/Providers/AgentServiceRegistrar.php` only if the service is not auto-resolved cleanly.
- Test: `tests/Feature/Api/AgentChatAsyncRunApiTest.php`

- [ ] **Step 1: Add a failing service integration test**

Append this test to `AgentChatAsyncRunApiTest`:

```php
public function test_agent_chat_run_service_creates_run_and_dispatches_job(): void
{
    \Illuminate\Support\Facades\Queue::fake();

    $result = app(\LaravelAIEngine\Services\Agent\AgentChatRunService::class)->start([
        'message' => 'Create invoice and generate preview',
        'session_id' => 'async-service',
        'user_id' => 'user-service',
        'options' => [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'memory' => true,
            'actions' => true,
            'use_rag' => false,
            'tenant_id' => 'tenant-service',
            'workspace_id' => 'workspace-service',
        ],
    ]);

    $this->assertTrue($result['queued']);
    $this->assertSame('pending', $result['run']['status']);
    $this->assertSame('async-service', $result['run']['session_id']);
    $this->assertStringContainsString('/api/v1/ai/agent-runs/', $result['status_url']);
    $this->assertStringEndsWith('/stream', $result['stream_url']);
    $this->assertDatabaseHas('ai_agent_runs', [
        'uuid' => $result['run']['uuid'],
        'session_id' => 'async-service',
        'user_id' => 'user-service',
        'tenant_id' => 'tenant-service',
        'workspace_id' => 'workspace-service',
        'status' => 'pending',
    ]);

    \Illuminate\Support\Facades\Queue::assertPushed(\LaravelAIEngine\Jobs\RunAgentJob::class);
}
```

- [ ] **Step 2: Run the failing test**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Feature/Api/AgentChatAsyncRunApiTest.php --display-errors --display-warnings
```

Expected: failure because `AgentChatRunService` does not exist.

- [ ] **Step 3: Create the service**

Create `src/Services/Agent/AgentChatRunService.php`:

```php
<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\URL;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;

class AgentChatRunService
{
    public function __construct(
        private readonly AgentRunRepository $runs
    ) {}

    public function start(array $payload): array
    {
        if (!(bool) config('ai-agent.chat.async_enabled', true)) {
            throw new \RuntimeException('Async agent chat is disabled.');
        }

        $message = trim((string) ($payload['message'] ?? ''));
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        if ($message === '' || $sessionId === '') {
            throw new \InvalidArgumentException('Async agent chat requires message and session_id.');
        }

        $options = (array) ($payload['options'] ?? []);
        $userId = $payload['user_id'] ?? null;
        $scope = $this->scope($options);

        $run = $this->runs->create([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'runtime' => (string) ($options['agent_runtime'] ?? config('ai-agent.runtime.default', 'laravel')),
            'status' => AIAgentRun::STATUS_PENDING,
            'metadata' => array_filter([
                'created_from' => 'agent_chat',
                'estimated_tokens' => $options['estimated_tokens'] ?? null,
                'estimated_cost' => $options['estimated_cost'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);

        RunAgentJob::dispatch(
            $run->id,
            $message,
            $sessionId,
            $userId,
            array_merge($options, [
                'tenant_id' => $scope['tenant_id'],
                'workspace_id' => $scope['workspace_id'],
                '_idempotency_key' => $options['_idempotency_key'] ?? 'agent-chat:' . $run->uuid,
            ])
        );

        return $this->payload($run->refresh());
    }

    private function scope(array $options): array
    {
        return [
            'tenant_id' => $options['tenant_id'] ?? $options['scope']['tenant_id'] ?? null,
            'workspace_id' => $options['workspace_id'] ?? $options['scope']['workspace_id'] ?? null,
        ];
    }

    private function payload(AIAgentRun $run): array
    {
        $base = '/api/v1/ai/agent-runs/' . $run->uuid;

        return [
            'queued' => true,
            'run' => $run->toArray(),
            'agent_run_id' => $run->uuid,
            'status_url' => $base,
            'trace_url' => $base . '/trace',
            'stream_url' => $base . '/stream',
            'broadcast' => [
                'enabled' => (bool) config('ai-agent.event_stream.broadcast.enabled', false),
                'driver' => config('broadcasting.default'),
                'channel' => $this->broadcastChannel($run),
                'events' => app(AgentRunEventStreamService::class)->names(),
            ],
        ];
    }

    private function broadcastChannel(AIAgentRun $run): string
    {
        $prefix = trim((string) config('ai-agent.event_stream.broadcast.channel_prefix', 'agent-run'), '.');
        $private = (bool) config('ai-agent.event_stream.broadcast.private', true);

        return ($private ? 'private-' : '') . $prefix . '.' . $run->uuid;
    }
}
```

- [ ] **Step 4: Run the service test**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Feature/Api/AgentChatAsyncRunApiTest.php --display-errors --display-warnings
```

Expected: the service test passes after Task 3 wires the controller.

## Task 3: Wire Async Chat In `AgentChatApiController`

**Files:**
- Modify: `src/Http/Controllers/Api/AgentChatApiController.php`
- Test: `tests/Feature/Api/AgentChatAsyncRunApiTest.php`

- [ ] **Step 1: Inject `AgentChatRunService`**

Modify the controller constructor:

```php
use LaravelAIEngine\Services\Agent\AgentChatRunService;

public function __construct(
    protected ChatService $chatService,
    protected RAGCollectionDiscovery $ragDiscovery,
    protected AgentChatRunService $agentRuns,
) {}
```

- [ ] **Step 2: Build the async branch before sync `ChatService` call**

Inside `sendMessage()`, after `$dto` and `$ragCollections` are resolved:

```php
if ($dto->async || (bool) config('ai-agent.chat.async_default', false)) {
    $payload = $this->agentRuns->start([
        'message' => $dto->message,
        'session_id' => $dto->sessionId,
        'user_id' => $dto->userId,
        'options' => array_merge($dto->agentOptions(), [
            'engine' => $dto->engine,
            'model' => $dto->model,
            'memory' => $dto->memory,
            'actions' => $dto->actions,
            'use_memory' => $dto->memory,
            'use_actions' => $dto->actions,
            'use_rag' => $request->boolean('use_rag', true),
            'rag_collections' => $ragCollections,
            'search_instructions' => $dto->searchInstructions,
        ]),
    ]);

    return response()->json([
        'success' => true,
        'data' => $payload,
    ], 202);
}
```

Keep the existing synchronous path unchanged.

- [ ] **Step 3: Run async and sync API tests**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Feature/Api/AgentChatAsyncRunApiTest.php tests/Feature/Api/AgentChatResponsePresentationApiTest.php --display-errors --display-warnings
```

Expected: async chat returns `202`; existing sync chat tests still return `200`.

## Task 4: Add SSE Run Stream Endpoint

**Files:**
- Create: `src/Http/Requests/StreamAgentRunRequest.php`
- Create: `src/Services/Agent/AgentRunSseStreamService.php`
- Modify: `src/Http/Controllers/Api/AgentRunController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/AgentRunSseStreamApiTest.php`

- [ ] **Step 1: Write the failing SSE API test**

Create `tests/Feature/Api/AgentRunSseStreamApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Tests\TestCase;

class AgentRunSseStreamApiTest extends TestCase
{
    public function test_agent_run_stream_endpoint_outputs_sse_events(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', true);
        config()->set('ai-agent.event_stream.sse.max_seconds', 1);
        config()->set('ai-agent.event_stream.sse.poll_milliseconds', 10);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sse-run',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);

        app(AgentRunEventStreamService::class)->emit(
            AgentRunEventStreamService::RUN_COMPLETED,
            $run,
            null,
            ['message' => 'Done']
        );

        $response = $this->get("/api/v1/ai/agent-runs/{$run->uuid}/stream?timeout=1&poll=10");

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', $response->headers->get('content-type'));
        $this->assertStringContainsString('event: run.completed', $response->streamedContent());
        $this->assertStringContainsString('"message":"Done"', $response->streamedContent());
    }

    public function test_agent_run_stream_endpoint_can_be_disabled(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', false);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sse-disabled',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $this->get("/api/v1/ai/agent-runs/{$run->uuid}/stream")
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Create the request**

Create `src/Http/Requests/StreamAgentRunRequest.php`:

```php
<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StreamAgentRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'poll' => ['nullable', 'integer', 'min:100', 'max:5000'],
            'last_event_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
```

- [ ] **Step 3: Create the SSE service**

Create `src/Services/Agent/AgentRunSseStreamService.php`:

```php
<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Symfony\Component\HttpFoundation\StreamedResponse;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;

class AgentRunSseStreamService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRunEventStreamService $events
    ) {}

    public function response(int|string $runId, array $options = []): StreamedResponse
    {
        if (!(bool) config('ai-agent.event_stream.sse.enabled', true)) {
            abort(404);
        }

        $run = $this->runs->findOrFail($runId);
        $timeout = min((int) ($options['timeout'] ?? config('ai-agent.event_stream.sse.max_seconds', 30)), 120);
        $pollMs = min(max((int) ($options['poll'] ?? config('ai-agent.event_stream.sse.poll_milliseconds', 500)), 100), 5000);
        $lastEventId = (string) ($options['last_event_id'] ?? '');

        return response()->stream(function () use ($run, $timeout, $pollMs, $lastEventId): void {
            $sent = $lastEventId !== '' ? [$lastEventId => true] : [];
            $deadline = microtime(true) + $timeout;

            while (microtime(true) <= $deadline) {
                $record = $this->runs->findOrFail($run->id);
                foreach ($this->events->fallbackEvents($record) as $event) {
                    $id = (string) ($event['id'] ?? '');
                    if ($id !== '' && isset($sent[$id])) {
                        continue;
                    }

                    echo $this->frame($event);
                    if ($id !== '') {
                        $sent[$id] = true;
                    }
                }

                if ($record->isTerminal()) {
                    break;
                }

                echo ": heartbeat\n\n";
                @ob_flush();
                flush();
                usleep($pollMs * 1000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function frame(array $event): string
    {
        return implode("\n", array_filter([
            'event: ' . (string) ($event['name'] ?? 'message'),
            isset($event['id']) ? 'id: ' . (string) $event['id'] : null,
            'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES),
        ], static fn (?string $line): bool => $line !== null)) . "\n\n";
    }
}
```

- [ ] **Step 4: Wire controller and route**

Modify `AgentRunController` constructor to inject `AgentRunSseStreamService`, and add:

```php
public function stream(string $run, StreamAgentRunRequest $request): \Symfony\Component\HttpFoundation\StreamedResponse
{
    return $this->stream->response($run, $request->validated());
}
```

Modify `routes/api.php` inside the `api/v1/ai/agent-runs` group:

```php
Route::get('/{run}/stream', [AgentRunController::class, 'stream'])
    ->name('runs.stream');
```

Place it before `/{run}` if route matching requires it.

- [ ] **Step 5: Run SSE tests**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Feature/Api/AgentRunSseStreamApiTest.php --display-errors --display-warnings
```

Expected: SSE endpoint emits persisted events and can be disabled.

## Task 5: Make Agent Run Events Broadcastable For Pusher/Reverb

**Files:**
- Modify: `src/Events/AgentRunStreamed.php`
- Test: `tests/Unit/Events/AgentRunStreamedBroadcastTest.php`

- [ ] **Step 1: Write the failing broadcast event test**

Create `tests/Unit/Events/AgentRunStreamedBroadcastTest.php`:

```php
<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Events;

use Illuminate\Broadcasting\PrivateChannel;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentRunStreamedBroadcastTest extends UnitTestCase
{
    public function test_agent_run_streamed_uses_configured_private_broadcast_channel(): void
    {
        config()->set('ai-agent.event_stream.broadcast.enabled', true);
        config()->set('ai-agent.event_stream.broadcast.private', true);
        config()->set('ai-agent.event_stream.broadcast.channel_prefix', 'agent-run');
        config()->set('ai-agent.event_stream.broadcast.queue', 'ai-agent-events');

        $event = new AgentRunStreamed([
            'id' => 'event-1',
            'name' => 'run.completed',
            'run_id' => 'run-uuid',
            'payload' => ['ok' => true],
        ]);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-agent-run.run-uuid', (string) $channels[0]);
        $this->assertSame('run.completed', $event->broadcastAs());
        $this->assertSame('ai-agent-events', $event->broadcastQueue());
        $this->assertTrue($event->broadcastWhen());
        $this->assertSame('event-1', $event->broadcastWith()['id']);
    }

    public function test_agent_run_streamed_does_not_broadcast_when_disabled(): void
    {
        config()->set('ai-agent.event_stream.broadcast.enabled', false);

        $event = new AgentRunStreamed([
            'name' => 'run.completed',
            'run_id' => 'run-uuid',
        ]);

        $this->assertFalse($event->broadcastWhen());
    }
}
```

- [ ] **Step 2: Implement broadcast methods**

Modify `src/Events/AgentRunStreamed.php`:

```php
<?php

declare(strict_types=1);

namespace LaravelAIEngine\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class AgentRunStreamed implements ShouldBroadcastNow
{
    public function __construct(
        public readonly array $event
    ) {}

    public function broadcastOn(): array
    {
        $runId = trim((string) ($this->event['run_id'] ?? ''));
        if ($runId === '') {
            return [];
        }

        $prefix = trim((string) config('ai-agent.event_stream.broadcast.channel_prefix', 'agent-run'), '.');
        $channel = $prefix . '.' . $runId;

        return [(bool) config('ai-agent.event_stream.broadcast.private', true)
            ? new PrivateChannel($channel)
            : new Channel($channel)];
    }

    public function broadcastAs(): string
    {
        return (string) ($this->event['name'] ?? 'agent.run.event');
    }

    public function broadcastWith(): array
    {
        return $this->event;
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('ai-agent.event_stream.broadcast.enabled', false)
            && trim((string) ($this->event['run_id'] ?? '')) !== '';
    }

    public function broadcastQueue(): string
    {
        return (string) config('ai-agent.event_stream.broadcast.queue', 'ai-agent-events');
    }
}
```

- [ ] **Step 3: Run broadcast tests**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Events/AgentRunStreamedBroadcastTest.php --display-errors --display-warnings
```

Expected: broadcast methods are driver-agnostic and pass without Reverb or Pusher installed.

## Task 6: Add Config Defaults Safely

**Files:**
- Modify: `config/ai-agent.php`
- Test: `tests/Unit/Services/Agent/AgentRuntimeConfigValidatorTest.php` if it validates config shape.
- Test: `tests/Feature/Api/AgentChatAsyncRunApiTest.php`
- Test: `tests/Feature/Api/AgentRunSseStreamApiTest.php`
- Test: `tests/Unit/Events/AgentRunStreamedBroadcastTest.php`

- [ ] **Step 1: Add config without using `env()` outside config**

Edit `config/ai-agent.php`, merging the configuration contract above into existing `chat` and `event_stream` sections. Runtime code must read only `config(...)`, never `env(...)`.

- [ ] **Step 2: Verify package can boot with defaults**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/AgentRuntimeConfigValidatorTest.php tests/Feature/Api/AgentChatAsyncRunApiTest.php tests/Feature/Api/AgentRunSseStreamApiTest.php tests/Unit/Events/AgentRunStreamedBroadcastTest.php --display-errors --display-warnings
```

Expected: all tests pass with no Reverb or Pusher credentials configured.

## Task 7: Add Bruno API Requests

**Files:**
- Create: `bruno/laravel-ai-engine/V1 Agent/05 Async Chat.bru`
- Create: `bruno/laravel-ai-engine/V1 Agent Runs/07 Stream Run.bru`

- [ ] **Step 1: Inspect existing Bruno style**

Open:

```bash
sed -n '1,140p' 'bruno/laravel-ai-engine/V1 Agent/01 Chat.bru'
sed -n '1,140p' 'bruno/laravel-ai-engine/V1 Agent Runs/02 Show Run.bru'
```

- [ ] **Step 2: Add async chat request**

Create `bruno/laravel-ai-engine/V1 Agent/05 Async Chat.bru` following the existing Bruno format. Use:

```json
{
  "message": "Create invoice and generate preview from this conversation",
  "session_id": "bruno-async-agent",
  "user_id": "1",
  "engine": "openai",
  "model": "gpt-4o-mini",
  "memory": true,
  "actions": true,
  "use_rag": false,
  "async": true
}
```

- [ ] **Step 3: Add SSE request**

Create `bruno/laravel-ai-engine/V1 Agent Runs/07 Stream Run.bru` with URL:

```text
{{base_url}}/api/v1/ai/agent-runs/{{agent_run_id}}/stream?timeout=30&poll=500
```

Use header:

```text
Accept: text/event-stream
```

- [ ] **Step 4: Run curl smoke checks against a local app**

Run after implementation:

```bash
curl -sS -X POST "$APP_URL/api/v1/agent/chat" \
  -H 'Content-Type: application/json' \
  -d '{"message":"Create invoice and generate preview","session_id":"curl-async-agent","async":true,"use_rag":false}' | jq .
```

Expected: response includes `data.agent_run_id`, `data.status_url`, `data.stream_url`.

Then run:

```bash
curl -N "$APP_URL/api/v1/ai/agent-runs/$RUN_ID/stream?timeout=5&poll=500"
```

Expected: SSE frames use `event:` and `data:` lines.

## Task 8: Documentation

**Files:**
- Modify: `docs/orchestration-v2.mdx`
- Modify: `docs/realtime-observability.mdx`
- Modify: `docs/api-reference.mdx`
- Modify: `docs/quickstart-chat-actions.mdx`
- Modify: `README.md`

- [ ] **Step 1: Document async chat lifecycle**

In `docs/orchestration-v2.mdx`, add a section named `Durable Async Chat` with:

```mdx
Async chat is opt-in through `async: true`. The chat endpoint creates an `ai_agent_runs` row and queues `RunAgentJob`; the normal synchronous chat path is unchanged.

Use:
- `GET /api/v1/ai/agent-runs/{run}` for current status
- `GET /api/v1/ai/agent-runs/{run}/trace` for step trace
- `GET /api/v1/ai/agent-runs/{run}/stream` for SSE progress
- `POST /api/v1/ai/agent-runs/{run}/resume` to continue from `waiting_input` or `waiting_approval`
```

- [ ] **Step 2: Document SSE, Reverb, and Pusher**

In `docs/realtime-observability.mdx`, add:

```mdx
Realtime support is layered:

1. Polling: always available.
2. SSE: package-owned HTTP event stream for one-way progress.
3. Laravel Broadcasting: optional production realtime through Reverb, Pusher, Soketi, Ably, or any compatible driver.

The package does not require Reverb. It emits Laravel broadcast events; the host app chooses the broadcast driver.
```

Add frontend examples:

```js
const stream = new EventSource(`/api/v1/ai/agent-runs/${runUuid}/stream`);
stream.addEventListener('run.completed', event => {
  console.log(JSON.parse(event.data));
  stream.close();
});
```

```js
Echo.private(`agent-run.${runUuid}`)
  .listen('.run.started', onAgentEvent)
  .listen('.approval.required', onAgentEvent)
  .listen('.run.completed', onAgentEvent);
```

- [ ] **Step 3: Add environment examples**

Document Pusher:

```env
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_HOST=...
PUSHER_PORT=443
PUSHER_SCHEME=https
AI_AGENT_EVENT_STREAM_BROADCAST_ENABLED=true
```

Document Reverb:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=...
REVERB_PORT=443
REVERB_SCHEME=https
AI_AGENT_EVENT_STREAM_BROADCAST_ENABLED=true
```

- [ ] **Step 4: Update API reference and README**

Add async chat response fields and the SSE endpoint to `docs/api-reference.mdx`. Add a short quickstart snippet to `README.md`.

- [ ] **Step 5: Run docs tests**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Documentation/DocsSiteIntegrityTest.php tests/Unit/Documentation/DocsCoverageMatrixTest.php --display-errors --display-warnings
```

Expected: docs tests pass.

## Task 9: End-To-End Verification

**Files:**
- Test: `tests/Feature/Api/AgentChatAsyncRunApiTest.php`
- Test: `tests/Feature/Api/AgentRunSseStreamApiTest.php`
- Test: `tests/Feature/AgentRunAsyncJobsTest.php`
- Test: `tests/Feature/Api/AgentRunApiTest.php`
- Test: `tests/Feature/AgentRunApprovalLifecycleTest.php`
- Test: `tests/Unit/Events/AgentRunStreamedBroadcastTest.php`

- [ ] **Step 1: Run focused lifecycle tests**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist \
  tests/Feature/Api/AgentChatAsyncRunApiTest.php \
  tests/Feature/Api/AgentRunSseStreamApiTest.php \
  tests/Feature/AgentRunAsyncJobsTest.php \
  tests/Feature/Api/AgentRunApiTest.php \
  tests/Feature/AgentRunApprovalLifecycleTest.php \
  tests/Unit/Events/AgentRunStreamedBroadcastTest.php \
  --display-errors --display-warnings
```

Expected: all focused lifecycle tests pass.

- [ ] **Step 2: Run package unit suite**

Run:

```bash
'/Users/abou7agar/Library/Application Support/Herd/bin/php' vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit --display-errors --display-warnings
```

Expected: Unit suite passes.

- [ ] **Step 3: Run live/manual smoke against local app**

Start a local app server if needed, then run:

```bash
APP_URL=http://127.0.0.1:8000
ASYNC_RESPONSE=$(curl -sS -X POST "$APP_URL/api/v1/agent/chat" \
  -H 'Content-Type: application/json' \
  -d '{"message":"Create invoice and generate preview","session_id":"manual-async-agent","async":true,"use_rag":false}')
echo "$ASYNC_RESPONSE" | jq .
RUN_ID=$(echo "$ASYNC_RESPONSE" | jq -r '.data.agent_run_id')
curl -sS "$APP_URL/api/v1/ai/agent-runs/$RUN_ID" | jq .
curl -N "$APP_URL/api/v1/ai/agent-runs/$RUN_ID/stream?timeout=5&poll=500"
```

Expected: async chat returns a run id; run inspection returns the same run; SSE returns frames.

- [ ] **Step 4: Commit after verification**

Only commit after tests and smoke checks pass:

```bash
git add src/DTOs/SendMessageDTO.php \
  src/Http/Requests/SendMessageRequest.php \
  src/Services/Agent/AgentChatRunService.php \
  src/Services/Agent/AgentRunSseStreamService.php \
  src/Http/Controllers/Api/AgentChatApiController.php \
  src/Http/Controllers/Api/AgentRunController.php \
  src/Http/Requests/StreamAgentRunRequest.php \
  src/Events/AgentRunStreamed.php \
  config/ai-agent.php \
  routes/api.php \
  tests/Feature/Api/AgentChatAsyncRunApiTest.php \
  tests/Feature/Api/AgentRunSseStreamApiTest.php \
  tests/Unit/Events/AgentRunStreamedBroadcastTest.php \
  bruno/laravel-ai-engine/V1\ Agent/05\ Async\ Chat.bru \
  bruno/laravel-ai-engine/V1\ Agent\ Runs/07\ Stream\ Run.bru \
  docs/orchestration-v2.mdx \
  docs/realtime-observability.mdx \
  docs/api-reference.mdx \
  docs/quickstart-chat-actions.mdx \
  README.md
git commit -m "feat: add durable async agent chat realtime"
```

## Self-Review

- Spec coverage: async chat, durable run creation, resume flow reuse, SSE, Laravel Broadcasting for Reverb/Pusher, polling fallback, Bruno, docs, and tests are covered.
- Placeholder scan: this plan contains no open implementation gaps.
- Type consistency: service names, request names, route names, job names, and config keys match the current package structure and planned files.
- Non-duplication: the plan reuses `RunAgentJob`, `ContinueAgentRunJob`, `AgentRunRepository`, `AgentRunEventStreamService`, and existing agent run APIs instead of creating a second job system.
