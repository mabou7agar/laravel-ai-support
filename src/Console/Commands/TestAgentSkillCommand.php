<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;

class TestAgentSkillCommand extends Command
{
    protected $signature = 'ai-engine:skills:test
                            {message : User message to match against enabled skills}
                            {--include-disabled : Include disabled draft skills}
                            {--json : Output the match and execution plan as JSON}';

    protected $description = 'Test skill matching and show the compiled execution plan for a message';

    public function handle(AgentSkillMatcher $matcher, AgentSkillExecutionPlanner $planner): int
    {
        $message = (string) $this->argument('message');
        $match = $matcher->match($message, (bool) $this->option('include-disabled'));

        if ($match === null) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'matched' => false,
                    'message' => $message,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->warn('No matching skill found.');
            }

            return self::SUCCESS;
        }

        $context = new UnifiedActionContext(
            sessionId: 'skill-test-' . uniqid(),
            userId: null
        );

        $plan = $planner->plan($match['skill'], $message, $context, $match);
        $payload = [
            'matched' => true,
            'skill' => $match['skill']->toArray(),
            'score' => $match['score'],
            'trigger' => $match['trigger'],
            'reason' => $match['reason'],
            'plan' => $plan,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info("Matched skill: {$match['skill']->name} ({$match['skill']->id})");
        $this->line("Score: {$match['score']}");
        $this->line("Trigger: {$match['trigger']}");
        $this->newLine();
        $this->table(
            ['Plan Field', 'Value'],
            [
                ['action', (string) ($plan['action'] ?? '')],
                ['resource_name', (string) ($plan['resource_name'] ?? '')],
                ['reasoning', (string) ($plan['reasoning'] ?? '')],
                ['params', json_encode($plan['params'] ?? [], JSON_UNESCAPED_SLASHES)],
            ]
        );

        return self::SUCCESS;
    }
}
