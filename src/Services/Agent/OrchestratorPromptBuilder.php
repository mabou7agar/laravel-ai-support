<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Node\NodeMetadataDiscovery;
use LaravelAIEngine\Services\Node\NodeRoutingDigestService;

class OrchestratorPromptBuilder
{
    public function __construct(
        protected FollowUpStateService $followUpStateService,
        protected UserProfileResolver $userProfileResolver,
        protected NodeMetadataDiscovery $nodeMetadataDiscovery,
        protected array $config = [],
        protected ?NodeRoutingDigestService $digestService = null
    ) {
    }

    public function build(string $message, array $resources, UnifiedActionContext $context): string
    {
        $history = $this->formatHistory($context);
        $pausedSessions = $context->get('session_stack', []);
        $localNodeMeta = $this->discoverLocalNodeMeta();
        $selectedEntityContext = $this->formatSelectedEntityContext($context);
        $entityContext = $this->formatEntityMetadata($context);
        $userProfile = $this->userProfileResolver->resolve($context->userId);

        $allowedActions = (array) $this->getConfig('allowed_actions', [
            'start_collector',
            'use_tool',
            'route_to_node',
            'resume_session',
            'pause_and_handle',
            'search_rag',
            'conversational',
        ]);

        $actionDescriptions = (array) $this->getConfig('action_descriptions', [
            'start_collector' => 'When user wants to create, update, or delete data',
            'search_rag' => 'When user wants to view, list, search, or get information from local indexed data',
            'conversational' => 'For greetings and general chat',
            'route_to_node' => 'When user wants data from a remote node domain',
            'resume_session' => 'When user asks to resume or go back to a paused workflow',
            'pause_and_handle' => 'When user interrupts an active workflow to handle another request',
            'use_tool' => 'When a specific tool should be executed directly',
        ]);

        $actionLines = [];
        foreach ($allowedActions as $action) {
            $description = $actionDescriptions[$action] ?? 'Use when this action best matches intent';
            $actionLines[] = "- {$action}: {$description}";
        }

        $instructions = (array) $this->getConfig('instructions', [
            'Analyze conversation history and user message before choosing an action.',
            'Preserve selected entity context and avoid asking user to repeat IDs when context is sufficient.',
            'When user asks a follow-up about already listed entities, avoid re-listing unless explicitly requested.',
            'If routing to a node, never choose local as route_to_node target.',
            'Use concise and deterministic reasoning.',
        ]);

        $instructionsText = '';
        foreach ($instructions as $instruction) {
            $instructionsText .= "- {$instruction}\n";
        }

        $nodeRoutingGuide = $this->buildNodeRoutingGuide($localNodeMeta, $resources);

        return <<<PROMPT
You are an AI orchestrator. Decide what to do with this message.

USER PROFILE:
{$userProfile}

CONVERSATION HISTORY:
{$history}

{$entityContext}

SELECTED ENTITY CONTEXT:
{$selectedEntityContext}

PAUSED SESSIONS: {$this->formatPausedSessions($pausedSessions)}

AVAILABLE RESOURCES:

Autonomous Collectors:
{$this->formatCollectors($resources['collectors'] ?? [])}

Available Local Collections:
{$this->formatCollections($resources['collections'] ?? [])}

Model Tools:
{$this->formatTools($resources['tools'] ?? [])}

NODE ROUTING GUIDE:
{$nodeRoutingGuide}

USER: "{$message}"

DECISION RULES:
{$instructionsText}

Choose the most appropriate action:
{$this->formatActionLines($actionLines)}

RESPOND WITH EXACTLY THIS FORMAT:
ACTION: <{$this->formatAllowedActionList($allowedActions)}>
RESOURCE: <name or "none">
REASON: <why>
PROMPT;
    }

    protected function discoverLocalNodeMeta(): array
    {
        try {
            $localNodeMeta = $this->nodeMetadataDiscovery->discover();
            if (!is_array($localNodeMeta)) {
                $localNodeMeta = [];
            }
        } catch (\Throwable $e) {
            $localNodeMeta = [];
        }

        $localNodeMeta['slug'] = $localNodeMeta['slug'] ?? 'local';
        $localNodeMeta['description'] = $localNodeMeta['description'] ?? 'Local node';
        $localNodeMeta['domains'] = is_array($localNodeMeta['domains'] ?? null) ? $localNodeMeta['domains'] : [];
        $localNodeMeta['collections'] = is_array($localNodeMeta['collections'] ?? null) ? $localNodeMeta['collections'] : [];

        return $localNodeMeta;
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
            $role = ucfirst((string) ($msg['role'] ?? 'unknown'));
            $content = (string) ($msg['content'] ?? '');
            $hasNumberedOptions = preg_match('/\b\d+[\.\)]\s+/m', $content) === 1;
            $content = $hasNumberedOptions ? substr($content, 0, 1000) : substr($content, 0, 300);
            $lines[] = "   {$role}: {$content}";
        }

