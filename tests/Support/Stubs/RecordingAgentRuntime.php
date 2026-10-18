<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Support\Stubs;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;

/**
 * Deterministic third-party runtime for tests: records every call it receives
 * and returns a fixed response carrying usage, so a test can prove the real
 * RunAgentJob hands scope and identifiers to a host-registered runtime.
 */
final class RecordingAgentRuntime implements AgentRuntimeContract
{
    public const NAME = 'recording';

    /**
     * @var array<int, array{message:string,session_id:string,user_id:mixed,options:array<string,mixed>}>
     */
    public static array $calls = [];

    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$instances = 0;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        return new AgentRuntimeCapabilities(
            streaming: false,
            interrupts: false,
            tools: true,
            artifacts: false,
            humanApprovals: false,
            subAgents: false,
            remoteCallbacks: false,
            metadata: ['runtime' => self::NAME]
        );
    }

    public function process(string $message, string $sessionId, mixed $userId, array $options = []): AgentResponse
    {
        self::$calls[] = [
            'message' => $message,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'options' => $options,
        ];

        $response = AgentResponse::success('Recorded response.');
        $response->metadata = [
            'agent_runtime' => self::NAME,
            'usage' => ['tokens_used' => 42, 'cost_used' => 0.1],
        ];

        return $response;
    }
}
