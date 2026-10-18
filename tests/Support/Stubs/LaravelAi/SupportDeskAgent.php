<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Support\Stubs\LaravelAi;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;

/**
 * A host-app style laravel/ai agent, configured through
 * `ai-agent.runtime.laravel_ai.agent`. Only autoloaded by tests that run when
 * laravel/ai is installed.
 */
final class SupportDeskAgent implements Agent, Conversational, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You are the support desk.';
    }
}
