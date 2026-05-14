<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper;

class EvaluateRuntimeFixturesCommand extends Command
{
    protected $signature = 'ai-engine:evaluate-runtime-fixtures
                            {--path= : JSON fixture file path}
                            {--json : Print JSON report}';

    protected $description = 'Evaluate tool safety, approval/resume, and LangGraph runtime fixtures';

    public function handle(AgentExecutionPolicyService $policy, LangGraphRunMapper $langGraph): int
    {
        $fixture = $this->loadFixture($this->fixturePath());
        $results = array_merge(
            $this->evaluateToolSafety((array) ($fixture['tool_safety'] ?? []), $policy),
            $this->evaluateApprovalResume((array) ($fixture['approval_resume'] ?? []), $langGraph),
            $this->evaluateLangGraph((array) ($fixture['langgraph_mock_runtime'] ?? []), $langGraph)
        );

        $passed = collect($results)->every(static fn (array $result): bool => $result['passed']);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Fixture', 'Group', 'Status', 'Detail'], array_map(
                static fn (array $result): array => [
                    $result['name'],
                    $result['group'],
                    $result['passed'] ? 'PASS' : 'FAIL',
                    $result['detail'],
                ],
                $results
            ));
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    protected function fixturePath(): string
    {
        $path = trim((string) ($this->option('path') ?? ''));

        return $path !== '' ? $path : dirname(__DIR__, 3) . '/resources/fixtures/orchestration-v2/runtime.json';
    }

    protected function loadFixture(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Runtime fixture file [{$path}] was not found.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException("Runtime fixture file [{$path}] is invalid.");
        }

        return $decoded;
    }

    protected function evaluateToolSafety(array $fixtures, AgentExecutionPolicyService $policy): array
    {
        $results = [];
        $originalAllow = config('ai-agent.execution_policy.tool_allow', []);
        $originalDeny = config('ai-agent.execution_policy.tool_deny', []);

        foreach ($fixtures as $fixture) {
            config()->set('ai-agent.execution_policy.tool_allow', (array) ($fixture['allow'] ?? []));
            config()->set('ai-agent.execution_policy.tool_deny', (array) ($fixture['deny'] ?? []));

            $allowed = $policy->canUseTool((string) ($fixture['target'] ?? ''));
            $expected = (bool) ($fixture['expected_allowed'] ?? true);
            $results[] = [
                'name' => (string) ($fixture['name'] ?? 'unnamed'),
                'group' => 'tool_safety',
                'passed' => $allowed === $expected,
                'detail' => 'allowed=' . ($allowed ? 'true' : 'false'),
            ];
        }

        config()->set('ai-agent.execution_policy.tool_allow', $originalAllow);
        config()->set('ai-agent.execution_policy.tool_deny', $originalDeny);

        return $results;
    }

    protected function evaluateApprovalResume(array $fixtures, LangGraphRunMapper $langGraph): array
    {
        $results = [];

        foreach ($fixtures as $fixture) {
            $run = (array) ($fixture['run'] ?? []);
            $expected = (array) ($fixture['expected'] ?? []);
            $requiresApproval = $langGraph->interrupts()->requiresApproval($run);
            $response = $langGraph->toResponse($run, new UnifiedActionContext('runtime-fixture'));
            $requiredInput = $response->requiredInputs[0]['name'] ?? null;
            $resume = $langGraph->resumePayload('approved', 'runtime-fixture', 1, [
                'langgraph_resume_payload' => (array) ($fixture['resume_payload'] ?? []),
            ]);

            $passed = $requiresApproval === (bool) ($expected['requires_approval'] ?? false)
                && $requiredInput === ($expected['required_input'] ?? null)
                && ($resume['approved'] ?? null) === (($fixture['resume_payload']['approved'] ?? null));

            $results[] = [
                'name' => (string) ($fixture['name'] ?? 'unnamed'),
                'group' => 'approval_resume',
                'passed' => $passed,
                'detail' => 'requires_approval=' . ($requiresApproval ? 'true' : 'false') . ', input=' . (string) $requiredInput,
            ];
        }

        return $results;
    }

    protected function evaluateLangGraph(array $fixtures, LangGraphRunMapper $langGraph): array
    {
        $results = [];

        foreach ($fixtures as $fixture) {
            $expected = (array) ($fixture['expected'] ?? []);
            $response = $langGraph->toResponse((array) ($fixture['run'] ?? []), new UnifiedActionContext('runtime-fixture'));
            $requiredInput = $response->requiredInputs[0]['name'] ?? null;

            $passed = true;
            if (array_key_exists('success', $expected)) {
                $passed = $passed && $response->success === (bool) $expected['success'];
            }
            if (array_key_exists('needs_user_input', $expected)) {
                $passed = $passed && $response->needsUserInput === (bool) $expected['needs_user_input'];
            }
            if (array_key_exists('message', $expected)) {
                $passed = $passed && $response->message === (string) $expected['message'];
            }
            if (array_key_exists('required_input', $expected)) {
                $passed = $passed && $requiredInput === (string) $expected['required_input'];
            }

            $results[] = [
                'name' => (string) ($fixture['name'] ?? 'unnamed'),
                'group' => 'langgraph_mock_runtime',
                'passed' => $passed,
                'detail' => 'success=' . ($response->success ? 'true' : 'false') . ', needs_input=' . ($response->needsUserInput ? 'true' : 'false'),
            ];
        }

        return $results;
    }
}
