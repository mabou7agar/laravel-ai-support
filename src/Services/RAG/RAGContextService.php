<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class RAGContextService
{
    public function __construct(
        protected RAGModelMetadataService $models,
        protected ?RAGDecisionPolicy $policy = null,
        protected ?NodeRegistryService $nodeRegistry = null
    ) {
        $this->policy = $policy ?? new RAGDecisionPolicy();
        $this->nodeRegistry = $nodeRegistry ?? (app()->bound(NodeRegistryService::class) ? app(NodeRegistryService::class) : null);
    }

    public function build(string $message, array $conversationHistory, $userId, array $options): array
    {
        $businessContext = $this->policy->decisionBusinessContext();
        $nodes = $this->getAvailableNodes();
        $models = $this->mergeRemoteNodeModels(
            $this->models->getAvailableModels($options),
            $nodes
        );

        return [
            'conversation' => $this->summarizeConversation(
                $conversationHistory,
                is_string($options['conversation_summary'] ?? null) ? $options['conversation_summary'] : null
            ),
            'models' => $models,
            'nodes' => $nodes,
            'session_id' => $options['session_id'] ?? null,
            'user_id' => $userId,
            'tenant_id' => $options['tenant_id'] ?? ($options['tenant'] ?? null),
            'app_id' => $options['app_id'] ?? ($options['application_id'] ?? null),
            'domain' => $options['business_domain'] ?? ($businessContext['domain'] ?? null),
            'locale' => $options['locale'] ?? app()->getLocale(),
            'is_master' => config('ai-engine.nodes.is_master', true),
            'last_entity_list' => $options['last_entity_list'] ?? null,
            'selected_entity' => $options['selected_entity'] ?? null,
            'target_model' => $options['target_model'] ?? null,
        ];
    }

    public function summarizeConversation(array $history, ?string $compactSummary = null): string
    {
        $compactSummary = trim((string) $compactSummary);

        if (empty($history)) {
            if ($compactSummary !== '') {
                return "Earlier conversation summary:\n{$compactSummary}";
            }

            return 'No previous conversation.';
        }

        $summary = $compactSummary !== ''
            ? "Earlier conversation summary:\n{$compactSummary}\n\nRecent conversation:\n"
            : "Recent conversation:\n";
        foreach (array_slice($history, -$this->policy->conversationSummaryMessageLimit()) as $message) {
            $content = $message['content'] ?? '';
            $limit = $this->policy->conversationSummaryExcerptLimit();
            if (strlen($content) > $limit) {
                $content = substr($content, 0, $limit) . '...';
            }

            $summary .= '- ' . ($message['role'] ?? 'unknown') . ': ' . $content . "\n";
        }

        return $summary;
    }

    public function getAvailableNodes(): array
    {
        $nodes = new Collection();

        if ($this->nodeRegistry) {
            try {
                $nodes = $this->nodeRegistry->getActiveNodes();
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Failed loading nodes from NodeRegistryService', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (
            $nodes->isEmpty()
            && config('ai-engine.nodes.enabled', true)
            && Schema::hasTable((new AINode())->getTable())
        ) {
            $nodes = AINode::active()->healthy()->get();
        }

        return $nodes->map(function ($node) {
            $collections = $node->collections ?? [];
            $models = [];

            if (!empty($collections) && is_array($collections)) {
                $firstItem = reset($collections);
                if (is_array($firstItem) && isset($firstItem['name'])) {
                    $models = collect($collections)->map(fn (array $collection) => [
                        'name' => $collection['name'],
                        'display_name' => $collection['display_name'] ?? $collection['name'],
                        'description' => $collection['description'] ?? "Model for {$collection['name']} data",
                        'aliases' => array_values((array) ($collection['aliases'] ?? [])),
                        'capabilities' => $collection['capabilities'] ?? [],
                    ])->toArray();
                } else {
                    $models = collect($collections)->map(fn ($collection) => [
                        'name' => strtolower(class_basename($collection)),
                        'display_name' => class_basename($collection),
                        'description' => 'Model for ' . class_basename($collection) . ' data',
                        'aliases' => [],
                        'capabilities' => [],
                    ])->toArray();
                }
            }

            return [
                'slug' => $node->slug,
                'name' => $node->name,
                'description' => $node->description,
                'models' => $models,
                'collections' => $collections,
            ];
        })->toArray();
    }

    protected function mergeRemoteNodeModels(array $models, array $nodes): array
    {
        $normalized = collect($models)
            ->filter(fn ($model) => is_array($model) && !empty($model['name']))
            ->mapWithKeys(fn (array $model) => [strtolower((string) $model['name']) => $model])
            ->all();

        foreach ($nodes as $node) {
            $slug = (string) ($node['slug'] ?? '');
            $collections = (array) ($node['collections'] ?? []);

            foreach ($collections as $collection) {
                if (is_array($collection)) {
                    $name = strtolower(trim((string) ($collection['name'] ?? '')));
                    $class = trim((string) ($collection['class'] ?? $name));
                    $description = (string) ($collection['description'] ?? "Remote model for {$name} data");
                } else {
                    $raw = trim((string) $collection);
                    $name = strtolower(class_basename($raw));
                    $class = $raw;
                    $description = "Remote model for {$name} data";
                }

                if ($name === '' || isset($normalized[$name])) {
                    continue;
                }

                $normalized[$name] = [
                    'name' => $name,
                    'class' => $class,
                    'display_name' => (string) ($collection['display_name'] ?? $name),
                    'table' => str_ends_with($name, 's') ? $name : $name . 's',
                    'description' => $description,
                    'aliases' => array_values((array) ($collection['aliases'] ?? [])),
                    'location' => 'remote',
                    'capabilities' => [
                        'db_query' => true,
                        'db_count' => true,
                        'vector_search' => true,
                        'crud' => false,
                    ],
                    'schema' => [],
                    'filter_config' => [],
                    'tools' => [],
                    'node_slug' => $slug,
                ];
            }
        }

        return array_values($normalized);
    }
}
