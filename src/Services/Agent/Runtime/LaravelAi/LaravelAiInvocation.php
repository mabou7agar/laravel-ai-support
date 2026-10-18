<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

/**
 * Everything a gateway needs for one laravel/ai turn, resolved by the runtime.
 */
final class LaravelAiInvocation
{
    /**
     * @param array<string, bool>|null $decisions tool call id => approved; non-null means
     *                                            this turn resumes a paused approval
     * @param array{tenant_id?:mixed,workspace_id?:mixed} $scope
     * @param array<string, mixed> $run agent_run_id, agent_run_uuid, agent_run_step_id,
     *                                  agent_run_step_uuid, trace_id
     * @param array<string, mixed> $options the full runtime options, for custom gateways
     */
    public function __construct(
        public readonly string $message,
        public readonly string $sessionId,
        public readonly mixed $userId,
        public readonly ?string $conversationId = null,
        public readonly ?array $decisions = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?int $timeout = null,
        public readonly bool $stream = true,
        public readonly array $scope = [],
        public readonly array $run = [],
        public readonly array $options = []
    ) {
    }

    public function isResume(): bool
    {
        return $this->decisions !== null;
    }
}
