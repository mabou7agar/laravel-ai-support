# Token-Efficient Conversation Memory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add a generic, scoped conversation-memory system that remembers useful long-term chat facts without sending full chat history back to the model.

**Architecture:** Keep the current `ConversationContextCompactor` as short-term memory, then add a separate durable memory layer for extracted user/session/workspace facts, preferences, unresolved tasks, and stable conversation decisions. Prompt construction retrieves only the most relevant memories under a strict token/character budget. Extraction is configurable, cheap by default, and isolated from business RAG/capability memory.

**Tech Stack:** Laravel package services, DTOs, repositories, migrations, Eloquent models, existing agent runtime, existing vector/embedding services as optional retrieval enhancement.

---

## Current Package Features To Reuse

The package already has message compression. Do not rebuild these parts.

- `src/Services/Agent/ConversationContextCompactor.php` sanitizes message roles/content, truncates each message, keeps only recent turns, moves older turns into `metadata.conversation_summary`, and records `conversation_context_metrics`.
- `src/Services/Agent/ContextManager.php` loads/saves `UnifiedActionContext` from cache and runs compaction on load and save.
- `src/Services/Agent/AgentResponseFinalizer.php` appends assistant messages, compacts again, and persists context.
- `src/Services/Agent/AgentConversationService.php` injects `conversation_summary` plus recent messages into conversational prompts.
- `src/Services/Agent/IntentRouter.php`, `src/Services/Agent/RoutingContextResolver.php`, and `src/Services/RAG/RAGContextService.php` already reuse `conversation_summary` for routing and RAG context.
- `src/Services/Memory/MemoryManager.php`, `src/Services/MemoryProxy.php`, and the memory drivers store/retrieve raw conversation histories through database, Redis, file, or MongoDB drivers.
- `src/Services/ConversationManager.php`, `src/Models/Conversation.php`, and `src/Models/Message.php` persist regular conversation rows and support capped message windows through `max_messages`.
- `README.md` already documents "Agent Conversation Context Compaction".

Therefore this plan must only add the missing layer: **scoped durable semantic memories** extracted from compressed turns and retrieved by relevance. It must not replace `ConversationContextCompactor`, `ConversationManager`, `MemoryManager`, or existing message tables.

## Design Summary

Use four memory layers:

1. **Recent turns:** keep the last `N` messages verbatim. This already exists through `ConversationContextCompactor`.
2. **Rolling summary:** compact older turns into `metadata.conversation_summary`. This already exists and should be reused as the source for cheap extraction, not replaced.
3. **Structured memories:** persist stable facts and preferences as small records, scoped by `user_id`, `tenant_id`, `workspace_id`, `session_id`, and `namespace`.
4. **Relevant memory retrieval:** before prompt generation, retrieve only top memories related to the current message and fit them into a fixed budget.

Do not store business records here. Invoices, customers, products, files, and knowledge documents still belong in app data/RAG/vector/graph systems. This feature stores conversation memory such as "user prefers Arabic replies", "current workspace is Acme", "user is building Laravel AI package", and "for this session, invoice flow draft uses net-30 terms".

## File Structure

- Create: `database/migrations/2026_05_17_000001_create_ai_conversation_memories_table.php`
- Create: `src/Models/AIConversationMemory.php`
- Create: `src/DTOs/ConversationMemoryItem.php`
- Create: `src/DTOs/ConversationMemoryQuery.php`
- Create: `src/DTOs/ConversationMemoryResult.php`
- Create: `src/Repositories/ConversationMemoryRepository.php`
- Create: `src/Services/Agent/Memory/ConversationMemoryPolicy.php`
- Create: `src/Services/Agent/Memory/ConversationMemoryExtractor.php`
- Create: `src/Services/Agent/Memory/ConversationMemoryRetriever.php`
- Create: `src/Services/Agent/Memory/ConversationMemoryPromptBuilder.php`
- Create: `tests/Unit/Services/Agent/Memory/ConversationMemoryExtractorTest.php`
- Create: `tests/Unit/Services/Agent/Memory/ConversationMemoryRetrieverTest.php`
- Create: `tests/Unit/Services/Agent/Memory/ConversationMemoryPromptBuilderTest.php`
- Modify: `config/ai-agent.php`
- Modify: `src/Support/Providers/AgentServiceRegistrar.php`
- Modify: `src/Services/Agent/ConversationContextCompactor.php` only to emit compacted older turns to the memory extractor after existing summary behavior succeeds
- Modify: `src/Services/Agent/AgentConversationService.php`
- Modify: `src/Services/Agent/RoutingContextResolver.php` only if scoped memory context should be passed into RAG/routing options
- Modify: `docs/agent-memory.mdx`
- Modify: `README.md`

## Non-Duplication Rules

