<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Node\NodeMetadataDiscovery;
use LaravelAIEngine\Services\Node\NodeRegistryService;
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
        protected ?AgentSkillExecutionPlanner $skillPlanner = null
    ) {
        $this->messageClassifier ??= app()->bound(MessageRoutingClassifier::class)
            ? app(MessageRoutingClassifier::class)
            : new MessageRoutingClassifier();
        $this->routingContextResolver ??= new RoutingContextResolver($this->selectedEntityContext);
        $this->skillRegistry ??= app()->bound(AgentSkillRegistry::class) ? app(AgentSkillRegistry::class) : null;
        $this->skillMatcher ??= app()->bound(AgentSkillMatcher::class) ? app(AgentSkillMatcher::class) : null;
        $this->skillPlanner ??= app()->bound(AgentSkillExecutionPlanner::class) ? app(AgentSkillExecutionPlanner::class) : null;
    }

    public function route(string $message, UnifiedActionContext $context, array $options = []): array
    {
        $resources = $this->discoverResources($options);

        Log::channel('ai-engine')->info('IntentRouter resources discovered', [
            'collectors_count' => count($resources['collectors']),
            'collectors' => array_map(fn ($collector) => $collector['name'], $resources['collectors']),
            'skills_count' => count($resources['skills']),
            'tools_count' => count($resources['tools']),
            'nodes_count' => count($resources['nodes']),
        ]);

        $skillDecision = $this->matchSkillBeforeAi($message, $context);
        if ($skillDecision !== null) {
            return $this->enforceForwardedRequestPolicy($skillDecision, $options);
        }

        $prompt = $this->buildPrompt($message, $resources, $context);

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

        return $this->enforceForwardedRequestPolicy(
            $this->enforceStructuredQueryToolPolicy(
                $this->enforceActiveActionWorkflowPolicy($this->parseDecision($rawResponse, $message, $context, $options), $message, $context),
                $message,
                $context,
                $options,
                $resources
            ),
            $options
        );
    }

    protected function discoverResources(array $options): array
    {
        return [
            'tools' => $this->discoverTools($options),
            'skills' => $this->discoverSkills(),
            'action_workflows' => $this->discoverActionWorkflows(),
            'collectors' => $this->discoverCollectors($options),
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

        if (is_array($context->metadata['last_action_workflow'] ?? null)) {
            return null;
        }

        $match = $this->skillMatcher->match($message);
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

        return $tools;
    }

    protected function discoverActionWorkflows(): array
    {
        if (!app()->bound(\LaravelAIEngine\Services\Actions\ActionRegistry::class)) {
            return [];
        }

        try {
            $registry = app(\LaravelAIEngine\Services\Actions\ActionRegistry::class);

            return collect($registry->all(true))
                ->map(fn (array $action): array => [
                    'id' => (string) ($action['id'] ?? ''),
                    'label' => (string) ($action['label'] ?? $action['id'] ?? ''),
                    'module' => (string) ($action['module'] ?? 'default'),
                    'operation' => (string) ($action['operation'] ?? 'custom'),
                    'description' => (string) ($action['description'] ?? ''),
                    'required' => array_values((array) ($action['required'] ?? [])),
                    'parameters' => array_keys((array) ($action['parameters'] ?? [])),
                    'initial_payload' => (array) ($action['initial_payload'] ?? []),
                ])
                ->filter(fn (array $action): bool => $action['id'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Failed to discover action workflows', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function discoverCollectors(array $options = []): array
    {
        $collectors = [];

        try {
            $localCollectors = \LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::getConfigs();

            foreach ($localCollectors as $name => $configData) {
                $goal = (string) ($configData['goal'] ?? '');
                $description = (string) ($configData['description'] ?? '');
                $config = $configData['config'] ?? null;

                if (($goal === '' || $description === '') && $config instanceof \Closure) {
                    try {
                        $config = $config();
                    } catch (\Throwable) {
                        $config = null;
                    }
                }

                if ($goal === '' && is_object($config) && isset($config->goal)) {
                    $goal = (string) $config->goal;
                }

                if ($description === '' && is_object($config) && isset($config->description)) {
                    $description = (string) $config->description;
                }

                $collectors[] = [
                    'name' => $name,
                    'goal' => $goal,
                    'description' => $description,
                ];
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to discover local collectors', [
                'error' => $e->getMessage(),
            ]);
        }

        $localOnly = !empty($options['local_only']) || !config('ai-engine.nodes.enabled', true);
        if ($localOnly) {
            return $collectors;
        }

        try {
            $activeNodes = $this->nodeRegistry->getActiveNodes();

            foreach ($activeNodes as $node) {
                $autonomousCollectors = $node['autonomous_collectors'] ?? [];

                if (!is_array($autonomousCollectors)) {
                    continue;
                }

                foreach ($autonomousCollectors as $collector) {
                    if (!isset($collector['name'])) {
                        continue;
                    }

                    $collectors[] = [
                        'name' => $collector['name'],
                        'goal' => $collector['goal'] ?? '',
                        'description' => $collector['description'] ?? '',
                        'node' => $node['slug'] ?? 'unknown',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to discover remote collectors', [
                'error' => $e->getMessage(),
            ]);
        }

        return $collectors;
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

        $decision['action'] = 'search_rag';
        $decision['resource_name'] = null;
        $decision['reasoning'] = trim(($decision['reasoning'] ?? 'AI decision') . ' [forwarded request cannot re-route nodes]');

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

    protected function buildPrompt(string $message, array $resources, UnifiedActionContext $context): string
    {
        $history = $this->formatHistory($context);
        $pausedSessions = $context->get('session_stack', []);
        $discovery = new NodeMetadataDiscovery();
        $localNodeMeta = $discovery->discover();
        $localNodeMeta['slug'] = 'local';
        $selectedEntityContext = $this->formatSelectedEntityContext($context);
        $userProfile = $this->getUserProfile($context->userId);
        $entityContext = $this->formatEntityMetadata($context);
        $actionWorkflowContext = $this->formatActionWorkflowContext($context);

        return <<<PROMPT
You are an intent router. Choose exactly one action for the user's message.

USER PROFILE:
{$userProfile}

RECENT CONVERSATION:
{$history}

{$entityContext}

ACTIVE ACTION WORKFLOW:
{$actionWorkflowContext}

SELECTED ENTITY CONTEXT:
{$selectedEntityContext}

PAUSED SESSIONS: {$this->formatPausedSessions($pausedSessions)}

AVAILABLE RESOURCES:

Agent Skills:
{$this->formatSkills($resources['skills'] ?? [])}

Autonomous Collectors:
{$this->formatCollectors($resources['collectors'])}

Local Collections:
{$this->formatCollectors($localNodeMeta['collections'] ?? [])}

Model Tools:
{$this->formatTools($resources['tools'])}

Action Workflows:
{$this->formatActionWorkflows($resources['action_workflows'] ?? [])}

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
5) Use data_query through use_tool for exact local IDs, codes, invoice numbers, ticket numbers, SKUs, or other structured filters.
6) For Action Workflows, use use_tool with update_action_draft to start or continue draft collection. Params must include action_id, payload_patch, and reset=true only when starting a new workflow. Use initial_payload when provided by the workflow.
7) If ACTIVE ACTION WORKFLOW has relation_next_steps or next_options with relation_create_confirmation and the user confirms/proceeds/approves that relation, continue the active draft using update_action_draft with params {"action_id":"the active action_id","payload_patch":{"approved_missing_relations":["the approval_key"]}}. Do not start a standalone action for that related record.
8) If ACTIVE ACTION WORKFLOW exists and the user provides more details, corrections, dates, item details, relation details, or relation approval, continue the active action_id with update_action_draft unless the user explicitly says to cancel, restart, or switch to a different action instead.
9) If ACTIVE ACTION WORKFLOW awaits_final_confirmation=true and the user confirms/proceeds/approves creating it, use use_tool with execute_action and params {"action_id":"the active action_id","confirmed":true}. If the user corrects or adds details instead, use update_action_draft.
10) Prefer Agent Skills when a skill trigger matches the request. A skill describes the complete user-facing ability; use its first action with update_action_draft, first tool with use_tool, or its collector if listed.
11) Use start_collector only when a named Autonomous Collector exists and no Action Workflow, Agent Skill, or Model Tool fits. If no collectors are listed, do not choose start_collector.
12) Use conversational for greetings/general chat.
13) Use resume_session only for "resume/back".

