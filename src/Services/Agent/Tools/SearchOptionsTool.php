<?php

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class SearchOptionsTool extends AgentTool
{
    public function __construct(
        protected AIEngineService $ai
    ) {}

    public function getName(): string
    {
        return 'search_options';
    }

    public function getDescription(): string
    {
        return 'Search for available options for a field based on context';
    }

    public function getParameters(): array
    {
        return [
            'field_name' => [
                'type' => 'string',
                'description' => 'Name of the field to search options for',
                'required' => true,
            ],
            'query' => [
                'type' => 'string',
                'description' => 'Search query or context',
                'required' => false,
            ],
            'model_class' => [
                'type' => 'string',
                'description' => 'Model class to search in (e.g., App\\Models\\Category)',
                'required' => false,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $fieldName = $parameters['field_name'];
        $query = $parameters['query'] ?? '';
        $modelClass = $parameters['model_class'] ?? null;

        $options = [];

        if ($modelClass && class_exists($modelClass)) {
            $options = $this->searchInModel($modelClass, $query);
        } else {
            $options = $this->searchWithAI($fieldName, $query, $context);
        }

        if (empty($options)) {
            return ActionResult::failure(
                error: "No options found for field '{$fieldName}'",
                data: ['field' => $fieldName, 'query' => $query]
            );
        }

        return ActionResult::success(
            message: "Found " . count($options) . " options for '{$fieldName}'",
            data: [
                'field' => $fieldName,
                'options' => $options,
                'count' => count($options),
            ]
        );
    }

    protected function searchInModel(string $modelClass, string $query): array
    {
        try {
            $model = new $modelClass();
            $queryBuilder = $modelClass::query();

            if (!empty($query)) {
                $searchableFields = ['name', 'title', 'label'];
                $queryBuilder->where(function ($q) use ($searchableFields, $query) {
                    foreach ($searchableFields as $field) {
                        if (schema()->hasColumn($q->getModel()->getTable(), $field)) {
                            $q->orWhere($field, 'LIKE', "%{$query}%");
                        }
                    }
                });
            }

            return $queryBuilder->limit(10)->pluck('name', 'id')->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function searchWithAI(string $fieldName, string $query, UnifiedActionContext $context): array
    {
        $prompt = "Suggest appropriate options for the field '{$fieldName}'.\n\n";
        
        if (!empty($query)) {
            $prompt .= "Context: {$query}\n\n";
        }

        $prompt .= "Provide 5-10 relevant options as a JSON array.\n";
        $prompt .= "Format: [\"option1\", \"option2\", \"option3\"]";

        try {
            $request = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0.7
            );

            $response = $this->ai->generate($request);
            
            if (preg_match('/\[[\s\S]*\]/', $response->content, $matches)) {
                $options = json_decode($matches[0], true);
                return is_array($options) ? $options : [];
            }
        } catch (\Exception $e) {
            return [];
        }

        return [];
    }
}
