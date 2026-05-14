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
                            {--smart : Use AI intent matching when deterministic triggers do not match}
                            {--history=* : Recent conversation entries as role:content}
                            {--json : Output the match and execution plan as JSON}';

    protected $description = 'Test skill matching and show the compiled execution plan for a message';

    public function handle(AgentSkillMatcher $matcher, AgentSkillExecutionPlanner $planner): int
    {
        $message = (string) $this->argument('message');
        $context = new UnifiedActionContext(
            sessionId: 'skill-test-' . uniqid(),
            userId: null,
            conversationHistory: $this->history()
        );
        $includeDisabled = (bool) $this->option('include-disabled');
        $match = ((bool) $this->option('smart') || $context->conversationHistory !== [])
            ? $matcher->matchIntent($message, $context, $includeDisabled)
            : $matcher->match($message, $includeDisabled);

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

    /**
     * @return array<int, array{role:string,content:string}>
     */
    protected function history(): array
    {
        return collect((array) $this->option('history'))
            ->map(function (mixed $entry): ?array {
                $entry = trim((string) $entry);
                if ($entry === '') {
                    return null;
                }

                [$role, $content] = str_contains($entry, ':')
                    ? explode(':', $entry, 2)
                    : ['user', $entry];

                $role = strtolower(trim($role));

                return [
                    'role' => in_array($role, ['system', 'assistant', 'user'], true) ? $role : 'user',
                    'content' => trim($content),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
