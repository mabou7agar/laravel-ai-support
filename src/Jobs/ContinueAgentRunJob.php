<?php

declare(strict_types=1);

namespace LaravelAIEngine\Jobs;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;

class ContinueAgentRunJob extends RunAgentJob
{
    public function __construct(
        int|string $runId,
        string $message,
        array $options = []
    ) {
        parent::__construct($runId, $message, '', null, $options);
    }

    public function handle(
        AgentRuntimeContract $runtime,
        AgentRunRepository $runs,
        AgentRunStepRepository $steps,
        AgentRunSafetyService $safety,
        ?AgentRunEventStreamService $events = null
    ): void {
        $run = $runs->findOrFail($this->runId);
        $this->sessionId = $run->session_id;
        $this->userId = $run->user_id;

        parent::handle($runtime, $runs, $steps, $safety, $events);
    }

    protected function stepKey(): string
    {
        return 'continuation';
    }

    protected function stepType(): string
    {
        return 'agent_continuation';
    }

    protected function stepAction(): string
    {
        return 'continue';
    }
}
