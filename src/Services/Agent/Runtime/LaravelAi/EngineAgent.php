<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;

/**
 * Default laravel/ai agent for the `laravel_ai` runtime.
 *
 * Instructions and tools come from `ai-agent.runtime.laravel_ai`. It remembers
 * conversations through the SDK's conversation store, which is what lets a
 * paused tool approval resume. Host apps that need their own agent set
 * `ai-agent.runtime.laravel_ai.agent` to a class implementing
 * Laravel\Ai\Contracts\Agent instead.
 *
 * Only loaded by SdkLaravelAiGateway, which is only built when laravel/ai is installed.
 */
final class EngineAgent implements Agent, Conversational, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    /**
     * @param iterable<int, mixed> $tools laravel/ai Tool instances
     */
    public function __construct(
        protected string $instructions = '',
        protected iterable $tools = []
    ) {
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
