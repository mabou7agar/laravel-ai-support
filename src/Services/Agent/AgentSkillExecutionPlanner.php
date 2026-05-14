<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentSkillExecutionPlanner
{
    /**
     * @return array<string, mixed>
     */
    public function plan(AgentSkillDefinition $skill, string $message, UnifiedActionContext $context, array $match = []): array
    {
        $metadata = [
            'skill_id' => $skill->id,
            'skill_name' => $skill->name,
            'skill_match_score' => $match['score'] ?? null,
            'skill_match_trigger' => $match['trigger'] ?? null,
        ];

        if ($this->shouldRunWithSkillToolPlanner($skill)) {
            return [
                'action' => 'use_tool',
                'resource_name' => 'run_skill',
                'params' => [
                    'skill_id' => $skill->id,
                    'message' => $message,
                    'reset' => true,
                ],
                'reasoning' => "Matched skill [{$skill->name}] and selected AI skill tool planner.",
                'decision_source' => 'skill_match',
                'metadata' => $metadata,
            ];
        }

        if ($skill->actions !== []) {
            return [
                'action' => 'use_tool',
                'resource_name' => 'update_action_draft',
                'params' => [
                    'action_id' => $skill->actions[0],
                    'payload_patch' => [],
                    'reset' => true,
                ],
                'reasoning' => "Matched skill [{$skill->name}] and selected action flow [{$skill->actions[0]}].",
                'decision_source' => 'skill_match',
                'metadata' => $metadata,
            ];
        }

        $collector = $skill->metadata['collector'] ?? null;
        if (is_string($collector) && trim($collector) !== '') {
            return [
                'action' => 'start_collector',
                'resource_name' => $collector,
                'params' => [],
                'reasoning' => "Matched skill [{$skill->name}] and selected collector [{$collector}].",
                'decision_source' => 'skill_match',
                'metadata' => $metadata,
            ];
        }

        if ($skill->tools !== []) {
            return [
                'action' => 'use_tool',
                'resource_name' => $skill->tools[0],
                'params' => [],
                'reasoning' => "Matched skill [{$skill->name}] and selected tool [{$skill->tools[0]}].",
                'decision_source' => 'skill_match',
                'metadata' => $metadata,
            ];
        }

        return [
            'action' => 'search_rag',
            'resource_name' => null,
            'params' => [
                'query' => $message,
            ],
            'reasoning' => "Matched skill [{$skill->name}] but no executable action, tool, or collector is configured.",
            'decision_source' => 'skill_match',
            'metadata' => $metadata,
        ];
    }

    protected function shouldRunWithSkillToolPlanner(AgentSkillDefinition $skill): bool
    {
        if (!(bool) config('ai-agent.skill_tool_planner.enabled', true)) {
            return false;
        }

        $planner = $skill->metadata['planner'] ?? null;
        if (is_string($planner) && in_array($planner, ['skill_tool_auto', 'tool_planner', 'ai_tool_planner'], true)) {
            return true;
        }

        return $skill->actions === []
            && $skill->tools !== []
            && ($skill->metadata['target_json'] ?? null) !== null;
    }
}
