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
     * @return array<int, string>
     */
    abstract protected function identityFields(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function writeFields(): array;

    /**
     * @return array<int, string>
     */
    protected function requiredFields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(UnifiedActionContext $context, array $parameters): array
    {
        return [];
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
            return ActionResult::failure('The requested records are not available.', [
                'success' => false,
                'message' => 'The requested records are not available.',
            ]);
        }

        $payload = array_merge($this->defaults($context, $parameters), Arr::only($parameters, $this->writeFields()));
        $missing = $this->missingRequired($payload, $this->requiredFields());
        if ($missing !== []) {
            return ActionResult::failure('Required fields are missing.', [
                'success' => false,
                'message' => 'Required fields are missing.',
                'missing_fields' => $missing,
            ]);
        }

        $identity = $this->identityPayload($modelClass, $payload, $this->identityFields());
        if ($identity === []) {
            return ActionResult::failure('At least one identity field is required.', [
                'success' => false,
                'message' => 'At least one identity field is required.',
                'missing_fields' => $this->identityFields(),
            ]);
        }

        $attributes = $this->existingColumnPayload($modelClass, $payload);
        $record = $modelClass::query()->updateOrCreate($identity, $attributes);
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
