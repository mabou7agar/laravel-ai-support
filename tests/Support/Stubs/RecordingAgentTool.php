<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Support\Stubs;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

/**
 * Package AgentTool that records how it was called, for the laravel/ai tool bridge tests.
 */
class RecordingAgentTool extends AgentTool
{
    /** @var array<int, array{parameters:array<string,mixed>,request_options:array<string,mixed>,session_id:string}> */
    public static array $calls = [];

    public function __construct(
        private readonly string $name = 'lookup_order',
        private readonly bool $confirm = false
    ) {
    }

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Look up an order by number.';
    }

    public function getParameters(): array
    {
        $parameters = [
            'order' => ['type' => 'integer', 'description' => 'Order number', 'required' => true],
        ];

        if ($this->confirm) {
            $parameters['confirmed'] = ['type' => 'boolean', 'description' => 'Set once the user confirmed.'];
        }

        return $parameters;
    }

    public function requiresConfirmation(): bool
    {
        return $this->confirm;
    }

    public function getConfirmationMessage(): ?string
    {
        return $this->confirm ? 'Refunds need approval.' : null;
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        self::$calls[] = [
            'parameters' => $parameters,
            'request_options' => $context->requestOptions,
            'session_id' => $context->sessionId,
        ];

        return ActionResult::success('Order ' . ($parameters['order'] ?? '?') . ' has shipped.', ['status' => 'shipped']);
    }
}