- Do not create another conversation history table.
- Do not create another message compression service.
- Do not move `conversation_summary` out of context metadata in this iteration.
- Do not change default `max_messages` trimming behavior in `Conversation` unless a test proves it conflicts with compaction.
- Do not store full message bodies in long-term memory records.
- Do not index business entities as conversation memories. Use RAG/vector/graph/capability memory for that.
- Do not call an AI model just to compress every turn. Extraction runs on compaction boundaries only and must respect a strict extraction budget.
- Do not ship package-level regex memory triggers such as "remember that" or "I prefer". They are language-biased and make the package less AI-aware.
- Do not hardcode Qdrant, Neo4j, OpenAI, Gemini, OpenRouter, collection names, embedding models, tenant keys, workspace keys, or payload field names in runtime memory code.
- Do not make vector DB required for chat memory. The package must work with SQL-only lexical retrieval.
- Do not trust vector metadata filters alone for authorization. Always re-check scope against SQL source-of-truth records.

## Configuration

Add this under `config/ai-agent.php`:

```php
'conversation_memory' => [
    'enabled' => env('AI_AGENT_CONVERSATION_MEMORY_ENABLED', true),
    'driver' => env('AI_AGENT_CONVERSATION_MEMORY_DRIVER', 'database'),
    'extract_on_compaction' => env('AI_AGENT_MEMORY_EXTRACT_ON_COMPACTION', true),
    'extractor' => env('AI_AGENT_MEMORY_EXTRACTOR', 'ai'),
    'engine' => env('AI_AGENT_MEMORY_ENGINE', env('AI_ENGINE_DEFAULT')),
    'model' => env('AI_AGENT_MEMORY_MODEL', env('AI_ENGINE_ORCHESTRATION_MODEL', env('AI_ENGINE_DEFAULT_MODEL'))),
    'max_extraction_input_chars' => (int) env('AI_AGENT_MEMORY_MAX_EXTRACTION_INPUT_CHARS', 6000),
    'max_memories_per_turn' => (int) env('AI_AGENT_MEMORY_MAX_PER_TURN', 6),
    'max_prompt_chars' => (int) env('AI_AGENT_MEMORY_MAX_PROMPT_CHARS', 1200),
    'min_score' => (float) env('AI_AGENT_MEMORY_MIN_SCORE', 0.45),
    'ttl_days' => (int) env('AI_AGENT_MEMORY_TTL_DAYS', 180),
    'scopes' => [
        'tenant_key' => env('AI_AGENT_MEMORY_TENANT_KEY', 'tenant_id'),
        'workspace_key' => env('AI_AGENT_MEMORY_WORKSPACE_KEY', 'workspace_id'),
    ],
    'semantic' => [
        'enabled' => env('AI_AGENT_MEMORY_SEMANTIC_ENABLED', false),
        'driver' => env('AI_AGENT_MEMORY_SEMANTIC_DRIVER', env('AI_ENGINE_VECTOR_DRIVER')),
        'collection' => env('AI_AGENT_MEMORY_SEMANTIC_COLLECTION', env('AI_ENGINE_MEMORY_COLLECTION')),
        'embedding_engine' => env('AI_AGENT_MEMORY_EMBEDDING_ENGINE', env('AI_ENGINE_DEFAULT')),
        'embedding_model' => env('AI_AGENT_MEMORY_EMBEDDING_MODEL', env('AI_ENGINE_VECTOR_EMBEDDING_MODEL')),
        'index_on_write' => env('AI_AGENT_MEMORY_SEMANTIC_INDEX_ON_WRITE', false),
        'payload_scope_fields' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'AI_AGENT_MEMORY_VECTOR_SCOPE_FIELDS',
            'user_id,tenant_id,workspace_id,session_id,namespace'
        ))))),
    ],
],
```

Config rules:

- `extractor=ai` by default so memory detection is multilingual and intent-aware.
- AI extraction runs only on compacted older messages, not on every turn.
- AI extraction input is capped by `max_extraction_input_chars`; if the cap is exceeded, pass the existing compact summary plus the newest compacted excerpts.
- Host apps may bind their own extractor service or configure another extractor name, but the package should not ship hardcoded English regex triggers.
- Retrieval must always enforce `user_id`, tenant, workspace, and session scopes.
- SQL/database remains the source of truth. Vector storage is an optional index for semantic recall.
- No vector driver, collection, embedding model, scope key, or payload field should be hardcoded in runtime code. All defaults must come from config.
- If semantic config is incomplete, the package must silently use lexical SQL retrieval.

---

### Task 1: Add Memory Schema, Model, DTOs, and Repository

**Files:**
- Create: `database/migrations/2026_05_17_000001_create_ai_conversation_memories_table.php`
- Create: `src/Models/AIConversationMemory.php`
- Create: `src/DTOs/ConversationMemoryItem.php`
- Create: `src/DTOs/ConversationMemoryQuery.php`
- Create: `src/DTOs/ConversationMemoryResult.php`
- Create: `src/Repositories/ConversationMemoryRepository.php`
- Test: `tests/Unit/Services/Agent/Memory/ConversationMemoryRepositoryTest.php`

- [x] **Step 1: Write failing repository tests**

