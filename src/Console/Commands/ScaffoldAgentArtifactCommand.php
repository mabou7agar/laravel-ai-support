<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaravelAIEngine\Services\Agent\AgentManifestService;

class ScaffoldAgentArtifactCommand extends Command
{
    protected $signature = 'ai:scaffold
                            {type? : agent|filter|tool|skill|routing-stage|execution-handler|runtime|rag-retriever|policy}
                            {name? : Class name (e.g. Invoice)}
                            {--model= : Model class for model-backed artifacts (e.g. App\\Models\\Invoice)}
                            {--kind= : Tool template kind: simple, lookup, upsert, action}
                            {--action= : Action id for action-backed tool templates}
                            {--description= : Description text used in generated class}
                            {--force : Overwrite file if it already exists}
                            {--no-register : Skip automatic manifest registration}';

    protected $description = 'Scaffold AI agent artifacts (agent config, filter, tool, skill, routing stage, execution handler, runtime, RAG retriever, policy)';

    /**
     * @var array<string, array{namespace:string,directory:string,suffix:string,manifest:string,map:bool}>
     */
    protected array $types = [
        'agent' => [
            'namespace' => 'App\\AI\\Configs',
            'directory' => 'AI/Configs',
            'suffix' => 'Config',
            'manifest' => 'model_configs',
            'map' => false,
        ],
        'filter' => [
            'namespace' => 'App\\AI\\Filters',
            'directory' => 'AI/Filters',
            'suffix' => 'Filter',
            'manifest' => 'filters',
            'map' => true,
        ],
        'tool' => [
            'namespace' => 'App\\AI\\Tools',
            'directory' => 'AI/Tools',
            'suffix' => 'Tool',
            'manifest' => 'tools',
            'map' => true,
        ],
        'skill' => [
            'namespace' => 'App\\AI\\Skills',
            'directory' => 'AI/Skills',
            'suffix' => 'Skill',
            'manifest' => 'skill_providers',
            'map' => true,
        ],
        'routing-stage' => [
            'namespace' => 'App\\AI\\Routing',
            'directory' => 'AI/Routing',
            'suffix' => 'RoutingStage',
            'manifest' => 'routing_stages',
            'map' => true,
        ],
        'execution-handler' => [
            'namespace' => 'App\\AI\\Execution',
            'directory' => 'AI/Execution',
            'suffix' => 'ExecutionHandler',
            'manifest' => 'execution_handlers',
            'map' => true,
        ],
        'runtime' => [
            'namespace' => 'App\\AI\\Runtimes',
            'directory' => 'AI/Runtimes',
            'suffix' => 'Runtime',
            'manifest' => 'runtimes',
            'map' => true,
        ],
        'rag-retriever' => [
            'namespace' => 'App\\AI\\RAG',
            'directory' => 'AI/RAG',
            'suffix' => 'RAGRetriever',
            'manifest' => 'rag_retrievers',
            'map' => true,
        ],
        'policy' => [
            'namespace' => 'App\\AI\\Policies',
            'directory' => 'AI/Policies',
            'suffix' => 'Policy',
            'manifest' => 'policies',
            'map' => true,
        ],
    ];

