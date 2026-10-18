<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Schema\SchemaNormalizer;
use Laravel\Ai\Tools\Request;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolExecutor;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolSchemaMapper;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

/**
 * Exposes a package AgentTool to a laravel/ai agent.
 *
 * - The schema is the tool's parameter definitions mapped to JSON Schema with
 *   the same mapper the AiNative planner uses, then converted the way the SDK
 *   converts MCP tool schemas.
 * - Calls run through AiNativeToolExecutor, so the execution policy
 *   (`ai-agent.execution_policy`) is enforced exactly as for built-in turns,
 *   and the tool receives the package conversation context with the run's
 *   request options (tenant and workspace scope) attached.
 * - A tool that requires confirmation asks the SDK for approval. The
 *   laravel_ai runtime turns that into a package step approval, and the tool
 *   runs with `confirmed` set once the approval is granted and resumed.
 *
 * Only loaded by SdkLaravelAiGateway, which is only built when laravel/ai is installed.
 */
final class PackageAgentTool implements Tool, Approvable
{
    use InteractsWithApprovals;

    /**
     * @param \Closure(): UnifiedActionContext $context
     */
    public function __construct(
        private readonly AgentTool $tool,
        private readonly \Closure $context
    ) {
    }

    public function name(): string
    {
        return $this->tool->getName();
    }

    public function description(): string
    {
        return $this->tool->getDescription();
    }

    public function schema(JsonSchema $schema): array
    {
        $definition = app(AiNativeToolSchemaMapper::class)->objectSchema($this->tool->getParameters());

        if (($definition['properties'] ?? []) === []) {
            return [];
        }

        try {
            $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize($definition));
        } catch (\Throwable) {
            return [];
        }

        return $type instanceof ObjectType
            ? (fn (): array => $this->properties)->call($type)
            : [];
    }

    public function handle(Request $request): string
    {
        $executor = app(AiNativeToolExecutor::class);
        $parameters = $request->all();

        if ($this->tool->requiresConfirmation()) {
            // Reaching handle() means the SDK approval was granted.
            $parameters = $executor->withConfirmationForValidation($this->tool, $parameters);
        }

        $errors = $this->tool->validate($parameters);
        if ($errors !== []) {
            return $this->encode(['success' => false, 'errors' => array_values($errors)]);
        }

        try {
            $result = $executor->execute($this->tool, $parameters, ($this->context)());
        } catch (\Throwable $e) {
            return $this->encode(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->encode($this->tool->resultForPlanner($result));
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return $this->tool->requiresConfirmation()
            ? Approval::required($this->tool->getConfirmationMessage())
            : false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