```php
public function test_repository_stores_and_filters_memory_by_user_workspace_and_tenant(): void
{
    $repo = app(\LaravelAIEngine\Repositories\ConversationMemoryRepository::class);

    $repo->upsert(\LaravelAIEngine\DTOs\ConversationMemoryItem::fromArray([
        'namespace' => 'profile',
        'key' => 'preferred_language',
        'value' => 'Arabic',
        'summary' => 'User prefers Arabic replies.',
        'user_id' => '7',
        'tenant_id' => 'tenant-a',
        'workspace_id' => 'workspace-a',
        'confidence' => 0.95,
    ]));

    $query = new \LaravelAIEngine\DTOs\ConversationMemoryQuery(
        message: 'reply in my preferred language',
        userId: '7',
        tenantId: 'tenant-a',
        workspaceId: 'workspace-a',
        limit: 5,
    );

    $results = $repo->search($query);

    $this->assertCount(1, $results);
    $this->assertSame('preferred_language', $results[0]->item->key);
    $this->assertSame('Arabic', $results[0]->item->value);

    $otherWorkspace = new \LaravelAIEngine\DTOs\ConversationMemoryQuery(
        message: 'reply in my preferred language',
        userId: '7',
        tenantId: 'tenant-a',
        workspaceId: 'workspace-b',
        limit: 5,
    );

    $this->assertSame([], $repo->search($otherWorkspace));
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/Memory/ConversationMemoryRepositoryTest.php
```

Expected: FAIL because repository/model/table do not exist.

- [x] **Step 3: Create migration**

```php
Schema::create('ai_conversation_memories', function (Blueprint $table): void {
    $table->id();
    $table->string('namespace')->default('conversation');
    $table->string('key');
    $table->text('value')->nullable();
    $table->text('summary');
    $table->json('metadata')->nullable();
    $table->string('user_id')->nullable()->index();
    $table->string('tenant_id')->nullable()->index();
    $table->string('workspace_id')->nullable()->index();
    $table->string('session_id')->nullable()->index();
    $table->float('confidence')->default(0.7);
    $table->timestamp('last_seen_at')->nullable()->index();
    $table->timestamp('expires_at')->nullable()->index();
    $table->timestamps();

    $table->unique(
        ['namespace', 'key', 'user_id', 'tenant_id', 'workspace_id', 'session_id'],
        'ai_conversation_memories_scope_unique'
    );
});
```

- [x] **Step 4: Create DTOs and repository**

Repository contract behavior:

```php
public function upsert(ConversationMemoryItem $item): ConversationMemoryItem;
public function search(ConversationMemoryQuery $query): array;
public function forgetScope(ConversationMemoryQuery $query): int;
```

Search implementation starts with safe lexical matching:

```php
$query = AIConversationMemory::query()
    ->where(function ($builder) use ($memoryQuery) {
        $builder->whereNull('user_id')->orWhere('user_id', $memoryQuery->userId);
    })
    ->where(function ($builder) use ($memoryQuery) {
        $builder->whereNull('tenant_id')->orWhere('tenant_id', $memoryQuery->tenantId);
    })
    ->where(function ($builder) use ($memoryQuery) {
        $builder->whereNull('workspace_id')->orWhere('workspace_id', $memoryQuery->workspaceId);
    })
    ->where(function ($builder): void {
        $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
    });
```

- [x] **Step 5: Run repository tests**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/Memory/ConversationMemoryRepositoryTest.php
```

Expected: PASS.

---

### Task 2: Add AI-Aware Memory Extraction Without Hardcoded Triggers

**Files:**
- Create: `src/Services/Agent/Memory/ConversationMemoryPolicy.php`
- Create: `src/Services/Agent/Memory/ConversationMemoryExtractor.php`
- Test: `tests/Unit/Services/Agent/Memory/ConversationMemoryExtractorTest.php`

- [x] **Step 1: Write failing extractor tests**

```php
public function test_ai_extractor_detects_memory_without_language_specific_patterns(): void
{
    config()->set('ai-agent.conversation_memory.extractor', 'ai');
    config()->set('ai-agent.conversation_memory.max_extraction_input_chars', 2000);

    $ai = Mockery::mock(\LaravelAIEngine\Services\AIEngineService::class);
    $ai->shouldReceive('generate')
        ->once()
        ->with(Mockery::on(function ($request): bool {
            return str_contains($request->prompt, 'أحب الردود المختصرة باللغة العربية')
                && str_contains($request->prompt, 'Return JSON array only')
                && strlen($request->prompt) < 2500;
        }))
        ->andReturn(\LaravelAIEngine\DTOs\AIResponse::success(json_encode([
            [
                'namespace' => 'preferences',
                'key' => 'reply_style',
                'value' => 'short Arabic replies',
                'summary' => 'User prefers short Arabic replies.',
                'confidence' => 0.9,
            ],
        ]), (string) config('ai-agent.conversation_memory.engine'), (string) config('ai-agent.conversation_memory.model')));

    $extractor = new \LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor(
        app(\LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy::class),
        $ai
    );

    $items = $extractor->extract([
        ['role' => 'user', 'content' => 'أحب الردود المختصرة باللغة العربية في هذا المشروع.'],
    ], [
        'user_id' => '7',
        'tenant_id' => 'tenant-a',
        'workspace_id' => 'workspace-a',
        'session_id' => 'session-a',
    ]);

    $this->assertNotEmpty($items);
    $this->assertStringContainsString('Arabic', $items[0]->summary);
    $this->assertSame('7', $items[0]->userId);
    $this->assertSame('preferences', $items[0]->namespace);
}
```

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/Memory/ConversationMemoryExtractorTest.php
```

