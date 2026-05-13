<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentOrchestrationReport;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AgentOrchestrationInspector
{
    public function __construct(
        private readonly SubAgentRegistry $subAgents,
        private readonly ToolRegistry $tools,
        private readonly AgentSkillRegistry $skills
    ) {
    }

    public function inspect(array $options = []): AgentOrchestrationReport
    {
        $toolNames = array_keys($this->tools->all());
        $subAgentNames = array_keys($this->subAgents->all());
        $skillDefinitions = $this->skillDefinitions();

        $nodes = [
            'tools' => array_values($toolNames),
            'sub_agents' => array_values($subAgentNames),
            'skills' => array_map(static fn (AgentSkillDefinition $skill): string => $skill->id, $skillDefinitions),
        ];

        $links = [];
        $issues = [];

        foreach ($this->subAgents->all() as $agentId => $definition) {
            if (!is_array($definition) || (($definition['enabled'] ?? true) === false)) {
                continue;
            }

            $declaredTools = $this->stringList($definition['tools'] ?? []);
            $declaredDelegates = $this->stringList($definition['sub_agents'] ?? $definition['delegates_to'] ?? []);

            if (($definition['handler'] ?? null) === null && $declaredTools === []) {
                $issues[] = $this->issue('error', 'sub_agent_missing_handler', "Sub-agent [{$agentId}] has no handler or tools.", "sub_agent:{$agentId}");
            }

            foreach ($declaredTools as $toolName) {
                $links[] = $this->link("sub_agent:{$agentId}", "tool:{$toolName}", 'uses_tool');
                if (!in_array($toolName, $toolNames, true)) {
                    $issues[] = $this->issue('error', 'missing_tool', "Sub-agent [{$agentId}] references missing tool [{$toolName}].", "sub_agent:{$agentId}");
                }
            }

            foreach ($declaredDelegates as $delegateId) {
                $links[] = $this->link("sub_agent:{$agentId}", "sub_agent:{$delegateId}", 'delegates_to');
                if (!in_array($delegateId, $subAgentNames, true)) {
                    $issues[] = $this->issue('error', 'missing_sub_agent', "Sub-agent [{$agentId}] delegates to missing sub-agent [{$delegateId}].", "sub_agent:{$agentId}");
                }
            }
        }

        foreach ($skillDefinitions as $skill) {
            foreach ($skill->tools as $toolName) {
                $links[] = $this->link("skill:{$skill->id}", "tool:{$toolName}", 'uses_tool');
                if (!in_array($toolName, $toolNames, true)) {
                    $issues[] = $this->issue('error', 'missing_tool', "Skill [{$skill->id}] references missing tool [{$toolName}].", "skill:{$skill->id}");
                }
            }

            foreach ($this->stringList($skill->metadata['sub_agents'] ?? $skill->metadata['agents'] ?? []) as $agentId) {
                $links[] = $this->link("skill:{$skill->id}", "sub_agent:{$agentId}", 'uses_sub_agent');
                if (!in_array($agentId, $subAgentNames, true)) {
                    $issues[] = $this->issue('error', 'missing_sub_agent', "Skill [{$skill->id}] references missing sub-agent [{$agentId}].", "skill:{$skill->id}");
                }
            }
        }

        $complexityScore = count($links) + count($nodes['tools']) + count($nodes['sub_agents']) + count($nodes['skills']);
        $maxComplexity = (int) ($options['max_complexity'] ?? config('ai-agent.orchestration.max_complexity', 80));
        if ($maxComplexity > 0 && $complexityScore > $maxComplexity) {
            $issues[] = $this->issue(
                'warning',
                'orchestration_complexity_high',
                "Agent orchestration graph complexity [{$complexityScore}] exceeds configured limit [{$maxComplexity}].",
                'orchestration'
            );
        }

        return new AgentOrchestrationReport(
            nodes: $nodes,
            links: $links,
            issues: $issues,
            metrics: [
                'tool_count' => count($nodes['tools']),
                'sub_agent_count' => count($nodes['sub_agents']),
                'skill_count' => count($nodes['skills']),
                'link_count' => count($links),
                'complexity_score' => $complexityScore,
                'max_complexity' => $maxComplexity,
            ]
        );
    }

    /**
     * @return array<int, AgentSkillDefinition>
     */
    private function skillDefinitions(): array
    {
        try {
            return $this->skills->skills(includeDisabled: true);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        )));
    }

    /**
     * @return array{from:string,to:string,type:string,metadata:array<string,mixed>}
     */
    private function link(string $from, string $to, string $type, array $metadata = []): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array{severity:string,code:string,message:string,subject:string}
     */
    private function issue(string $severity, string $code, string $message, string $subject): array
    {
        return compact('severity', 'code', 'message', 'subject');
    }
}