Allowed actions:
- start_collector
- use_tool
- route_to_node
- resume_session
- pause_and_handle
- search_rag
- conversational

Respond with JSON ONLY using this schema:
{"action":"start_collector|use_tool|route_to_node|resume_session|pause_and_handle|search_rag|conversational","resource_name":"name or null","params":{"optional":"tool parameters"},"reasoning":"one short sentence"}
PROMPT;
    }

    protected function getUserProfile(?string $userId): string
    {
        if (!$userId) {
            return '- No user profile available';
        }

        try {
            $user = \App\Models\User::find($userId);
            if (!$user) {
                return "- User ID: {$userId} (profile not found)";
            }

            $profile = [
                "- Name: {$user->name}",
                "- Email: {$user->email}",
            ];

            if (isset($user->company)) {
                $profile[] = "- Company: {$user->company}";
            }
            if (isset($user->role)) {
                $profile[] = "- Role: {$user->role}";
            }
            if (isset($user->preferences) && is_array($user->preferences)) {
                $profile[] = '- Preferences: ' . json_encode($user->preferences);
            }

            return implode("\n", $profile);
        } catch (\Exception $e) {
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

        $recent = array_slice($messages, -5);
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

    protected function formatActionWorkflowContext(UnifiedActionContext $context): string
    {
        $workflow = $context->metadata['last_action_workflow'] ?? null;
        if (!is_array($workflow) || empty($workflow['action_id'])) {
            return '(none)';
        }

        return json_encode($workflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function enforceActiveActionWorkflowPolicy(array $decision, string $message, UnifiedActionContext $context): array
    {
        $workflow = $context->metadata['last_action_workflow'] ?? null;
        if (!is_array($workflow) || empty($workflow['action_id'])) {
            return $decision;
        }

        $relationOption = collect($workflow['next_options'] ?? $workflow['relation_next_steps'] ?? [])
            ->first(fn (mixed $option): bool => is_array($option) && ($option['type'] ?? null) === 'relation_create_confirmation');
        if (!is_array($relationOption) || !$this->looksLikeApproval($message)) {
            return $decision;
        }

        return [
            'action' => 'use_tool',
            'resource_name' => 'update_action_draft',
            'params' => [
                'action_id' => (string) $workflow['action_id'],
                'payload_patch' => [
                    'approved_missing_relations' => [
                        (string) ($relationOption['approval_key'] ?? $relationOption['field'] ?? true),
                    ],
                ],
            ],
            'reasoning' => 'The user approved a pending relation in the active action draft.',
        ];
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $options
     * @param array<string, mixed> $resources
     * @return array<string, mixed>
     */
    protected function enforceStructuredQueryToolPolicy(array $decision, string $message, UnifiedActionContext $context, array $options, array $resources): array
    {
        if (($decision['action'] ?? null) === 'use_tool') {
            return $decision;
        }

        if (!$this->hasTool($resources, 'data_query')) {
            return $decision;
        }

        $classification = $this->messageClassifier->classify(
            $message,
            $this->routingContextResolver->signalsFromContext($context, $options)
        );

        if (($classification['mode'] ?? null) !== 'structured_query') {
            return $decision;
        }

        return [
            'action' => 'use_tool',
            'resource_name' => 'data_query',
            'params' => [
                'query' => $message,
                'limit' => 10,
            ],
            'reasoning' => 'Structured local data request should use the query tool before semantic retrieval.',
            'decision_source' => 'structured_query_policy',
        ];
    }

    /**
     * @param array<string, mixed> $resources
     */
    protected function hasTool(array $resources, string $toolName): bool
    {
        return collect($resources['tools'] ?? [])
            ->contains(fn (mixed $tool): bool => is_array($tool) && ($tool['name'] ?? null) === $toolName);
    }

    protected function looksLikeApproval(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\b(no|not|don\'t|do not|cancel|stop|instead)\b/u', $normalized) === 1) {
            return false;
        }

        return preg_match('/\b(yes|approve|approved|confirm|create|add|go ahead|proceed|ok|okay|sure)\b/u', $normalized) === 1;
    }

    protected function formatPausedSessions(array $sessions): string
    {
        if (empty($sessions)) {
            return 'None';
        }

        return implode(', ', array_map(fn ($session) => $session['config_name'] ?? 'unknown', $sessions));
    }

    protected function formatCollectors(array $collectors): string
    {
        if (empty($collectors)) {
            return '   (No collectors available)';
        }

        $lines = [];
        foreach ($collectors as $collector) {
            $nodeName = $collector['node'] ?? 'local';
            $goal = $collector['goal'] ?? '';
            $description = $collector['description'] ?? '';
            $lines[] = "   - Name :{$collector['name']} Goal: {$goal} Description : {$description} Node: {$nodeName} ";
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
            $workflows = implode(', ', (array) ($skill['workflows'] ?? []));
            $lines[] = "   - {$skill['id']}: {$skill['name']}. {$skill['description']} Triggers: {$triggers}. Required: {$required}. Actions: {$actions}. Tools: {$tools}. Workflows: {$workflows}.";
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

    protected function formatActionWorkflows(array $actions): string
    {
        if (empty($actions)) {
            return '   (No action workflows available)';
        }

        $lines = [];
        foreach ($actions as $action) {
            $required = implode(', ', (array) ($action['required'] ?? []));
            $parameters = implode(', ', (array) ($action['parameters'] ?? []));
            $initialPayload = !empty($action['initial_payload'])
                ? ' Initial payload: ' . json_encode($action['initial_payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '';
            $lines[] = "   - {$action['id']} ({$action['operation']} {$action['module']}): {$action['label']}. {$action['description']} Required: {$required}. Parameters: {$parameters}.{$initialPayload}";
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
            'start_collector',
            'use_tool',
            'route_to_node',
            'resume_session',
            'pause_and_handle',
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