Expected: FAIL because extractor does not exist.

- [x] **Step 3: Implement AI-aware extractor**

The extractor must not use package-level regex triggers. It should send a capped compaction batch to the configured AI engine/model and ask for structured memory candidates.

Prompt requirements:

```text
You extract durable conversation memories for a Laravel package.
Return JSON array only.
Extract only stable user/session/workspace preferences, facts, constraints, unresolved goals, and decisions.
Do not require English trigger phrases. Infer memory-worthiness from meaning in any language.
Do not include business database records, invoice line items, product catalogs, private credentials, secrets, payment data, or transient chit-chat.
Each item must contain: namespace, key, value, summary, confidence.
Keep every summary under 220 characters.
Return [] when there is nothing worth remembering.
```

Map every accepted item through package DTO validation:

```php
new ConversationMemoryItem(
    namespace: $this->normalizeNamespace($payload['namespace'] ?? 'conversation'),
    key: $this->stableKey($payload['namespace'] ?? 'conversation', $payload['key'] ?? $payload['summary'] ?? ''),
    value: $this->limit((string) ($payload['value'] ?? ''), 500),
    summary: $this->limit((string) ($payload['summary'] ?? ''), 220),
    userId: $scope['user_id'] ?? null,
    tenantId: $scope['tenant_id'] ?? null,
    workspaceId: $scope['workspace_id'] ?? null,
    sessionId: $scope['session_id'] ?? null,
    confidence: min(1.0, max(0.0, (float) ($payload['confidence'] ?? 0.7))),
    metadata: ['source' => 'ai_extractor'],
);
```

- [x] **Step 4: Add configurable custom extractor support**

Host apps can override extraction without editing the package:

```php
$customExtractor = config('ai-agent.conversation_memory.extractor_class');
if (is_string($customExtractor) && class_exists($customExtractor)) {
    return app($customExtractor)->extract($messages, $scope);
}
```

If `extractor` is set to `none`, return `[]` and keep existing compression only.

- [x] **Step 5: Run extractor tests**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/Memory/ConversationMemoryExtractorTest.php
```

Expected: PASS.

---

### Task 3: Retrieve Relevant Memories Under a Prompt Budget

**Files:**
- Create: `src/Services/Agent/Memory/ConversationMemoryRetriever.php`
- Create: `src/Services/Agent/Memory/ConversationMemoryPromptBuilder.php`
- Test: `tests/Unit/Services/Agent/Memory/ConversationMemoryRetrieverTest.php`
- Test: `tests/Unit/Services/Agent/Memory/ConversationMemoryPromptBuilderTest.php`

- [x] **Step 1: Write failing budget tests**

```php
public function test_prompt_builder_keeps_only_relevant_memories_under_budget(): void
{
    config()->set('ai-agent.conversation_memory.max_prompt_chars', 120);

    $builder = app(\LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder::class);

    $text = $builder->build([
        new ConversationMemoryResult(
            item: ConversationMemoryItem::fromArray([
                'summary' => 'User prefers Arabic replies.',
                'key' => 'preferred_language',
                'namespace' => 'preferences',
            ]),
            score: 0.95,
            reason: 'lexical',
        ),
        new ConversationMemoryResult(
            item: ConversationMemoryItem::fromArray([
                'summary' => str_repeat('Long irrelevant memory. ', 30),
                'key' => 'long',
                'namespace' => 'conversation',
            ]),
            score: 0.3,
            reason: 'low_score',
        ),
    ]);

    $this->assertStringContainsString('Arabic replies', $text);
    $this->assertLessThanOrEqual(120, strlen($text));
    $this->assertStringNotContainsString('Long irrelevant memory', $text);
}
```

- [x] **Step 2: Implement retrieval ranking**

Ranking inputs:

```php
$score = 0.0;
$score += $this->lexicalOverlap($query->message, $memory->summary) * 0.55;
$score += $memory->confidence * 0.25;
$score += $this->recencyScore($memory->last_seen_at) * 0.10;
$score += $this->scopeSpecificityScore($memory, $query) * 0.10;
```

Do not return memories below `ai-agent.conversation_memory.min_score`.

- [x] **Step 3: Implement prompt builder**

Format:

```text
Relevant remembered context:
- [preferences] User prefers Arabic replies.
- [conversation] User is validating a Laravel AI package.
```

Rules:

- No raw JSON in the prompt.
- No more than `max_prompt_chars`.
- Sort by score desc.
- Drop low-score and duplicate summaries.

- [x] **Step 4: Run retrieval and prompt tests**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/Memory/ConversationMemoryRetrieverTest.php tests/Unit/Services/Agent/Memory/ConversationMemoryPromptBuilderTest.php
```

Expected: PASS.

---

### Task 4: Wire Memory Into Compaction and Conversational Prompting

