<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

abstract class ModelBackedUpsertTool extends ModelBackedLookupTool
{
    /**
     * @var array<int, string>
     */
    protected array $identity = [];

    /**
     * @var array<int, string>
     */
    protected array $write = [];

    /**
     * @var array<int, string>
     */
    protected array $required = [];

    /**
     * @var array<string, mixed>
     */
    protected array $defaultValues = [];

    /**
     * @return array<int, string>
     */
    protected function identityFields(): array
    {
        return $this->identity;
    }

    /**
     * @return array<int, string>
     */
    protected function writeFields(): array
    {
        return $this->write;
    }

    /**
     * @return array<int, string>
     */
    protected function requiredFields(): array
    {
        return $this->required;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(UnifiedActionContext $context, array $parameters): array
    {
        return $this->defaultValues;
    }

    /**
     * @return array<int, string>
     */
    protected function searchColumns(): array
    {
        return $this->identityFields();
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        $parameters = [];
        foreach ($this->writeFields() as $field) {
            $parameters[$field] = [
                'type' => 'string',
                'required' => in_array($field, $this->requiredFields(), true),
                'description' => str_replace('_', ' ', $field),
            ];
        }

        return $parameters;
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $modelClass = $this->modelClass();
        if (!$this->tableExists($modelClass)) {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.records_unavailable', 'The requested records are not available.'), [
                'success' => false,
                'message' => 'The requested records are not available.',
            ]);
        }

        $payload = array_merge($this->defaults($context, $parameters), Arr::only($parameters, $this->writeFields()));
        $missing = $this->missingRequired($payload, $this->requiredFields());
        if ($missing !== []) {
            // Ask for the missing input instead of hard-failing, so the
            // orchestrator re-prompts the user (consistent with the guided
            // needs_user_input UX used by higher-level tools like create_invoice).
            return ActionResult::needsUserInput(
                'Please provide the required ' . (count($missing) === 1 ? 'field' : 'fields')
                    . ': ' . implode(', ', $missing) . '.',
                ['missing_fields' => $missing]
            );
        }

        $identity = $this->identityPayload($modelClass, $payload, $this->identityFields());
        if ($identity === []) {
            return ActionResult::needsUserInput(
                'Please provide at least one of: ' . implode(', ', $this->identityFields()) . '.',
                ['missing_fields' => $this->identityFields()]
            );
        }

        $attributes = $this->existingColumnPayload($modelClass, $payload);

        // Match only inside the caller's scope. Without this, an identity like
        // ['name' => 'Acme'] would find and overwrite another tenant's row, then
        // re-stamp it with this caller's scope columns.
        $scope = $this->scopeConstraints($modelClass, $context, $parameters);
        $record = $modelClass::query()
            ->where($scope)
            ->updateOrCreate(array_merge($identity, $scope), $attributes);
        $created = $record->wasRecentlyCreated;
        $record = $record->fresh() ?: $record;

        return ActionResult::success(
            'Record saved.',
            array_merge([
                'success' => true,
                'created' => $created,
            ], $this->recordPayload($record, $this->returnColumns()))
        );
    }

    /**
     * Scope columns (e.g. workspace_id, created_by) that exist on the table and carry a value.
     *
     * @param class-string<Model> $modelClass
     * @return array<string, mixed>
     */
    protected function scopeConstraints(string $modelClass, UnifiedActionContext $context, array $parameters): array
    {
        $constraints = [];
        foreach ($this->scope($context, $parameters) as $column => $value) {
            if ($value !== null && $value !== '' && $this->columnExists($modelClass, (string) $column)) {
                $constraints[(string) $column] = $value;
            }
        }

        return $constraints;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function existingColumnPayload(string $modelClass, array $payload): array
    {
        return array_filter(
            $payload,
            fn (string $column): bool => $this->columnExists($modelClass, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $payload
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    protected function identityPayload(string $modelClass, array $payload, array $fields): array
    {
        foreach ($fields as $field) {
            if (!$this->columnExists($modelClass, $field)) {
                continue;
            }

            $value = $payload[$field] ?? null;
            if ($value !== null && $value !== '') {
                return [$field => $value];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    protected function missingRequired(array $payload, array $fields): array
    {
        return array_values(array_filter(
            $fields,
            static fn (string $field): bool => ($payload[$field] ?? null) === null || ($payload[$field] ?? '') === ''
        ));
    }
}
