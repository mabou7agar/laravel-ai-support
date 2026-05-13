<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools;

class SimilaritySearch
{
    public function __construct(
        protected mixed $using,
        protected string $description = 'Search for documents similar to the query.',
        protected float $minSimilarity = 0.0,
        protected int $limit = 10
    ) {}

    public static function usingModel(
        string $model,
        string $column,
        float $minSimilarity = 0.0,
        int $limit = 10,
        ?callable $query = null
    ): self {
        return new self(
            using: [
                'type' => 'model',
                'model' => $model,
                'column' => $column,
                'query' => $query,
            ],
            minSimilarity: $minSimilarity,
            limit: $limit
        );
    }

    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function toArray(): array
    {
        $parameters = [
            'query' => [
                'type' => 'string',
                'description' => 'The semantic search query.',
                'required' => true,
            ],
        ];

        return [
            'name' => 'similarity_search',
            'description' => $this->description,
            'parameters' => $parameters,
            'metadata' => [
                'type' => 'similarity_search',
                'min_similarity' => $this->minSimilarity,
                'limit' => $this->limit,
                'using' => is_array($this->using) ? array_diff_key($this->using, ['query' => true]) : 'callback',
            ],
        ];
    }

    public function search(string $query): mixed
    {
        if (is_callable($this->using)) {
            return ($this->using)($query);
        }

        if (is_array($this->using) && ($this->using['type'] ?? null) === 'model') {
            $model = (string) $this->using['model'];
            $column = (string) $this->using['column'];
            $builder = $model::query();

            if (is_callable($this->using['query'] ?? null)) {
                $builder = ($this->using['query'])($builder) ?? $builder;
            }

            if (method_exists($builder, 'whereVectorSimilarTo')) {
                $builder->whereVectorSimilarTo($column, $query);
            }

            return $builder->limit($this->limit)->get();
        }

        return [];
    }
}