**Files:**
- Modify: `src/Services/Agent/ConversationContextCompactor.php` without changing current summary/recent-turn behavior
- Modify: `src/Services/Agent/AgentConversationService.php`
- Modify: `src/Support/Providers/AgentServiceRegistrar.php`
- Test: `tests/Unit/Services/Agent/ConversationContextCompactorTest.php`
- Test: `tests/Unit/Services/Agent/AgentConversationServiceTest.php`

- [x] **Step 1: Add failing compactor integration test**

```php
public function test_compactor_extracts_memories_only_when_old_messages_are_compacted(): void
{
    config()->set('ai-agent.conversation_memory.enabled', true);
    config()->set('ai-agent.conversation_memory.extract_on_compaction', true);
    config()->set('ai-agent.context_compaction.max_messages', 4);
    config()->set('ai-agent.context_compaction.keep_recent_messages', 2);

    $context = new UnifiedActionContext('memory-session', '7', metadata: [
        'tenant_id' => 'tenant-a',
        'workspace_id' => 'workspace-a',
    ]);
    $context->conversationHistory = [
        ['role' => 'user', 'content' => 'Remember that I prefer Arabic replies.'],
        ['role' => 'assistant', 'content' => 'Noted.'],
        ['role' => 'user', 'content' => 'Now create a draft invoice.'],
        ['role' => 'assistant', 'content' => 'I need the customer name.'],
        ['role' => 'user', 'content' => 'Use ACME.'],
    ];

    app(ConversationContextCompactor::class)->compact($context);

    $repo = app(\LaravelAIEngine\Repositories\ConversationMemoryRepository::class);
    $results = $repo->search(new ConversationMemoryQuery(
        message: 'what language should you use?',
        userId: '7',
        tenantId: 'tenant-a',
        workspaceId: 'workspace-a',
        limit: 5,
    ));

    $this->assertNotEmpty($results);
    $this->assertArrayHasKey('conversation_summary', $context->metadata);
    $this->assertCount(2, $context->conversationHistory);
}
```

- [x] **Step 2: Extend the existing compactor instead of replacing it**

Keep the existing flow:

1. sanitize messages
2. decide whether compaction is required
3. split `$recent` and `$older`
4. append `$older` to `metadata.conversation_summary`
5. keep `$recent` in `conversationHistory`
6. store `conversation_context_metrics`

Only add memory extraction between steps 3 and 4. The existing summary must still be produced even when memory extraction is disabled or fails.

- [x] **Step 3: Inject extractor/repository into compactor**

Constructor:

```php
public function __construct(
    protected ?ConversationMemoryPolicy $memoryPolicy = null,
    protected ?ConversationMemoryExtractor $memoryExtractor = null,
    protected ?ConversationMemoryRepository $memoryRepository = null,
) {
}
```

After `$older` is computed and before summary is built:

```php
try {
    if ($this->memoryPolicy()->enabled() && $this->memoryPolicy()->extractOnCompaction()) {
        foreach ($this->memoryExtractor()->extract($older, $this->scopeFromContext($context)) as $item) {
            $this->memoryRepository()->upsert($item);
        }
    }
} catch (\Throwable $e) {
    $context->metadata['conversation_memory_extraction_error'] = $e->getMessage();
}
```

This try/catch is intentional: memory extraction must never break existing message compression or chat response generation.

- [x] **Step 4: Add failing conversational prompt test**

```php
public function test_conversational_prompt_includes_only_retrieved_memory(): void
{
    config()->set('ai-agent.conversation_memory.enabled', true);
    config()->set('ai-agent.conversation_memory.max_prompt_chars', 500);

    app(ConversationMemoryRepository::class)->upsert(ConversationMemoryItem::fromArray([
        'namespace' => 'preferences',
        'key' => 'preferred_language',
        'summary' => 'User prefers Arabic replies.',
        'value' => 'Arabic',
        'user_id' => '7',
    ]));

    $ai = Mockery::mock(AIEngineService::class);
    $ai->shouldReceive('generate')
        ->once()
        ->with(Mockery::on(fn ($request) => str_contains($request->prompt, 'User prefers Arabic replies')))
        ->andReturn(AIResponse::success('سأرد بالعربية.', (string) config('ai-engine.default'), (string) config('ai-engine.default_model')));

    $service = new AgentConversationService($ai, $ragRouter, $selectedEntity, $selection);
    $response = $service->executeConversational('what language should you use?', new UnifiedActionContext('s', '7'), [
        'engine' => config('ai-engine.default'),
        'model' => config('ai-engine.default_model'),
    ]);

    $this->assertSame('سأرد بالعربية.', $response->message);
}
```

- [x] **Step 5: Add memory retrieval to `AgentConversationService`**

Before rendering the prompt:

```php
$memoryText = '';
if ($this->memoryPolicy()->enabled()) {
    $results = $this->memoryRetriever()->retrieve(ConversationMemoryQuery::fromContext($message, $context, $options));
    $memoryText = $this->memoryPromptBuilder()->build($results);
}
```

Prompt template variable:

```php
'memory_text' => $memoryText,
```

Fallback prompt section:

```text
Relevant remembered context:
{$memoryText}
```

- [x] **Step 6: Store retrieved memory metrics on the existing context metrics path**

After building memory prompt text:

