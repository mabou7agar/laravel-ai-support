<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Node\NodeMetadataDiscovery;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;

class IntentRouter
{
    public function __construct(
        protected AIEngineService $ai,
        protected NodeRegistryService $nodeRegistry,
        protected SelectedEntityContextService $selectedEntityContext,
        protected ?AgentManifestService $manifestService = null,
        protected ?MessageRoutingClassifier $messageClassifier = null,
        protected ?RoutingContextResolver $routingContextResolver = null,
        protected ?AgentSkillRegistry $skillRegistry = null,
        protected ?AgentSkillMatcher $skillMatcher = null,
        protected ?AgentSkillExecutionPlanner $skillPlanner = null,
        protected ?RAGPromptPolicyService $promptPolicyService = null,
        protected ?IntentSignalService $intentSignals = null
    ) {
        $this->messageClassifier ??= app()->bound(MessageRoutingClassifier::class)
            ? app(MessageRoutingClassifier::class)
            : new MessageRoutingClassifier();
        $this->routingContextResolver ??= new RoutingContextResolver($this->selectedEntityContext);
        $this->skillRegistry ??= app()->bound(AgentSkillRegistry::class) ? app(AgentSkillRegistry::class) : null;
        $this->skillMatcher ??= app()->bound(AgentSkillMatcher::class) ? app(AgentSkillMatcher::class) : null;
        $this->skillPlanner ??= app()->bound(AgentSkillExecutionPlanner::class) ? app(AgentSkillExecutionPlanner::class) : null;
        $this->promptPolicyService ??= app()->bound(RAGPromptPolicyService::class)
            ? app(RAGPromptPolicyService::class)
            : null;
    }

    public function route(string $message, UnifiedActionContext $context, array $options = []): array
    {
        $resources = $this->discoverResources($options);

        $capabilityCounts = [
            'skills_count' => count($resources['skills']),
            'tools_count' => count($resources['tools']),
            'nodes_count' => count($resources['nodes']),
        ];

        Log::channel('ai-engine')->info('IntentRouter resources discovered', $capabilityCounts);

        // If the orchestrator has nothing to route to, every request degrades to
        // a plain conversational reply. Surface that loudly so it is diagnosable
        // instead of silently behaving like a basic chatbot.
        if ($capabilityCounts['skills_count'] === 0
            && $capabilityCounts['tools_count'] === 0
            && $capabilityCounts['nodes_count'] === 0
        ) {
            Log::channel('ai-engine')->warning(
                'IntentRouter has no registered tools, skills, or nodes — the orchestrator will ' .
                'default to conversational/RAG only. Register tools (app/AI/Tools or ModelToolConfig), ' .
                'skills (app/AI/Skills), or enable nodes to make actions routable.',
                $capabilityCounts
            );
        }

        $skillDecision = $this->matchSkillBeforeAi($message, $context);
        if ($skillDecision !== null) {
            return $this->withCapabilityCounts(
                $this->enforceForwardedRequestPolicy($skillDecision, $options),
                $capabilityCounts
            );
        }

        $promptPolicy = $this->resolvePromptPolicy($context, $options);
        $prompt = $this->buildPrompt($message, $resources, $context, $promptPolicy);

        Log::channel('ai-engine')->debug('IntentRouter prompt', [
            'prompt' => $prompt,
            'message' => $message,
            'session_id' => $context->sessionId,
        ]);

        $request = new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from(config('ai-engine.default', 'openai')),
            model: EntityEnum::from(config('ai-engine.orchestration_model', 'gpt-4o-mini')),
            maxTokens: 300,
            temperature: 0.1
        );

        $aiResponse = $this->ai->generate($request);
        $rawResponse = $aiResponse->getContent();

        Log::channel('ai-engine')->debug('IntentRouter raw response', [
            'raw_response' => $rawResponse,
            'session_id' => $context->sessionId,
        ]);

        return $this->withCapabilityCounts(
            $this->withPromptPolicyMetadata($this->enforceForwardedRequestPolicy(
                $this->enforceStructuredQueryToolPolicy(
                    $this->parseDecision($rawResponse, $message, $context, $options),
                    $message,
                    $context,
                    $options,
                    $resources
                ),
                $options
            ), $promptPolicy),
            $capabilityCounts
        );
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, int> $counts
     * @return array<string, mixed>
     */
    private function withCapabilityCounts(array $decision, array $counts): array
    {
        $decision['capability_counts'] = $counts;

        return $decision;
    }