        return implode("\n", $lines);
    }

    protected function formatSelectedEntityContext(UnifiedActionContext $context): string
    {
        $selectedEntity = $this->followUpStateService->getSelectedEntityContext($context);
        if (is_array($selectedEntity) && !empty($selectedEntity['entity_id'])) {
            return json_encode($selectedEntity, JSON_PRETTY_PRINT);
        }

        return '(none)';
    }

    protected function formatEntityMetadata(UnifiedActionContext $context): string
    {
        $entityContext = $this->followUpStateService->formatEntityListContext($context);
        if ($entityContext === '(none)') {
            return '';
        }

        return "ENTITY CONTEXT (from recent results):\n"
            . $entityContext
            . "\nNote: If user refers to positions (1, 2, first, etc.), map to these IDs in order.\n";
    }

    protected function formatPausedSessions(array $sessions): string
    {
        if (empty($sessions)) {
            return 'None';
        }

        return implode(', ', array_map(fn ($s) => $s['config_name'] ?? 'unknown', $sessions));
    }

    protected function formatCollectors(array $collectors): string
    {
        if (empty($collectors)) {
            return '   (No collectors available)';
        }

        $lines = [];
        foreach ($collectors as $collector) {
            if (!is_array($collector)) {
                continue;
            }
            $nodeName = $collector['node'] ?? 'local';
            $goal = $collector['goal'] ?? '';
            $description = $collector['description'] ?? '';
            $name = $collector['name'] ?? 'unknown';
            $lines[] = "   - Name: {$name} Goal: {$goal} Description: {$description} Node: {$nodeName}";
        }

        return empty($lines) ? '   (No collectors available)' : implode("\n", $lines);
    }

    protected function formatCollections(array $collections): string
    {
        if (empty($collections)) {
            return '   (No local collections available)';
        }

        $lines = [];
        foreach ($collections as $collection) {
            if (is_string($collection)) {
                $lines[] = '   - ' . $collection;
                continue;
            }
            if (!is_array($collection)) {
                continue;
            }

            $name = $collection['name'] ?? $collection['class'] ?? 'unknown';
            $description = $collection['description'] ?? '';
            $lines[] = "   - {$name}: {$description}";
        }

        return empty($lines) ? '   (No local collections available)' : implode("\n", $lines);
    }

    protected function formatTools(array $tools): string
    {
        if (empty($tools)) {
            return '   (No tools available)';
        }

        $lines = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = $tool['name'] ?? 'unknown';
            $model = $tool['model'] ?? 'generic';
            $description = $tool['description'] ?? '';
            $lines[] = "   - {$name} ({$model}): {$description}";
        }

        return empty($lines) ? '   (No tools available)' : implode("\n", $lines);
    }

    /**
     * Build the NODE ROUTING GUIDE section.
     *
     * Uses NodeRoutingDigestService if available (cached, compact, token-efficient).
     * Falls back to raw metadata formatting if the digest service is not bound.
     */
    protected function buildNodeRoutingGuide(array $localNodeMeta, array $resources): string
    {
        if ($this->digestService !== null) {
            try {
                return $this->digestService->getFullDigest($localNodeMeta);
            } catch (\Throwable $e) {
                // Fall through to legacy formatting
            }
        }

        // Legacy: format raw metadata (backward compatible)
        return $this->formatNodesLegacy($resources['nodes'] ?? [], $localNodeMeta);
    }

    /**
     * Legacy node formatting — used when NodeRoutingDigestService is not available.
     */
    protected function formatNodesLegacy(array $remoteNodes, array $localNodeMeta): string
    {
        $lines = [];

        if (!empty($remoteNodes)) {
            $lines[] = 'REMOTE NODES:';
            foreach ($remoteNodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $slug = $node['slug'] ?? 'unknown';
                $description = $node['description'] ?? '';
                $domains = is_array($node['domains'] ?? null) ? implode(', ', $node['domains']) : '';
                $lines[] = "   - {$slug}: {$description} [Domains: {$domains}]";
            }
        }

        $lines[] = '';
        $lines[] = 'LOCAL NODE:';
        $slug = $localNodeMeta['slug'] ?? 'local';
        $description = $localNodeMeta['description'] ?? '';
        $domains = is_array($localNodeMeta['domains'] ?? null) ? implode(', ', $localNodeMeta['domains']) : '';
        $lines[] = "   - {$slug}: {$description} [Domains: {$domains}]";

        return implode("\n", $lines);
    }

    protected function formatActionLines(array $actionLines): string
    {
        return implode("\n", $actionLines);
    }

    protected function formatAllowedActionList(array $allowedActions): string
    {
        return implode('|', $allowedActions);
    }

    protected function getConfig(string $key, $default = null)
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        try {
            return config("ai-agent.orchestrator.{$key}", $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