```php
$context->metadata['retrieved_memory'] = $memoryText;
$context->metadata['conversation_context_metrics'] = array_merge(
    $context->metadata['conversation_context_metrics'] ?? [],
    [
        'retrieved_memory_size_chars' => strlen($memoryText),
        'retrieved_memory_count' => count($results),
    ]
);
```

This reuses the existing `ConversationContextCompactor::metrics()` convention instead of creating a second metrics structure.

- [x] **Step 7: Register services**

In `AgentServiceRegistrar`:

```php
$app->singleton(ConversationMemoryPolicy::class);
$app->singleton(ConversationMemoryRepository::class);
$app->singleton(ConversationMemoryExtractor::class);
$app->singleton(ConversationMemoryRetriever::class);
$app->singleton(ConversationMemoryPromptBuilder::class);
```

- [x] **Step 8: Run integration unit tests**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/ConversationContextCompactorTest.php tests/Unit/Services/Agent/AgentConversationServiceTest.php
```

Expected: PASS.

---

### Task 5: Add Optional Semantic Retrieval Without Making It Mandatory

**Files:**
- Create: `src/DTOs/ConversationMemoryVectorDocument.php`
- Create: `src/Services/Agent/Memory/ConversationMemoryEmbeddingService.php`
- Modify: `src/Services/Agent/Memory/ConversationMemoryRetriever.php`
- Test: `tests/Unit/Services/Agent/Memory/ConversationMemoryRetrieverTest.php`

- [x] **Step 1: Add config**

```php
'semantic' => [
    'enabled' => env('AI_AGENT_MEMORY_SEMANTIC_ENABLED', false),
    'driver' => env('AI_AGENT_MEMORY_SEMANTIC_DRIVER', env('AI_ENGINE_VECTOR_DRIVER')),
    'collection' => env('AI_AGENT_MEMORY_SEMANTIC_COLLECTION', env('AI_ENGINE_MEMORY_COLLECTION')),
    'embedding_engine' => env('AI_AGENT_MEMORY_EMBEDDING_ENGINE', env('AI_ENGINE_DEFAULT')),
    'embedding_model' => env('AI_AGENT_MEMORY_EMBEDDING_MODEL', env('AI_ENGINE_VECTOR_EMBEDDING_MODEL')),
    'index_on_write' => env('AI_AGENT_MEMORY_SEMANTIC_INDEX_ON_WRITE', false),
    'payload_scope_fields' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'AI_AGENT_MEMORY_VECTOR_SCOPE_FIELDS',
        'user_id,tenant_id,workspace_id,session_id,namespace'
    ))))),
],
```

Important: `driver`, `collection`, `embedding_engine`, and `embedding_model` are nullable by config. Runtime code must not assume Qdrant, OpenAI, or any collection name.

- [x] **Step 2: Add semantic retrieval tests with fake embeddings**

Use fake embeddings and assert:

- semantic provider is not called when disabled
- semantic results merge with lexical results when enabled
- scope filters are always passed to vector search
- semantic retrieval returns lexical results when `collection` or `driver` is empty
- vector payload is built from configured `payload_scope_fields`, not hardcoded field names

- [x] **Step 3: Implement optional semantic adapter**

The adapter should:

- Use existing embedding/vector services when configured.
- Fall back to repository lexical search when vector services are unavailable.
- Never throw from prompt construction if vector search fails.
- Treat SQL `ai_conversation_memories` as source of truth and vector DB as a disposable index.
- Rehydrate vector hits through `ConversationMemoryRepository` by memory id before prompt injection.
- Apply SQL scope checks after vector retrieval, even if the vector driver supports metadata filters.
- Build vector collection names through config only:

```php
$collection = trim((string) config('ai-agent.conversation_memory.semantic.collection', ''));
if ($collection === '') {
    return [];
}
```

- Build vector filters from configured fields only:

```php
$fields = (array) config('ai-agent.conversation_memory.semantic.payload_scope_fields', []);
$payload = [];
foreach ($fields as $field) {
    $value = data_get($query->scope(), $field);
    if ($value !== null && $value !== '') {
        $payload[$field] = (string) $value;
    }
}
```

- The vector document text should be concise and generic:

```php
trim(implode("\n", array_filter([
    "[{$item->namespace}] {$item->summary}",
    $item->value !== null ? "Value: {$item->value}" : null,
])));
```

- [x] **Step 4: Run semantic tests**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Services/Agent/Memory/ConversationMemoryRetrieverTest.php
```

Expected: PASS.

---

### Task 6: Documentation and End-to-End Checks

**Files:**
- Create: `docs/agent-memory.mdx`
- Modify: `README.md`
- Test: existing docs coverage tests if applicable

- [x] **Step 1: Document recommended usage**

Document:

- `Recent turns` are for immediate context.
- `conversation_summary` is for compressed session continuity.
- `conversation_memory` is for durable facts/preferences.
- `RAG/vector/graph` is for business records and knowledge.
- Set tenant/workspace scope in options:

```php
AgentChat::message('continue from last time', [
    'user_id' => auth()->id(),
    'tenant_id' => tenant('id'),
    'workspace_id' => $workspace->id,
]);
```

- [x] **Step 2: Add README summary**

Add a short section:

