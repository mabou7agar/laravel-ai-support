<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

abstract class SimpleAgentTool extends AgentTool
{
    public string $name = '';

    public string $description = '';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $parameters = [];

    public bool $requiresConfirmation = false;

    public ?string $confirmationMessage = null;

    public function getName(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        return Str::snake(Str::beforeLast(class_basename($this), 'Tool'));
    }

    public function getDescription(): string
    {
        return $this->description !== ''
            ? $this->description
            : 'Tool for ' . str_replace('_', ' ', $this->getName()) . ' operations.';
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function requiresConfirmation(): bool
    {
        return $this->requiresConfirmation;
    }

    public function getConfirmationMessage(): ?string
    {
        return $this->confirmationMessage;
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $errors = $this->validate($parameters);
        if ($errors !== []) {
            return ActionResult::failure('Tool parameters are invalid.', null, [
                'validation_errors' => $errors,
                'tool_name' => $this->getName(),
            ]);
        }

        return $this->normalizeResult($this->handle($parameters, $context));
    }

    abstract protected function handle(array $parameters, UnifiedActionContext $context): ActionResult|array|string|null;

    protected function normalizeResult(ActionResult|array|string|null $result): ActionResult
    {
        if ($result instanceof ActionResult) {
            return $result;
        }

        if (is_string($result)) {
            return ActionResult::success($result);
        }

        if ($result === null) {
            return ActionResult::success('Tool executed successfully.');
        }

        if (array_key_exists('success', $result)) {
            return ActionResult::fromArray($result);
        }

        return ActionResult::success(
            (string) ($result['message'] ?? 'Tool executed successfully.'),
            $result['data'] ?? $result,
            (array) ($result['metadata'] ?? [])
        );
    }
}