    public function handle(AgentManifestService $manifestService): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return self::FAILURE;
        }

        $name = $this->resolveName($type);
        if ($name === null) {
            $this->error('A class name is required.');
            return self::FAILURE;
        }

        $className = $this->normalizeClassName($name, $this->types[$type]['suffix']);
        $namespace = $this->types[$type]['namespace'];
        $directory = app_path($this->types[$type]['directory']);
        $path = $directory . '/' . $className . '.php';
        $fqcn = $namespace . '\\' . $className;

        File::ensureDirectoryExists($directory);

        if (is_file($path) && !$this->option('force')) {
            $this->error("File already exists: {$path}");
            $this->line('Use --force to overwrite.');
            return self::FAILURE;
        }

        $contents = $this->buildTemplate($type, $namespace, $className);
        File::put($path, $contents);

        $this->info("Generated {$type} class:");
        $this->line($path);

        if (!$this->option('no-register')) {
            $manifestPath = $this->normalizePath($manifestService->manifestPath());
            $manifest = $this->loadManifest($manifestPath);

            $section = $this->types[$type]['manifest'];
            $manifest[$section] = $manifest[$section] ?? [];

            $registered = false;

            if ($this->types[$type]['map']) {
                $key = $this->artifactKey($className);
                $current = $manifest[$section][$key] ?? null;
                if ($current !== $fqcn) {
                    $manifest[$section][$key] = $fqcn;
                    $registered = true;
                }
            } else {
                if (!in_array($fqcn, $manifest[$section], true)) {
                    $manifest[$section][] = $fqcn;
                    $registered = true;
                }
            }

            if ($registered) {
                File::ensureDirectoryExists(dirname($manifestPath));
                File::put($manifestPath, $this->renderManifest($manifest));
                $manifestService->refresh();
                $this->line("Registered in manifest [{$section}] at {$manifestPath}");
            } else {
                $this->line("Manifest already contains this {$type} entry.");
            }
        }

        $this->newLine();
        $this->line('Next:');
        $this->line('1) Implement real logic in the generated class');
        $this->line($type === 'tool'
            ? '2) Run php artisan ai:tools:test ' . $this->artifactKey($className) . ' --payload=\'{}\''
            : '2) Run php artisan ai:skills:test "your test message"');

        return self::SUCCESS;
    }

    protected function resolveType(): ?string
    {
        $type = strtolower(trim((string) ($this->argument('type') ?? '')));

        if ($type === '') {
            $type = (string) $this->choice('What do you want to scaffold?', array_keys($this->types), 0);
        }

        if (!array_key_exists($type, $this->types)) {
            $this->error('Invalid type. Allowed: ' . implode(', ', array_keys($this->types)) . '.');
            return null;
        }

        return $type;
    }

    protected function resolveName(string $type): ?string
    {
        $name = trim((string) ($this->argument('name') ?? ''));

        if ($name !== '') {
            return $name;
        }

        return $this->ask('Class name', Str::studly($type));
    }

    protected function normalizeClassName(string $name, string $suffix): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? $name;
        $class = Str::studly($sanitized);

        if ($class === '') {
            return $suffix;
        }

        if (!Str::endsWith($class, $suffix)) {
            return $class . $suffix;
        }

        return $class;
    }

    protected function inferModelClass(string $className, string $type): string
    {
        $provided = trim((string) ($this->option('model') ?? ''));
        if ($provided !== '') {
            $provided = preg_replace('/::class$/', '', $provided) ?? $provided;
            return ltrim($provided, '\\');
        }

        $base = $className;
        if ($type === 'agent') {
            $base = Str::beforeLast($className, 'Config');
        }

        $base = $base === '' ? 'Model' : $base;

        return 'App\\Models\\' . $base;
    }

    protected function artifactKey(string $className): string
    {
        $key = preg_replace('/(Config|Collector|Filter|Tool|Skill|RoutingStage|ExecutionHandler|Runtime|RAGRetriever|Policy)$/', '', $className) ?? $className;
        $key = Str::snake($key);

        return $key !== '' ? $key : Str::snake($className);
    }

    protected function buildTemplate(string $type, string $namespace, string $className): string
    {
        return match ($type) {
            'agent' => $this->buildAgentTemplate($namespace, $className),
            'filter' => $this->buildFilterTemplate($namespace, $className),
            'tool' => $this->buildToolTemplate($namespace, $className),
            'skill' => $this->buildSkillTemplate($namespace, $className),
            'routing-stage' => $this->buildRoutingStageTemplate($namespace, $className),
            'execution-handler' => $this->buildExecutionHandlerTemplate($namespace, $className),
            'runtime' => $this->buildRuntimeTemplate($namespace, $className),
            'rag-retriever' => $this->buildRagRetrieverTemplate($namespace, $className),
            'policy' => $this->buildPolicyTemplate($namespace, $className),
            default => throw new \InvalidArgumentException("Unsupported scaffold type: {$type}"),
        };
    }

    protected function buildAgentTemplate(string $namespace, string $className): string
    {
        $modelClass = $this->inferModelClass($className, 'agent');
        $name = Str::snake(Str::beforeLast($className, 'Config'));
        $name = $name !== '' ? $name : Str::snake($className);
        $description = trim((string) ($this->option('description') ?? ''));
        $description = $description !== '' ? $description : "AI operations for {$name}.";

        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Contracts\AutonomousModelConfig;

class {$className} extends AutonomousModelConfig
{
    public static function getModelClass(): string
    {
        return \\{$modelClass}::class;
    }

    public static function getName(): string
    {
        return '{$name}';
    }

    public static function getDescription(): string
    {
        return '{$this->escapeSingleQuotes($description)}';
    }

    public static function getFilterConfig(): array
    {
        return [
            // 'user_field' => 'user_id',
            // 'date_field' => 'created_at',
            // 'status_field' => 'status',
        ];
    }

    public static function getTools(): array
    {
        return [];
    }
}
PHP;
    }

    protected function buildFilterTemplate(string $namespace, string $className): string
    {
        $description = trim((string) ($this->option('description') ?? ''));
        $description = $description !== '' ? $description : 'Apply query constraints from request context.';

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Builder;

class {$className}
{
    /**
     * {$this->escapeSingleQuotes($description)}
     */
    public function __invoke(Builder \$query, array \$context = []): Builder
    {
        // Example: limit records to current user
        if (isset(\$context['user_id'])) {
            // \$query->where('user_id', \$context['user_id']);
        }

        return \$query;
    }
}
PHP;
    }

    protected function buildToolTemplate(string $namespace, string $className): string
    {
        $name = Str::snake(Str::beforeLast($className, 'Tool'));
        $name = $name !== '' ? $name : Str::snake($className);
        $description = trim((string) ($this->option('description') ?? ''));
        $description = $description !== '' ? $description : "Tool for {$name} operations.";
        $kind = strtolower(trim((string) ($this->option('kind') ?: 'simple')));

        if ($kind === 'lookup') {
            $modelClass = $this->inferModelClass($className, 'tool');

            return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Services\Agent\Tools\ModelBackedLookupTool;

class {$className} extends ModelBackedLookupTool
{
    public string \$name = '{$name}';

    public string \$description = '{$this->escapeSingleQuotes($description)}';

    protected string \$model = \\{$modelClass}::class;

    protected array \$search = ['name', 'email'];

    protected array \$returns = ['id', 'name', 'email'];
}
PHP;
        }

        if ($kind === 'upsert') {
            $modelClass = $this->inferModelClass($className, 'tool');

            return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Services\Agent\Tools\ModelBackedUpsertTool;

class {$className} extends ModelBackedUpsertTool
{
    public string \$name = '{$name}';

    public string \$description = '{$this->escapeSingleQuotes($description)}';

    protected string \$model = \\{$modelClass}::class;

    protected array \$identity = ['email'];

    protected array \$write = ['name', 'email'];

    protected array \$required = ['name'];

    protected array \$returns = ['id', 'name', 'email'];
}
PHP;
        }

        if ($kind === 'action') {
            $actionId = trim((string) ($this->option('action') ?: $name));

            return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Services\Agent\Tools\ActionBackedTool;

class {$className} extends ActionBackedTool
{
    public string \$name = '{$name}';

    public string \$description = '{$this->escapeSingleQuotes($description)}';

    public string \$actionId = '{$this->escapeSingleQuotes($actionId)}';
}
PHP;
        }

        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;

class {$className} extends SimpleAgentTool
{
    public string \$name = '{$name}';

    public string \$description = '{$this->escapeSingleQuotes($description)}';

    public array \$parameters = [
        'input' => [
            'type' => 'string',
            'required' => true,
            'description' => 'Input payload for tool execution',
        ],
    ];

    protected function handle(array \$parameters, UnifiedActionContext \$context): array
    {
        return [
            'message' => 'Tool executed successfully.',
            'data' => [
                'received' => \$parameters,
                'user_id' => \$context->userId,
            ],
        ];
    }
}
PHP;
    }

    protected function buildSkillTemplate(string $namespace, string $className): string
    {
        $base = Str::beforeLast($className, 'Skill');
        $id = Str::snake($base);
        $id = $id !== '' ? $id : Str::snake($className);
        $name = ucwords(str_replace('_', ' ', Str::snake($base !== '' ? $base : $className)));
        $description = trim((string) ($this->option('description') ?? ''));
        $description = $description !== '' ? $description : "Handle {$name} requests using configured tools and actions.";

        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Services\Agent\Skills\AgentSkill;

class {$className} extends AgentSkill
{
    public string \$id = '{$id}';

    public string \$name = '{$this->escapeSingleQuotes($name)}';

    public string \$description = '{$this->escapeSingleQuotes($description)}';

    public array \$triggers = [
        '{$id}',
    ];

    public array \$requiredData = [
        // 'customer_id',
        // 'items',
    ];

    public array \$tools = [
        // \\App\\AI\\Tools\\FindCustomerTool::class,
        // \\App\\AI\\Tools\\CreateInvoiceTool::class,
    ];

    public string \$finalTool = '';

    public array \$actions = [
        // '{$id}.create',
    ];

    public array \$capabilities = [
        '{$id}',
    ];

    public bool \$requiresConfirmation = true;

    public bool \$enabled = false;

    public array \$metadata = [
        'source' => 'scaffold',
        'review_required' => true,
    ];

    public function targetJson(): array
    {
        return [
            // 'customer_id' => null,
            // 'items' => [],
        ];
    }
}
PHP;
    }

    protected function buildRoutingStageTemplate(string $namespace, string $className): string
    {
        $name = Str::snake(Str::beforeLast($className, 'RoutingStage')) ?: Str::snake($className);

        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class {$className} implements RoutingStageContract
{
    public function name(): string
    {
        return '{$name}';
    }

    public function decide(string \$message, UnifiedActionContext \$context, array \$options = []): ?RoutingDecision
    {
        return null;
    }
}
PHP;
    }

    protected function buildExecutionHandlerTemplate(string $namespace, string $className): string
    {
        $action = Str::snake(Str::beforeLast($className, 'ExecutionHandler')) ?: Str::snake($className);

        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Contracts\ExecutionHandlerContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class {$className} implements ExecutionHandlerContract
{
    public function action(): string
    {
        return '{$action}';
    }

    public function handle(RoutingDecision \$decision, UnifiedActionContext \$context, array \$options = []): AgentResponse
    {
        return AgentResponse::failure('Execution handler is not implemented.', context: \$context);
    }
}
PHP;
    }

    protected function buildRuntimeTemplate(string $namespace, string $className): string
    {
        $name = Str::snake(Str::beforeLast($className, 'Runtime')) ?: Str::snake($className);

        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;

class {$className} implements AgentRuntimeContract
{
    public function name(): string
    {
        return '{$name}';
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        return AgentRuntimeCapabilities::laravel();
    }

    public function process(string \$message, string \$sessionId, mixed \$userId, array \$options = []): AgentResponse
    {
        return AgentResponse::failure('Runtime is not implemented.');
    }
}
PHP;
    }

    protected function buildRagRetrieverTemplate(string $namespace, string $className): string
    {
        $name = Str::snake(Str::beforeLast($className, 'RAGRetriever')) ?: Str::snake($className);

        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\Contracts\RAGRetrieverContract;

class {$className} implements RAGRetrieverContract
{
    public function name(): string
    {
        return '{$name}';
    }

    public function retrieve(array \$queries, array \$collections, array \$options = [], int|string|null \$userId = null): array
    {
        return [];
    }
}
PHP;
    }

    protected function buildPolicyTemplate(string $namespace, string $className): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use LaravelAIEngine\DTOs\UnifiedActionContext;

