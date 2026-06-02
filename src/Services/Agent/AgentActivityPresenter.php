<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

/**
 * Turns raw agent-run stream events into human-friendly, live activity lines —
 * the "Thinking… / Searching for customer… / Creating invoice… / Done" labels a
 * frontend shows the user while the agent works (Claude-Code style).
 *
 * Returns { label, icon, phase, terminal } per event so the UI can render a
 * spinner + a verb phrase, and group by phase. Pure/stateless — safe to call per
 * event on the SSE stream or from any consumer.
 */
class AgentActivityPresenter
{
    /**
     * @param array<string, mixed> $payload the event payload (e.g. tool_name, decision, count)
     * @return array{label: string, icon: string, phase: string, terminal: bool}
     */
    public function describe(string $event, array $payload = []): array
    {
        return match ($event) {
            AgentRunEventStreamService::RUN_STARTED              => $this->a('Getting started', '•', 'start'),
            AgentRunEventStreamService::ROUTING_STAGE_STARTED    => $this->a('Thinking', '✶', 'thinking'),
            AgentRunEventStreamService::ROUTING_STAGE_ABSTAINED  => $this->a('Thinking', '✶', 'thinking'),
            AgentRunEventStreamService::ROUTING_DECIDED          => $this->routing($payload),
            AgentRunEventStreamService::RAG_STARTED              => $this->a('Searching your knowledge base', '🔎', 'searching'),
            AgentRunEventStreamService::RAG_SOURCES_FOUND        => $this->a($this->sources($payload), '📚', 'searching'),
            AgentRunEventStreamService::RAG_COMPLETED            => $this->a('Reading the results', '📖', 'searching'),
            AgentRunEventStreamService::TOOL_STARTED             => $this->a($this->toolLabel($payload), $this->toolIcon($payload), 'acting'),
            AgentRunEventStreamService::TOOL_PROGRESS            => $this->a($this->toolLabel($payload, 'Working'), '⚙', 'acting'),
            AgentRunEventStreamService::TOOL_COMPLETED           => $this->a($this->toolDone($payload), '✓', 'acting'),
            AgentRunEventStreamService::TOOL_FAILED              => $this->a($this->toolFail($payload), '✗', 'error'),
            AgentRunEventStreamService::SUB_AGENT_STARTED        => $this->a($this->subAgent($payload), '🤝', 'acting'),
            AgentRunEventStreamService::SUB_AGENT_COMPLETED      => $this->a('Sub-agent finished', '✓', 'acting'),
            AgentRunEventStreamService::APPROVAL_REQUIRED        => $this->a('Waiting for your approval', '⏸', 'waiting'),
            AgentRunEventStreamService::APPROVAL_RESOLVED        => $this->a('Approval resolved', '✓', 'acting'),
            AgentRunEventStreamService::ARTIFACT_CREATED         => $this->a('Created a file', '📄', 'acting'),
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED  => $this->a('Writing the reply', '✍', 'writing'),
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED => $this->a('Reply ready', '✓', 'writing'),
            AgentRunEventStreamService::RUN_WAITING_INPUT        => $this->a('Waiting for your input', '⏳', 'waiting'),
            AgentRunEventStreamService::RUN_WAITING_APPROVAL     => $this->a('Waiting for approval', '⏸', 'waiting'),
            AgentRunEventStreamService::RUN_COMPLETED            => $this->a('Done', '✓', 'done', true),
            AgentRunEventStreamService::RUN_FAILED               => $this->a('Something went wrong', '✗', 'error', true),
            AgentRunEventStreamService::RUN_CANCELLED            => $this->a('Cancelled', '⊘', 'done', true),
            AgentRunEventStreamService::RUN_EXPIRED              => $this->a('Timed out', '⌛', 'error', true),
            default                                             => $this->a('Working', '•', 'acting'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{label: string, icon: string, phase: string, terminal: bool}
     */
    protected function routing(array $payload): array
    {
        $action = $payload['decision']['action'] ?? $payload['action'] ?? null;

        return match ($action) {
            'use_tool'        => $this->a('Choosing the right action', '✶', 'thinking'),
            'search_rag'      => $this->a('Looking for relevant information', '🔎', 'searching'),
            'route_to_node'   => $this->a('Routing to the right service', '➜', 'thinking'),
            'need_user_input' => $this->a('Working out what to ask', '✶', 'thinking'),
            'conversational'  => $this->a('Composing a reply', '✶', 'thinking'),
            default           => $this->a('Planning', '✶', 'thinking'),
        };
    }

    protected function toolLabel(array $payload, string $fallback = 'Working'): string
    {
        $tool = $this->toolName($payload);

        return $tool === '' ? $fallback : $this->humanize($tool);
    }

    protected function toolDone(array $payload): string
    {
        $tool = $this->toolName($payload);

        return $tool === '' ? 'Done' : $this->humanize($tool) . ' — done';
    }

    protected function toolFail(array $payload): string
    {
        $tool = $this->toolName($payload);

        return $tool === '' ? 'Action failed' : $this->humanize($tool) . ' — failed';
    }

    protected function toolName(array $payload): string
    {
        return trim((string) ($payload['tool_name'] ?? $payload['resource_name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function toolIcon(array $payload): string
    {
        $tool = strtolower($this->toolName($payload));
        $verb = explode('_', $tool)[0] ?? '';

        return match (true) {
            str_starts_with($tool, 'find') || str_starts_with($tool, 'search') || $verb === 'lookup' => '🔎',
            $verb === 'create' || $verb === 'add' || $verb === 'new'                                  => '✚',
            $verb === 'update' || $verb === 'edit' || $verb === 'modify'                              => '✎',
            $verb === 'enhance' || $verb === 'improve' || $verb === 'upscale'                         => '✨',
            $verb === 'delete' || $verb === 'remove'                                                  => '🗑',
            default                                                                                   => '⚙',
        };
    }

    /**
     * find_customer -> "Searching for customer", create_invoice -> "Creating invoice",
     * update_invoice -> "Modifying invoice", enhance_image -> "Enhancing image".
     */
    protected function humanize(string $tool): string
    {
        $tool = strtolower(trim($tool));

        $special = [
            'data_query'    => 'Looking up your data',
            'run_skill'     => 'Running a skill',
            'run_sub_agent' => 'Delegating to a sub-agent',
            'search_rag'    => 'Searching your knowledge base',
        ];
        if (isset($special[$tool])) {
            return $special[$tool];
        }

        $parts = explode('_', $tool);
        $verb = $parts[0];
        $rest = trim(str_replace('_', ' ', implode(' ', array_slice($parts, 1))));

        $verbs = [
            'find' => 'Searching for', 'search' => 'Searching for', 'lookup' => 'Looking up', 'get' => 'Fetching', 'list' => 'Listing',
            'create' => 'Creating', 'add' => 'Adding', 'new' => 'Creating', 'make' => 'Creating',
            'update' => 'Modifying', 'edit' => 'Editing', 'modify' => 'Modifying', 'set' => 'Updating',
            'enhance' => 'Enhancing', 'improve' => 'Enhancing', 'upscale' => 'Enhancing',
            'delete' => 'Removing', 'remove' => 'Removing',
            'validate' => 'Validating', 'check' => 'Checking',
            'generate' => 'Generating', 'analyze' => 'Analyzing', 'send' => 'Sending', 'run' => 'Running',
        ];

        if (!isset($verbs[$verb])) {
            return ucfirst(str_replace('_', ' ', $tool));
        }

        return trim($verbs[$verb] . ($rest !== '' ? ' ' . $rest : ''));
    }

    protected function sources(array $payload): string
    {
        $count = (int) ($payload['result_count'] ?? $payload['count'] ?? 0);

        return $count > 0 ? sprintf('Found %d %s', $count, $count === 1 ? 'source' : 'sources') : 'Searching your knowledge base';
    }

    protected function subAgent(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? $payload['sub_agent'] ?? $payload['id'] ?? ''));

        return $name === '' ? 'Delegating to a sub-agent' : 'Delegating to ' . $name;
    }

    /**
     * @return array{label: string, icon: string, phase: string, terminal: bool}
     */
    protected function a(string $label, string $icon, string $phase, bool $terminal = false): array
    {
        return ['label' => $label, 'icon' => $icon, 'phase' => $phase, 'terminal' => $terminal];
    }
}