    protected function discoverResources(array $options): array
    {
        return [
            'tools' => $this->discoverTools($options),
            'skills' => $this->discoverSkills(),
            'nodes' => $this->discoverNodes($options),
        ];
    }

    protected function discoverSkills(): array
    {
        if ($this->skillRegistry === null) {
            return [];
        }

        try {
            return array_map(static fn ($skill): array => $skill->toArray(), $this->skillRegistry->skills());
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Failed to discover skills', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function matchSkillBeforeAi(string $message, UnifiedActionContext $context): ?array
    {
        if (!(bool) config('ai-agent.skills.prefer_deterministic_matches', true)) {
            return null;
        }

        if ($this->skillMatcher === null || $this->skillPlanner === null) {
            return null;
        }

        $match = $this->skillMatcher->matchIntent($message, $context);
        if ($match === null) {
            return null;
        }

        $decision = $this->skillPlanner->plan($match['skill'], $message, $context, $match);

        Log::channel('ai-engine')->debug('IntentRouter matched skill before AI routing', [
            'skill_id' => $match['skill']->id,
            'score' => $match['score'],
            'trigger' => $match['trigger'],
            'action' => $decision['action'] ?? null,
            'resource_name' => $decision['resource_name'] ?? null,
        ]);

        return $decision;
    }

    protected function discoverTools(array $options): array
    {
        $tools = [];
        $modelConfigs = $options['model_configs'] ?? $this->discoverModelConfigs();

        foreach ($modelConfigs as $configClass) {
            if (!method_exists($configClass, 'getTools')) {
                continue;
            }

            try {
                $configTools = $configClass::getTools();
                $modelName = method_exists($configClass, 'getName')
                    ? $configClass::getName()
                    : class_basename($configClass);

                foreach ($configTools as $toolName => $toolDef) {
                    $tools[] = [
                        'name' => $toolName,
                        'model' => $modelName,
                        'description' => $toolDef['description'] ?? '',
                        'parameters' => is_array($toolDef['parameters'] ?? null) ? $toolDef['parameters'] : [],
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('Failed to get tools from config', [
                    'config' => $configClass,
                ]);
            }
        }

        try {
            foreach (app(ToolRegistry::class)->getToolDefinitions() as $tool) {
                $name = (string) ($tool['name'] ?? '');
                if ($name === '' || collect($tools)->contains(fn (array $existing): bool => ($existing['name'] ?? null) === $name)) {
                    continue;
                }

                $tools[] = [
                    'name' => $name,
                    'model' => 'agent_tool',
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => is_array($tool['parameters'] ?? null) ? $tool['parameters'] : [],
                    'requires_confirmation' => (bool) ($tool['requires_confirmation'] ?? false),
                ];
            }
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->debug('Failed to get tools from registry', [
                'error' => $e->getMessage(),
            ]);
        }

        return $tools;
    }

    protected function discoverNodes(array $options = []): array
    {
        if (!empty($options['is_forwarded']) || !empty($options['local_only']) || !config('ai-engine.nodes.enabled', true)) {
            return [];
        }

        $nodes = [];
        $activeNodes = $this->nodeRegistry->getActiveNodes();

        foreach ($activeNodes as $node) {
            $nodes[] = [
                'slug' => is_array($node) ? ($node['slug'] ?? '') : $node->slug,
                'name' => is_array($node) ? ($node['name'] ?? '') : $node->name,
                'description' => is_array($node) ? ($node['description'] ?? '') : ($node->description ?? ''),
                'domains' => is_array($node) ? ($node['domains'] ?? []) : ($node->domains ?? []),
            ];
        }

        return $nodes;
    }

    protected function enforceForwardedRequestPolicy(array $decision, array $options): array
    {
        if (empty($options['is_forwarded']) || ($decision['action'] ?? null) !== 'route_to_node') {
            return $decision;
        }

        $originalAction = $decision['action'];
        $originalResource = $decision['resource_name'] ?? null;

        Log::channel('ai-engine')->warning('Forwarded request policy overrode route_to_node decision', [
            'original_action' => $originalAction,
            'original_resource_name' => $originalResource,
            'enforced_action' => 'search_rag',
            'decision_source' => $decision['decision_source'] ?? null,
        ]);

        $decision['action'] = 'search_rag';
        $decision['resource_name'] = null;
        $decision['reasoning'] = trim(($decision['reasoning'] ?? 'AI decision') . ' [forwarded request cannot re-route nodes]');

        $metadata = is_array($decision['metadata'] ?? null) ? $decision['metadata'] : [];
        $metadata['policy_enforced'] = 'forwarded_request_no_reroute';
        $metadata['original_action'] = $originalAction;
        $metadata['original_resource_name'] = $originalResource;
        $decision['metadata'] = $metadata;

        return $decision;
    }

    protected function discoverModelConfigs(): array
    {
        $manifestConfigs = $this->manifest()->modelConfigs();
        $configs = $manifestConfigs;
        if (!$this->manifest()->fallbackDiscoveryEnabled()) {
            return array_values(array_unique($configs));
        }

        $configPath = app_path('AI/Configs');
        if (!is_dir($configPath)) {
            return array_values(array_unique($configs));
        }

        $files = glob($configPath . '/*ModelConfig.php');

        foreach ($files as $file) {
            $className = 'App\\AI\\Configs\\' . basename($file, '.php');
            if (class_exists($className)) {
                $configs[] = $className;
            }
        }

        return array_values(array_unique($configs));
    }

    protected function manifest(): AgentManifestService
    {
        if ($this->manifestService === null) {
            $this->manifestService = app(AgentManifestService::class);
        }

        return $this->manifestService;
    }

    protected function buildPrompt(string $message, array $resources, UnifiedActionContext $context, array $promptPolicy = []): string
    {
        $history = $this->formatHistory($context);
        $discovery = new NodeMetadataDiscovery();
        $localNodeMeta = $discovery->discover();
        $localNodeMeta['slug'] = 'local';
        $selectedEntityContext = $this->formatSelectedEntityContext($context);
        $userProfile = $this->getUserProfile($context->userId);
        $entityContext = $this->formatEntityMetadata($context);
        $policyContext = $this->formatPromptPolicy($promptPolicy);

        return <<<PROMPT
You are an intent router. Choose exactly one action for the user's message.

DECISION PROMPT POLICY:
{$policyContext}

USER PROFILE:
{$userProfile}

RECENT CONVERSATION:
{$history}

{$entityContext}

SELECTED ENTITY CONTEXT:
{$selectedEntityContext}

AVAILABLE RESOURCES:

Agent Skills:
{$this->formatSkills($resources['skills'] ?? [])}

Local Collections:
{$this->formatCollections($localNodeMeta['collections'] ?? [])}

Model Tools:
{$this->formatTools($resources['tools'])}

Remote Nodes:
{$this->formatNodes($resources['nodes'])}

Local Node:
{$this->formatNodes([$localNodeMeta])}

USER MESSAGE: "{$message}"

Routing rules:
1) Preserve follow-up context. If user refers to "first/second/it", use selected/entity context.
2) Use route_to_node only when the requested domain belongs to a remote node.
3) Never route_to_node for "local".
4) Use data_query through use_tool for local list/show/count/filter questions when a target entity/table/model is named. Pass the original query if exact model/table parameters are uncertain.
5) Use data_query through use_tool for exact local IDs, codes, reference numbers, SKUs, or other structured filters.
6) Prefer Agent Skills when a skill trigger matches the request. A skill describes the complete user-facing ability; use its declared tool when listed.
7) Use conversational for greetings/general chat.

