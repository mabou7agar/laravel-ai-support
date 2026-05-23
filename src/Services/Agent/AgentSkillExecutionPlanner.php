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

        if ($this->shouldRunWithAiNativeSkill($skill)) {
            return [
                'action' => 'use_tool',
                'resource_name' => 'run_skill',
                'params' => [
                    'skill_id' => $skill->id,
                    'message' => $message,
                    'reset' => true,
                    'fresh_start' => $this->isFreshSkillRequest($skill, $message, $context),
                ],
                'reasoning' => "Matched skill [{$skill->name}] and selected AI-native skill runtime.",
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
            'reasoning' => "Matched skill [{$skill->name}] but no executable tool is configured.",
            'decision_source' => 'skill_match',
            'metadata' => $metadata,
        ];
    }

    protected function shouldRunWithAiNativeSkill(AgentSkillDefinition $skill): bool
    {
        $planner = $skill->metadata['planner'] ?? null;
        if (is_string($planner) && $planner === 'ai_native') {
            return true;
        }

        return $skill->actions === []
            && $skill->tools !== []
            && ($skill->metadata['target_json'] ?? null) !== null;
    }

    protected function isFreshSkillRequest(AgentSkillDefinition $skill, string $message, UnifiedActionContext $context): bool
    {
        $request = $context->metadata['_fresh_skill_request'] ?? null;

        return is_array($request)
            && (string) ($request['skill_id'] ?? '') === $skill->id
            && trim((string) ($request['message'] ?? '')) === trim($message);
    }
}
