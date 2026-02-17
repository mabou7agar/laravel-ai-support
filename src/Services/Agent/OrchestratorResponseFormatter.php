<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Formats data structures into text blocks for AI orchestrator prompts.
 *
 * Extracted from MinimalAIOrchestrator to keep formatting logic
 * testable and separate from routing/execution concerns.
 */
class OrchestratorResponseFormatter
{
    /**
     * Format conversation history for the AI prompt.
     */
    public function formatHistory(UnifiedActionContext $context): string
    {
        $messages = $context->conversationHistory;

        if (empty($messages) || count($messages) <= 1) {
            return "(New conversation)";
        }

        // Show last 5 messages with more content for better context
        $recent = array_slice($messages, -5);
        $lines = [];

        foreach ($recent as $msg) {
            $role = ucfirst($msg['role']);
            $content = $msg['content'];

            // Check if message contains numbered options (1., 2., 3. or 1), 2), 3))
            $hasNumberedOptions = preg_match('/\b\d+[\.\)]\s+/m', $content);

            // Preserve numbered options fully, truncate others
            if ($hasNumberedOptions) {
                // Keep full content for numbered options (up to 1000 chars to be safe)
                $content = substr($content, 0, 1000);
            } else {
                // Normal truncation for other messages
                $content = substr($content, 0, 300);
            }

            $lines[] = "   {$role}: {$content}";
        }

        return implode("\n", $lines);
    }

    /**
     * Provide compact selected-entity context for AI routing (model-agnostic).
     */
    public function formatSelectedEntityContext(?array $selected): string
    {
        if (!$selected) {
            return "(none)";
        }

        return json_encode($selected, JSON_PRETTY_PRINT);
    }

    /**
     * Format entity metadata from last assistant message for AI prompt.
     */
    public function formatEntityMetadata(UnifiedActionContext $context): string
    {
        // Get last assistant message
        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $msg) {
            if ($msg['role'] === 'assistant' && !empty($msg['metadata']['entity_ids'])) {
                $entityIds = $msg['metadata']['entity_ids'];
                $entityType = $msg['metadata']['entity_type'] ?? 'item';

                $formatted = "ENTITY CONTEXT (from last response):\n";
                $formatted .= "Type: {$entityType}\n";
                $formatted .= "IDs: " . json_encode($entityIds) . "\n";
                $formatted .= "Note: If user refers to positions (1, 2, first, etc.), map to these IDs in order.\n";

                return $formatted;
            }
        }

        return "";
    }

    /**
     * Format paused sessions for the AI prompt.
     */
    public function formatPausedSessions(array $sessions): string
    {
        if (empty($sessions)) {
            return "None";
        }

        return implode(', ', array_map(fn($s) => $s['config_name'] ?? 'unknown', $sessions));
    }

    /**
     * Format collectors for the AI prompt.
     */
    public function formatCollectors(array $collectors): string
    {
        if (empty($collectors)) {
            return "   (No collectors available)";
        }

        $lines = [];
        foreach ($collectors as $collector) {
            $nodeName = $collector['node'] ?? 'local';
            $goal = $collector['goal'] ?? '';
            $lines[] = "   - Name :{$collector['name']} Goal: {$goal} Description : {$collector['description']} Node: {$nodeName} ";
        }

        return implode("\n", $lines);
    }

    /**
     * Format tools for the AI prompt.
     */
    public function formatTools(array $tools): string
    {
        if (empty($tools)) {
            return "   (No tools available)";
        }

        $lines = [];
        foreach ($tools as $tool) {
            $lines[] = "   - {$tool['name']} ({$tool['model']}): {$tool['description']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Format nodes for the AI prompt.
     */
    public function formatNodes(array $nodes): string
    {
        if (empty($nodes)) {
            return "   (No nodes available)";
        }

        $lines = [];
        foreach ($nodes as $node) {
            $domains = implode(', ', $node['domains']);
            $lines[] = "   - {$node['slug']}: {$node['description']} [Domains: {$domains}]";
        }

        return implode("\n", $lines);
    }
}