```md
## Token-Efficient Conversation Memory

The package uses bounded recent turns, rolling summaries, and optional scoped long-term memories. Long-term memories are retrieved by relevance and budget before prompting, so old chats do not grow token usage linearly.
```

- [x] **Step 3: Run documentation and full tests**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit,Feature --order-by=default
composer validate --strict
git diff --check
```

Expected:

- PHPUnit passes.
- Composer validates.
- Diff check is clean.

---

### Task 7: Audit Package Intent Heuristics and Make User-Intent Decisions AI-Aware

**Files:**
- Modify: `config/ai-agent.php`
- Modify: `src/Services/Agent/MessageRoutingClassifier.php`
- Modify: `src/Services/Agent/AgentSkillMatcher.php`
- Modify: `src/Services/Agent/IntentRouter.php`
- Modify: `src/Services/Agent/Tools/RunSkillTool.php`
- Modify: `src/Services/Agent/Collectors/CollectorConfirmationService.php`
- Modify: `src/Services/Actions/ActionDraftService.php`
- Modify: `src/Services/Actions/ActionPayloadExtractor.php`
- Modify: `src/Services/Actions/ActionIntakeFlowService.php`
- Modify: `src/Services/Agent/NodeSessionManager.php`
- Test: existing tests for each touched service plus new multilingual intent tests

**Audit findings:**

The package currently has hardcoded user-intent phrase logic in these areas:

- `MessageRoutingClassifier`: greetings, thanks, action verbs, list/count words, contextual follow-up words, semantic retrieval phrases.
- `AgentSkillMatcher`: deterministic trigger matching is fine when triggers come from host skills, but package prompt text and fallback behavior should not assume English continuation phrases.
- `config/ai-agent.php`: `skills.continuation_terms` ships package-owned English/Arabic defaults.
- `IntentRouter`, `RunSkillTool`, `ActionDraftService`, `ActionPayloadExtractor`, `CollectorConfirmationService`: yes/no/confirm/cancel phrase regex.
- `ActionIntakeFlowService`: relation choice phrases like use existing/create new.
- `NodeSessionManager`: remote-session continuation/new-topic phrase checks.

Keep structural regex. These are not the problem:

- JSON/code fence extraction.
- Class/name/path parsing.
- Numeric id, date, money, and email parsing.
- Database field type heuristics.
- Host-provided skill triggers, action triggers, aliases, and configured term lists.

- [x] **Step 1: Add config for AI-aware intent classification**

Add:

```php
'intent_understanding' => [
    'mode' => env('AI_AGENT_INTENT_UNDERSTANDING_MODE', 'ai_first'), // ai_first|hybrid|heuristic
    'engine' => env('AI_AGENT_INTENT_ENGINE', env('AI_ENGINE_DEFAULT')),
    'model' => env('AI_AGENT_INTENT_MODEL', env('AI_ENGINE_ORCHESTRATION_MODEL', env('AI_ENGINE_DEFAULT_MODEL'))),
    'max_tokens' => (int) env('AI_AGENT_INTENT_MAX_TOKENS', 500),
    'temperature' => (float) env('AI_AGENT_INTENT_TEMPERATURE', 0.0),
    'cache_ttl_seconds' => (int) env('AI_AGENT_INTENT_CACHE_TTL', 120),
    'fallback_to_heuristics' => env('AI_AGENT_INTENT_FALLBACK_TO_HEURISTICS', true),
],
```

Do not add hardcoded phrase defaults. Existing configurable term arrays may stay only as host-editable fallback input.

- [x] **Step 2: Create an intent DTO and classifier service**

Create:

- `src/DTOs/AgentIntentDecision.php`
- `src/Services/Agent/AgentIntentUnderstandingService.php`

The service returns structured decisions:

```php
new AgentIntentDecision(
    route: 'ask_ai',
    mode: 'action_flow',
    confidence: 0.88,
    intent: 'confirm',
    target: 'current_action',
    reason: 'User approved the pending action in Arabic.',
    metadata: []
);
```

Allowed `intent` values:

```php
[
    'chat',
    'action_request',
    'structured_query',
    'semantic_retrieval',
    'contextual_follow_up',
    'confirm',
    'reject',
    'choose_existing',
    'create_new',
    'continue_remote_session',
    'new_topic',
    'skill_request',
    'unknown',
]
```

- [x] **Step 3: Replace package-owned phrase checks with AI-first decisions**

Rules:

- `MessageRoutingClassifier` should call `AgentIntentUnderstandingService` when mode is `ai_first` or `hybrid`.
- Confirmation/cancel helpers should accept an injected/precomputed `AgentIntentDecision` before falling back.
- `ActionIntakeFlowService` should use `choose_existing` / `create_new` decisions.
- `NodeSessionManager` should use `continue_remote_session` / `new_topic`.
- `AgentSkillMatcher` should keep host-provided triggers but prefer AI intent when configured.

- [x] **Step 4: Keep deterministic fallback only as configurable or structural**

Fallback can use:

```php
(array) config('ai-agent.routing_classifier.action_entities', [])
(array) config('ai-agent.routing_classifier.structured_status_terms', [])
(array) config('ai-agent.routing_classifier.structured_collection_terms', [])
(array) config('ai-agent.skills.continuation_terms', [])
```

But remove package-owned English phrase arrays from runtime code. If default config keeps examples for backward compatibility, document that apps can clear them and AI mode does not require them.

- [x] **Step 5: Add multilingual tests**

Tests must prove no English trigger is required:

```php
public function test_ai_intent_understands_arabic_confirmation_without_regex_terms(): void
{
    config()->set('ai-agent.intent_understanding.mode', 'ai_first');
    config()->set('ai-agent.skills.continuation_terms', []);

    $ai = Mockery::mock(AIEngineService::class);
    $ai->shouldReceive('generate')->andReturn(AIResponse::success(json_encode([
        'route' => 'ask_ai',
        'mode' => 'action_flow',
        'intent' => 'confirm',
        'confidence' => 0.94,
        'reason' => 'Arabic approval.',
    ]), (string) config('ai-engine.default'), (string) config('ai-engine.default_model')));

    $decision = app(AgentIntentUnderstandingService::class)->decide('تمام نفذ الآن', new UnifiedActionContext('s', 7));

    $this->assertSame('confirm', $decision->intent);
}
```

Add similar tests for:

- Arabic cancellation.
- Arabic "use existing".
- Arabic "create new".
- Indirect skill request from conversation context.
- Semantic retrieval in non-English language.

- [x] **Step 6: Run impacted tests**

Run:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist \
  tests/Unit/Services/Agent/MessageRoutingClassifierTest.php \
  tests/Unit/Services/Agent/AgentSkillMatcherTest.php \
  tests/Unit/Services/Agent/Tools/RunSkillToolTest.php \
  tests/Unit/Services/Actions/ActionDraftServiceTest.php \
  tests/Unit/Services/Actions/ActionPayloadExtractorTest.php
```

