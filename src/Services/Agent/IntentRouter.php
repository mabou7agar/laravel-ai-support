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
        protected ?RoutingContextResolver $routingContextResolver = null
    ) {
        $this->messageClassifier ??= app()->bound(MessageRoutingClassifier::class)
            ? app(MessageRoutingClassifier::class)
            : new MessageRoutingClassifier();
        $this->routingContextResolver ??= new RoutingContextResolver($this->selectedEntityContext);
    }

    public function route(string $message, UnifiedActionContext $context, array $options = []): array
    {
        $resources = $this->discoverResources($options);

        Log::channel('ai-engine')->info('IntentRouter resources discovered', [
            'collectors_count' => count($resources['collectors']),
            'collectors' => array_map(fn ($collector) => $collector['name'], $resources['collectors']),
            'tools_count' => count($resources['tools']),
            'nodes_count' => count($resources['nodes']),
        ]);

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
            $this->parseDecision($rawResponse, $message, $context, $options),
            $options
        );
    }

    protected function discoverResources(array $options): array
    {
        return [
            'tools' => $this->discoverTools($options),
            'collectors' => $this->discoverCollectors($options),
            'nodes' => $this->discoverNodes($options),
        ];
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

        return <<<PROMPT
You are an intent router. Choose exactly one action for the user's message.

USER PROFILE:
{$userProfile}

RECENT CONVERSATION:
{$history}

{$entityContext}

SELECTED ENTITY CONTEXT:
{$selectedEntityContext}

PAUSED SESSIONS: {$this->formatPausedSessions($pausedSessions)}

AVAILABLE RESOURCES:

Autonomous Collectors:
{$this->formatCollectors($resources['collectors'])}

Local Collections:
{$this->formatCollectors($localNodeMeta['collections'] ?? [])}

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
4) Prefer search_rag for local viewing/search/listing questions.
5) Use start_collector for create/update/delete requests.
6) Use conversational for greetings/general chat.
7) Use resume_session only for "resume/back".

Allowed actions:
- start_collector
- use_tool
- route_to_node
- resume_session
- pause_and_handle
- search_rag
- conversational

Respond with JSON ONLY using this schema:
{"action":"start_collector|use_tool|route_to_node|resume_session|pause_and_handle|search_rag|conversational","resource_name":"name or null","reasoning":"one short sentence"}
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

        if (empty($messages) || count($messages) <= 1) {
            return '(New conversation)';
        }

        $recent = array_slice($messages, -5);
        $lines = [];

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

    protected function formatTools(array $tools): string
    {
        if (empty($tools)) {
            return '   (No tools available)';
        }

        $lines = [];
        foreach ($tools as $tool) {
            $lines[] = "   - {$tool['name']} ({$tool['model']}): {$tool['description']}";
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
