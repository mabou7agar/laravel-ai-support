<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use GuzzleHttp\Client;
use LaravelAIEngine\Contracts\VectorStoreDriverInterface;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Repositories\VectorStoreRepository;
use LaravelAIEngine\Services\SDK\VectorStores\LocalVectorStoreDriver;
use LaravelAIEngine\Services\SDK\VectorStores\OpenAIVectorStoreDriver;

class VectorStoreService
{
    protected VectorStoreDriverInterface $driver;

    public function __construct(
        protected ?VectorStoreRepository $repository = null,
        ?VectorStoreDriverInterface $driver = null
    )
    {
        $this->repository = $repository ?? (function_exists('app') ? app(VectorStoreRepository::class) : null);
        $this->driver = $driver ?? new LocalVectorStoreDriver($this->repository);
    }

    public function create(string $name, array $metadata = []): array
    {
        return $this->driver->create($name, $metadata);
    }

    public function add(string $storeId, Document|string $document, array $metadata = []): array
    {
        return $this->driver->add($storeId, $document, $metadata);
    }

    public function get(string $storeId): ?array
    {
        return $this->driver->get($storeId);
    }

    public function delete(string $storeId): bool
    {
        return $this->driver->delete($storeId);
    }

    public function local(): self
    {
        return new self($this->repository, new LocalVectorStoreDriver($this->repository));
    }

    public function provider(string $provider, array $config = [], ?Client $client = null): self
    {
        return new self($this->repository, $this->makeDriver($provider, $config, $client));
    }

    protected function makeDriver(string $provider, array $config = [], ?Client $client = null): VectorStoreDriverInterface
    {
        return match (strtolower($provider)) {
            'local' => new LocalVectorStoreDriver($this->repository),
            'openai' => new OpenAIVectorStoreDriver(
                array_replace_recursive((array) config('ai-engine.engines.openai', []), $config),
                $client
            ),
            default => throw new \InvalidArgumentException("Vector store provider [{$provider}] is not supported."),
        };
    }
}