Allowed actions:
- use_tool
- route_to_node
- search_rag
- conversational

Respond with JSON ONLY using this schema:
{"action":"use_tool|route_to_node|search_rag|conversational","resource_name":"name or null","params":{"optional":"tool parameters"},"reasoning":"one short sentence"}
PROMPT;
    }

    protected function resolvePromptPolicy(UnifiedActionContext $context, array $options): array
    {
        if (!$this->promptPolicyService instanceof RAGPromptPolicyService) {
            return [];
        }

        try {
            return $this->promptPolicyService->resolveForRuntime([
                'session_id' => $context->sessionId,
                'user_id' => $context->userId,
                'tenant_id' => $options['tenant_id'] ?? $options['tenant'] ?? null,
                'app_id' => $options['app_id'] ?? null,
                'domain' => $options['domain'] ?? null,
                'locale' => $options['locale'] ?? app()->getLocale(),
            ], $options['decision_policy_key'] ?? null);
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('IntentRouter prompt policy resolution failed', [
                'error' => $e->getMessage(),
                'session_id' => $context->sessionId,
            ]);

            return [];
        }
    }

    protected function formatPromptPolicy(array $promptPolicy): string
    {
        $selected = $promptPolicy['selected'] ?? null;
        if (!$selected) {
            return '- Default routing policy.';
        }

        $template = trim((string) ($selected->template ?? ''));
        $rules = (array) ($selected->rules ?? []);
        $lines = [
            '- Policy selection: ' . (string) ($promptPolicy['selection'] ?? 'active'),
            '- Policy key: ' . (string) ($selected->policy_key ?? 'default'),
            '- Policy version: ' . (string) ($selected->version ?? 'unknown'),
        ];

        if ($template !== '') {
            $lines[] = "Instructions:\n{$template}";
        }

        if ($rules !== []) {
            $lines[] = 'Rules: ' . json_encode($rules, JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    protected function withPromptPolicyMetadata(array $decision, array $promptPolicy): array
    {
        $selected = $promptPolicy['selected'] ?? null;
        if (!$selected) {
            return $decision;
        }

        $metadata = is_array($decision['metadata'] ?? null) ? $decision['metadata'] : [];
        $metadata['prompt_policy'] = array_filter([
            'id' => $selected->id ?? null,
            'policy_key' => $selected->policy_key ?? null,
            'version' => $selected->version ?? null,
            'status' => $selected->status ?? null,
            'selection' => $promptPolicy['selection'] ?? null,
            'scope_key' => $selected->scope_key ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $decision['metadata'] = $metadata;

        return $decision;
    }

    protected function getUserProfile(int|string|null $userId): string
    {
        $userId = $userId !== null ? (string) $userId : null;
        if (!$userId) {
            return '- No user profile available';
        }

        try {
            $modelClass = config('auth.providers.users.model');
            if (!is_string($modelClass) || !class_exists($modelClass) || !method_exists($modelClass, 'find')) {
                return "- User ID: {$userId} (profile unavailable)";
            }

            $user = $modelClass::find($userId);
            if (!$user) {
                return "- User ID: {$userId} (profile not found)";
            }

            $profile = [
                '- Name: ' . (data_get($user, 'name') ?: 'unavailable'),
                '- Email: ' . (data_get($user, 'email') ?: 'unavailable'),
            ];

            if (data_get($user, 'company') !== null) {
                $profile[] = '- Company: ' . data_get($user, 'company');
            }
            if (data_get($user, 'role') !== null) {
                $profile[] = '- Role: ' . data_get($user, 'role');
            }
            if (is_array(data_get($user, 'preferences'))) {
                $profile[] = '- Preferences: ' . json_encode(data_get($user, 'preferences'));
            }

            return implode("\n", $profile);
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Failed to fetch user profile', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return "- User ID: {$userId}";
        }
    }

    protected function formatHistory(UnifiedActionContext $context): string
    {
        $messages = $context->conversationHistory;
        $summary = trim((string) ($context->metadata['conversation_summary'] ?? ''));

        if (empty($messages) || count($messages) <= 1) {
            return $summary !== ''
                ? "Earlier conversation summary:\n{$summary}"
                : '(New conversation)';
        }

        $recent = array_slice($messages, -$this->recentConversationMessageLimit());
        $lines = $summary !== ''
            ? ["Earlier conversation summary:\n{$summary}", 'Recent conversation:']
            : [];

        foreach ($recent as $msg) {
            $role = ucfirst($msg['role']);
            $content = $msg['content'];
            $hasNumberedOptions = preg_match('/\b\d+[\.\)]\s+/m', $content);

            $lines[] = '   ' . $role . ': ' . substr($content, 0, $hasNumberedOptions ? 1000 : 300);
        }

        return implode("\n", $lines);
    }

    protected function recentConversationMessageLimit(): int
    {
        $configured = (int) config(
            'ai-engine.conversation_history.recent_messages',
            config('ai-agent.context_compaction.keep_recent_messages', 6)
        );

        return max(5, $configured);
    }

    protected function formatSelectedEntityContext(UnifiedActionContext $context): string
    {
        $selected = $this->selectedEntityContext->getFromContext($context);
        if (!$selected) {
            return '(none)';
        }

        return json_encode($selected, JSON_PRETTY_PRINT);
    }

    protected function formatEntityMetadata(UnifiedActionContext $context): string
    {
        $messages = array_reverse($context->conversationHistory);

        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) !== 'assistant' || empty($msg['metadata']['entity_ids'])) {
                continue;
            }

            $entityIds = $msg['metadata']['entity_ids'];
            $entityType = $msg['metadata']['entity_type'] ?? 'item';

            return "ENTITY CONTEXT (from last response):\n"
                . "Type: {$entityType}\n"
                . 'IDs: ' . json_encode($entityIds) . "\n"
                . 'Note: If user refers to positions (1, 2, first, etc.), map to these IDs in order.' . "\n";
        }

        return '';
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $options
     * @param array<string, mixed> $resources
     * @return array<string, mixed>
     */
    protected function enforceStructuredQueryToolPolicy(array $decision, string $message, UnifiedActionContext $context, array $options, array $resources): array
    {
        $action = $decision['action'] ?? null;
        $available = $this->availableToolNames($resources);
        $hasDataQuery = in_array('data_query', $available, true);
        $isStructured = ($this->messageClassifier->classify(
            $message,
            $this->routingContextResolver->signalsFromContext($context, $options)
        )['mode'] ?? null) === 'structured_query';

        // The router chose use_tool. If the tool is actually registered, keep it.
        // If it picked a tool that does not exist (a hallucinated name), do NOT pass
        // it to the executor — that would silently fall back to RAG. Redirect here so
        // the decision is correct and observable.
        if ($action === 'use_tool') {
            $resource = $decision['resource_name'] ?? null;

            if (!is_string($resource) || $resource === '' || in_array($resource, $available, true)) {
                return $decision;
            }

            if ($hasDataQuery && $isStructured) {
                return $this->dataQueryDecision($message, 'Requested tool is not registered; routing structured query to data_query.');
            }

            $route = $this->ragEnabled($options) ? 'search_rag' : 'conversational';

            return array_merge($decision, [
                'action' => $route,
                'resource_name' => null,
                'reasoning' => sprintf('Router selected unregistered tool "%s"; routed to %s instead.', $resource, $route),
                'decision_source' => 'unregistered_tool_redirect',
            ]);
        }

        if (!$isStructured) {
            return $decision;
        }

        // Structured list/count/filter query. Prefer a registered data_query tool;
        // otherwise fall back to structured retrieval so it works out of the box,
        // but only upgrade a weak conversational default (never override an explicit
        // route_to_node / search_rag decision).
        if ($hasDataQuery) {
            return $this->dataQueryDecision($message, 'Structured local data request should use the query tool before semantic retrieval.');
        }

        if ($action === 'conversational' && $this->ragEnabled($options)) {
            return array_merge($decision, [
                'action' => 'search_rag',
                'resource_name' => null,
                'reasoning' => 'Structured query routed to structured retrieval (no data_query tool registered).',
                'decision_source' => 'structured_query_rag',
                'preclassified_route_mode' => 'structured_query',
            ]);
        }

        return $decision;
    }

    /**
     * @param array<string, mixed> $resources
     */
    protected function hasTool(array $resources, string $toolName): bool
    {
        return in_array($toolName, $this->availableToolNames($resources), true);
    }

    /**
     * Names of every tool actually available to route to.
     *
     * @param array<string, mixed> $resources
     * @return array<int, string>
     */
    protected function availableToolNames(array $resources): array
    {
        $names = [];

        foreach (($resources['tools'] ?? []) as $tool) {
            $name = is_array($tool) ? ($tool['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataQueryDecision(string $message, string $reasoning): array
    {
        return [
            'action' => 'use_tool',
            'resource_name' => 'data_query',
            'params' => [
                'query' => $message,
                'limit' => 10,
            ],
            'reasoning' => $reasoning,
            'decision_source' => 'structured_query_policy',
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function ragEnabled(array $options): bool
    {
        if (!empty($options['force_rag'])) {
            return true;
        }

        return !array_key_exists('use_rag', $options) || (bool) $options['use_rag'];
    }

    protected function looksLikeApproval(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        if ($this->signals()->isNegative($normalized)) {
            return false;
        }

        return $this->signals()->isAffirmative($normalized);
    }

    protected function signals(): IntentSignalService
    {
        return $this->intentSignals ??= app(IntentSignalService::class);
    }

    protected function formatCollections(array $collections): string
    {
        if (empty($collections)) {
            return '   (No resources available)';
        }

        $lines = [];
        foreach ($collections as $collection) {
            $nodeName = $collection['node'] ?? 'local';
            $name = $collection['name'] ?? ($collection['table'] ?? 'unknown');
            $description = $collection['description'] ?? '';
            $lines[] = "   - Name: {$name}. Description: {$description}. Node: {$nodeName}.";
        }

        return implode("\n", $lines);
    }

    protected function formatSkills(array $skills): string
    {
        if (empty($skills)) {
            return '   (No skills available)';
        }

        $lines = [];
        foreach ($skills as $skill) {
            if (!is_array($skill)) {
                continue;
            }

            $triggers = implode(', ', array_slice((array) ($skill['triggers'] ?? []), 0, 5));
            $required = implode(', ', (array) ($skill['required_data'] ?? []));
            $actions = implode(', ', (array) ($skill['actions'] ?? []));
            $tools = implode(', ', (array) ($skill['tools'] ?? []));
            $prompt = trim((string) ($skill['prompt'] ?? data_get($skill, 'metadata.prompt', '')));
            $promptText = $prompt !== '' ? " Prompt: {$prompt}." : '';
            $relations = data_get($skill, 'metadata.relations', []);
            $relationText = is_array($relations) && $relations !== []
                ? ' Relations: ' . json_encode($relations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '.'
                : '';
            $lines[] = "   - {$skill['id']}: {$skill['name']}. {$skill['description']}{$promptText} Triggers: {$triggers}. Required: {$required}. Actions: {$actions}. Tools: {$tools}.{$relationText}";
        }

        return $lines !== [] ? implode("\n", $lines) : '   (No skills available)';
    }

    protected function formatTools(array $tools): string
    {
        if (empty($tools)) {
            return '   (No tools available)';
        }

        $lines = [];
        foreach ($tools as $tool) {
            $parameters = isset($tool['parameters']) && is_array($tool['parameters'])
                ? ' Params: ' . implode(', ', array_keys($tool['parameters']))
                : '';
            $lines[] = "   - {$tool['name']} ({$tool['model']}): {$tool['description']}{$parameters}";
        }

        return implode("\n", $lines);
    }

    protected function formatNodes(array $nodes): string
    {
        if (empty($nodes)) {
            return '   (No nodes available)';
        }

        $lines = [];
        foreach ($nodes as $node) {
            $domains = implode(', ', $node['domains'] ?? []);
            $slug = $node['slug'] ?? 'unknown';
            $description = $node['description'] ?? '';
            $lines[] = "   - {$slug}: {$description} [Domains: {$domains}]";
        }

        return implode("\n", $lines);
    }

    protected function parseDecision(string $response, string $message, UnifiedActionContext $context, array $options = []): array
    {
        $allowedActions = [
            'use_tool',
            'route_to_node',
            'search_rag',
            'conversational',
        ];

        $decoded = $this->decodeRouterDecision($response);
        $action = strtolower(trim((string) ($decoded['action'] ?? '')));

        if (!in_array($action, $allowedActions, true)) {
            $classification = $this->messageClassifier->classify(
                $message,
                $this->routingContextResolver->signalsFromContext($context, $options)
            );

            return [
                'action' => $classification['route'] === 'search_rag' ? 'search_rag' : 'conversational',
                'resource_name' => null,
                'reasoning' => 'Heuristic fallback: ' . $classification['reason'],
                'decision_source' => 'heuristic_fallback',
            ];
        }

        $resourceName = $decoded['resource_name'] ?? null;
        if (!is_string($resourceName) || trim($resourceName) === '' || in_array(strtolower(trim($resourceName)), ['none', 'null'], true)) {
            $resourceName = null;
        }

        if ($action === 'use_tool' && $resourceName === null) {
            $classification = $this->messageClassifier->classify(
                $message,
                $this->routingContextResolver->signalsFromContext($context, $options)
            );

            return [
                'action' => $classification['route'] === 'search_rag' ? 'search_rag' : 'conversational',
                'resource_name' => null,
                'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : [],
                'reasoning' => 'Heuristic fallback: router selected use_tool without a tool name.',
                'decision_source' => 'heuristic_fallback',
            ];
        }

        $reasoning = trim((string) ($decoded['reasoning'] ?? 'AI routing decision'));

        return [
            'action' => $action,
            'resource_name' => $resourceName,
            'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : [],
            'reasoning' => $reasoning !== '' ? $reasoning : 'AI routing decision',
            'decision_source' => 'router_ai',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeRouterDecision(string $response): array
    {
        $trimmed = trim($response);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