Expected: PASS.

---

## Acceptance Criteria

- Old chat history never grows prompt tokens linearly.
- Recent turns still work exactly as today.
- Existing `conversation_summary` remains supported.
- Existing `ConversationContextCompactor` tests continue to pass unchanged, except for additive assertions around extracted memories.
- Existing `ConversationManager`, `MemoryManager`, `MemoryProxy`, `ai_conversations`, and `ai_messages` keep their current responsibilities.
- Durable memories are scoped by user, tenant, workspace, session, and namespace.
- Memory extraction is AI-aware by default but runs only at compaction boundaries with capped input.
- Apps can disable extraction with `AI_AGENT_MEMORY_EXTRACTOR=none` or replace it with `extractor_class`.
- Vector retrieval and graph relationships are optional enhancements.
- Vector DB is used only as a configurable semantic index, never as the only source of truth.
- Runtime code has no hardcoded provider, vector driver, collection, embedding model, tenant key, workspace key, or payload filter field.
- User-intent decisions do not depend on package-owned English regex phrases when AI intent mode is enabled.
- Structural parsing regex remains allowed for JSON, ids, dates, code fences, class names, and host-configured terms.
- Prompt memory section is capped by `ai-agent.conversation_memory.max_prompt_chars`.
- Tests cover extraction, scope isolation, retrieval ranking, prompt budgeting, and conversational prompt injection.

## Recommended Defaults

```env
AI_AGENT_CONTEXT_MAX_MESSAGES=12
AI_AGENT_CONTEXT_KEEP_RECENT_MESSAGES=6
AI_AGENT_CONTEXT_MAX_TOTAL_CHARS=12000
AI_AGENT_CONTEXT_MAX_SUMMARY_CHARS=4000
AI_AGENT_CONVERSATION_MEMORY_ENABLED=true
AI_AGENT_MEMORY_EXTRACTOR=ai
AI_AGENT_MEMORY_MAX_EXTRACTION_INPUT_CHARS=6000
AI_AGENT_MEMORY_MAX_PER_TURN=6
AI_AGENT_MEMORY_MAX_PROMPT_CHARS=1200
AI_AGENT_MEMORY_MIN_SCORE=0.45
AI_AGENT_MEMORY_TTL_DAYS=180
AI_AGENT_MEMORY_SEMANTIC_ENABLED=false
AI_AGENT_MEMORY_SEMANTIC_DRIVER=
AI_AGENT_MEMORY_SEMANTIC_COLLECTION=
AI_AGENT_MEMORY_EMBEDDING_ENGINE=
AI_AGENT_MEMORY_EMBEDDING_MODEL=
AI_AGENT_MEMORY_VECTOR_SCOPE_FIELDS=user_id,tenant_id,workspace_id,session_id,namespace
AI_AGENT_INTENT_UNDERSTANDING_MODE=ai_first
AI_AGENT_INTENT_ENGINE=
AI_AGENT_INTENT_MODEL=
AI_AGENT_INTENT_FALLBACK_TO_HEURISTICS=true
```

## Self-Review

- Spec coverage: covers low-token chat memory, durable conversation memory, scope isolation, optional semantic retrieval, AI-aware package intent cleanup, docs, and tests.
- Placeholder scan: no task uses TBD/TODO/later placeholders.
- Type consistency: DTO/service names are consistent across tasks.
