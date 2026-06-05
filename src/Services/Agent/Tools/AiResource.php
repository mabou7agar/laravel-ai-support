<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Closure;
use Illuminate\Support\Str;

/**
 * One-call registration of an Eloquent model as an agent resource.
 *
 * Instead of hand-writing a find tool and a create/update tool per entity, declare the
 * model once and get `find_<name>` + `create_<name>` tools (config-driven, no subclasses):
 *
 *     AiResource::for(Customer::class)
 *         ->search(['name', 'email'])
 *         ->writable(['name', 'email', 'company'])
 *         ->identity(['email'])      // find-or-create key
 *         ->required(['name', 'email'])
 *         ->register();
 *
 * Add ->lookupOnly() for read-only resources, ->scopedBy(fn) for tenant isolation, or
 * ->defaults([...]) / ->defaults(fn($ctx)=>[...]) for server-set columns (user/tenant id).
 */
class AiResource
{
    private string $name;

    /** @var array<int, string> */
    private array $search = [];

    /** @var array<int, string> */
    private array $returns = [];

    /** @var array<int, string> */
    private array $write = [];

    /** @var array<int, string> */
    private array $identity = [];

    /** @var array<int, string> */
    private array $required = [];

    /** @var array<string, mixed> */
    private array $defaults = [];

    private ?Closure $defaultsResolver = null;

    private ?Closure $scope = null;

    private bool $creatable = false;

    private ?string $lookupDescription = null;

    private ?string $createDescription = null;

    private ?string $confirmationMessage = null;

    /**
     * @param class-string $model
     */
    private function __construct(private readonly string $model)
    {
        $this->name = Str::snake(class_basename($model));
    }

    /**
     * @param class-string $model
     */
    public static function for(string $model): self
    {
        return new self($model);
    }

    /**
     * Build a resource from a plain config array (for `ai-agent.resources`). Keys mirror
     * the fluent methods: model, name, search, returns, writable, identity, required,
     * defaults, lookup_only, confirmation_message.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(?string $name, array $config): self
    {
        $resource = self::for((string) ($config['model'] ?? ''));

        $resourceName = $name !== null && $name !== '' ? $name : ($config['name'] ?? null);
        if (is_string($resourceName) && $resourceName !== '') {
            $resource->name($resourceName);
        }
        if (!empty($config['search'])) {
            $resource->search((array) $config['search']);
        }
        if (!empty($config['returns'])) {
            $resource->returns((array) $config['returns']);
        }
        if (!empty($config['writable'])) {
            $resource->writable((array) $config['writable']);
        }
        if (!empty($config['identity'])) {
            $resource->identity((array) $config['identity']);
        }
        if (!empty($config['required'])) {
            $resource->required((array) $config['required']);
        }
        if (isset($config['defaults']) && is_array($config['defaults'])) {
            $resource->defaults($config['defaults']);
        }
        if (!empty($config['lookup_only'])) {
            $resource->lookupOnly();
        }
        if (!empty($config['confirmation_message'])) {
            $resource->confirmationMessage((string) $config['confirmation_message']);
        }

        return $resource;
    }

    public function name(string $name): self
    {
        $this->name = Str::snake(trim($name));

        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function search(array $columns): self
    {
        $this->search = array_values($columns);

        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function returns(array $columns): self
    {
        $this->returns = array_values($columns);

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function writable(array $fields): self
    {
        $this->write = array_values($fields);
        $this->creatable = true;

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function identity(array $fields): self
    {
        $this->identity = array_values($fields);

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function required(array $fields): self
    {
        $this->required = array_values($fields);

        return $this;
    }

    /**
     * @param array<string, mixed>|Closure $defaults
     */
    public function defaults(array|Closure $defaults): self
    {
        if ($defaults instanceof Closure) {
            $this->defaultsResolver = $defaults;
        } else {
            $this->defaults = $defaults;
        }

        return $this;
    }

    public function scopedBy(Closure $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function lookupOnly(): self
    {
        $this->creatable = false;

        return $this;
    }

    public function descriptions(?string $lookup = null, ?string $create = null): self
    {
        $this->lookupDescription = $lookup;
        $this->createDescription = $create;

        return $this;
    }

    public function confirmationMessage(string $message): self
    {
        $this->confirmationMessage = $message;

        return $this;
    }

    /**
     * Build the tools without registering them.
     *
     * @return array<string, AgentTool>
     */
    public function tools(): array
    {
        $search = $this->search !== []
            ? $this->search
            : ($this->identity !== [] ? $this->identity : ['name']);

        $tools = [];

        $findName = 'find_' . $this->name;
        $tools[$findName] = new GenericModelLookupTool(
            $findName,
            $this->model,
            $search,
            $this->returns,
            (string) $this->lookupDescription,
            [],
            $this->scope
        );

        if ($this->creatable && $this->write !== []) {
            $createName = 'create_' . $this->name;
            $tools[$createName] = new GenericModelUpsertTool(
                $createName,
                $this->model,
                $this->identity !== [] ? $this->identity : $search,
                $this->write,
                $this->required,
                $this->returns,
                $this->defaults,
                (string) $this->createDescription,
                $this->defaultsResolver,
                $this->scope,
                $this->confirmationMessage
            );
        }

        return $tools;
    }

    /**
     * Register the resource's tools into the agent tool registry.
     */
    public function register(?ToolRegistry $registry = null): void
    {
        $registry ??= app(ToolRegistry::class);

        foreach ($this->tools() as $name => $tool) {
            if (!$registry->has($name)) {
                $registry->register($name, $tool);
            }
        }
    }
}
