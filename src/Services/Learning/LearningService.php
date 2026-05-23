<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

use LaravelAIEngine\Contracts\Learning\LearningSourceAdapterInterface;
use LaravelAIEngine\DTOs\LearningIngestionResult;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Repositories\LearningRepository;
class LearningService
{
    /**
     * @param array<int, LearningSourceAdapterInterface> $adapters
     */
    public function __construct(
        protected LearningRepository $repository,
        protected LearningExtractorService $extractor,
        protected LearningVectorIndexer $vectorIndexer,
        protected LearningSourceGuard|array|null $guard = null,
        protected array $adapters = [],
    ) {
        if (is_array($this->guard)) {
            $this->adapters = $this->guard;
            $this->guard = null;
        }

        $this->guard ??= app(LearningSourceGuard::class);

        if ($this->adapters === []) {
            $this->adapters = $this->configuredAdapters();
        }
    }

    public function ingest(LearningSourceRequest $request): LearningIngestionResult
    {
        $adapter = $this->adapterFor($request);
        $payload = $adapter->fetch($request);
        $this->guard->assertContentAllowed($payload->content, $request->source);
        $items = $this->extractor->extract($request, $payload);
        $source = $this->repository->store($request, $payload, $items);

        $vectorStoreId = null;
        if ($request->shouldIndex) {
            $vectorStoreId = $this->vectorIndexer->index($source, $request->vectorStoreId, $request->vectorStoreName);
            $this->repository->markIndexed($source->sourceId, $vectorStoreId);
            $source->vectorStoreId = $vectorStoreId;
        }

        return new LearningIngestionResult(
            source: $source,
            itemsCount: count($items),
            items: $items,
            vectorStoreId: $vectorStoreId,
        );
    }

    public function search(string $query, array $scope = [], int $limit = 5, ?string $type = null): array
    {
        return $this->repository->search($query, $scope, $limit, $type);
    }

    protected function adapterFor(LearningSourceRequest $request): LearningSourceAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($request)) {
                return $adapter;
            }
        }

        throw new \InvalidArgumentException("No learning adapter supports source type [{$request->sourceType}].");
    }

    /**
     * @return array<int, LearningSourceAdapterInterface>
     */
    protected function configuredAdapters(): array
    {
        return array_values(array_map(
            static fn (string $class): LearningSourceAdapterInterface => app($class),
            array_values(array_filter((array) config('ai-engine.learning.adapter_classes', [])))
        ));
    }
}
