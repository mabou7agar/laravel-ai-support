<?php

namespace LaravelAIEngine\DTOs;

class WorkflowStep
{
    protected string $name;
    protected $executor;
    protected ?string $onSuccess = null;
    protected ?string $onFailure = null;
    protected bool $requiresUserInput = false;
    protected ?array $expectedInput = null;
    protected ?string $description = null;
    protected array $metadata = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function execute(callable $executor): self
    {
        $this->executor = $executor;
        return $this;
    }

    public function onSuccess(string $nextStep): self
    {
        $this->onSuccess = $nextStep;
        return $this;
    }

    public function onFailure(string $nextStep): self
    {
        $this->onFailure = $nextStep;
        return $this;
    }

    public function requiresUserInput(bool $required = true): self
    {
        $this->requiresUserInput = $required;
        return $this;
    }

    public function expectInput(array $input): self
    {
        $this->expectedInput = $input;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getExecutor(): ?callable
    {
        return $this->executor;
    }

    public function getOnSuccess(): ?string
    {
        return $this->onSuccess;
    }

    public function getOnFailure(): ?string
    {
        return $this->onFailure;
    }

    public function doesRequireUserInput(): bool
    {
        return $this->requiresUserInput;
    }

    public function getExpectedInput(): ?array
    {
        return $this->expectedInput;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function run(UnifiedActionContext $context): \LaravelAIEngine\DTOs\ActionResult
    {
        if (!$this->executor) {
            return \LaravelAIEngine\DTOs\ActionResult::failure(
                error: "No executor defined for step: {$this->name}"
            );
        }

        try {
            $result = call_user_func($this->executor, $context);
            
            if (!($result instanceof \LaravelAIEngine\DTOs\ActionResult)) {
                return \LaravelAIEngine\DTOs\ActionResult::failure(
                    error: "Step executor must return ActionResult instance"
                );
            }
            
            return $result;
        } catch (\Exception $e) {
            return \LaravelAIEngine\DTOs\ActionResult::failure(
                error: "Step execution failed: {$e->getMessage()}",
                metadata: [
                    'step' => $this->name,
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'requires_user_input' => $this->requiresUserInput,
            'expected_input' => $this->expectedInput,
            'description' => $this->description,
            'on_success' => $this->onSuccess,
            'on_failure' => $this->onFailure,
            'metadata' => $this->metadata,
        ];
    }
}