class {$className}
{
    public function canExecute(string \$action, ?UnifiedActionContext \$context = null, array \$payload = []): bool
    {
        return true;
    }

    public function sanitize(array \$payload): array
    {
        return \$payload;
    }
}
PHP;
    }

    protected function loadManifest(string $path): array
    {
        $default = [
            'model_configs' => [],
            'tools' => [],
            'filters' => [],
            'skill_providers' => [],
            'skills' => [],
            'routing_stages' => [],
            'execution_handlers' => [],
            'runtimes' => [],
            'rag_retrievers' => [],
            'policies' => [],
        ];

        if (!is_file($path)) {
            return $default;
        }

        try {
            $loaded = require $path;
            if (!is_array($loaded)) {
                return $default;
            }

            return array_merge($default, $loaded);
        } catch (\Throwable) {
            return $default;
        }
    }

    protected function renderManifest(array $manifest): string
    {
        $normalized = [
            'model_configs' => array_values(array_unique(array_values((array) ($manifest['model_configs'] ?? [])))),
            'tools' => (array) ($manifest['tools'] ?? []),
            'filters' => (array) ($manifest['filters'] ?? []),
            'skill_providers' => (array) ($manifest['skill_providers'] ?? []),
            'skills' => (array) ($manifest['skills'] ?? []),
            'routing_stages' => (array) ($manifest['routing_stages'] ?? []),
            'execution_handlers' => (array) ($manifest['execution_handlers'] ?? []),
            'runtimes' => (array) ($manifest['runtimes'] ?? []),
            'rag_retrievers' => (array) ($manifest['rag_retrievers'] ?? []),
            'policies' => (array) ($manifest['policies'] ?? []),
        ];

        return "<?php\n\nreturn " . var_export($normalized, true) . ";\n";
    }

    protected function escapeSingleQuotes(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    protected function normalizePath(string $path): string
    {
        if ($path === '') {
            return app_path('AI/agent-manifest.php');
        }

        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
