<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use GuzzleHttp\Client;
use LaravelAIEngine\Contracts\FileStoreDriverInterface;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\SDK\FileStores\AnthropicFileStoreDriver;
use LaravelAIEngine\Services\SDK\FileStores\OpenAIFileStoreDriver;

class FileStoreService
{
    protected ?FileStoreDriverInterface $driver = null;

    public function provider(string $provider, array $config = [], ?Client $client = null): self
    {
        $clone = clone $this;
        $clone->driver = $this->makeDriver($provider, $config, $client);

        return $clone;
    }

    public function upload(Document|string $document, array $metadata = []): array
    {
        return $this->requireDriver()->upload($document, $metadata);
    }

    public function get(string $fileId): ?array
    {
        return $this->requireDriver()->get($fileId);
    }

    public function delete(string $fileId): bool
    {
        return $this->requireDriver()->delete($fileId);
    }

    protected function requireDriver(): FileStoreDriverInterface
    {
        if ($this->driver === null) {
            throw new \LogicException('Select a file store provider before using hosted file storage.');
        }

        return $this->driver;
    }

    protected function makeDriver(string $provider, array $config = [], ?Client $client = null): FileStoreDriverInterface
    {
        return match (strtolower($provider)) {
            'openai' => new OpenAIFileStoreDriver(
                array_replace_recursive((array) config('ai-engine.engines.openai', []), $config),
                $client
            ),
            'anthropic' => new AnthropicFileStoreDriver(
                array_replace_recursive((array) config('ai-engine.engines.anthropic', []), $config),
                $client
            ),
            default => throw new \InvalidArgumentException("File store provider [{$provider}] is not supported."),
        };
    }
}
