<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK\VectorStores;

use GuzzleHttp\Client;
use LaravelAIEngine\Contracts\VectorStoreDriverInterface;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\SDK\FileStores\OpenAIFileStoreDriver;

class OpenAIVectorStoreDriver implements VectorStoreDriverInterface
{
    protected OpenAIFileStoreDriver $files;

    public function __construct(
        protected array $config = [],
        protected ?Client $client = null,
        ?OpenAIFileStoreDriver $files = null
    ) {
        $this->client ??= new Client([
            'base_uri' => $this->config['base_url'] ?? 'https://api.openai.com',
            'timeout' => $this->config['timeout'] ?? 60,
        ]);
        $this->files = $files ?? new OpenAIFileStoreDriver($this->config, $this->client);
    }

    public function create(string $name, array $metadata = []): array
    {
        $response = $this->client->post('/v1/vector_stores', [
            'headers' => $this->headers(),
            'json' => array_filter([
                'name' => $name,
                'metadata' => $metadata,
            ], static fn ($value): bool => $value !== []),
        ]);

        return $this->normalize($this->decode($response->getBody()->getContents()));
    }

    public function add(string $storeId, Document|string $document, array $metadata = []): array
    {
        $document = $document instanceof Document
            ? $document
            : Document::fromPath($document, $metadata);

        $fileId = $document->metadata['provider_file_id'] ?? null;
        if (!is_string($fileId) || $fileId === '') {
            $file = $this->files->upload($document, ['purpose' => 'assistants']);
            $fileId = (string) ($file['id'] ?? '');
        }

        $this->client->post("/v1/vector_stores/{$storeId}/files", [
            'headers' => $this->headers(),
            'json' => ['file_id' => $fileId],
        ]);

        return $this->get($storeId) ?? [
            'id' => $storeId,
            'provider' => 'openai',
            'documents' => [['id' => $fileId]],
        ];
    }

    public function get(string $storeId): ?array
    {
        $response = $this->client->get("/v1/vector_stores/{$storeId}", [
            'headers' => $this->headers(),
        ]);

        return $this->normalize($this->decode($response->getBody()->getContents()));
    }

    public function delete(string $storeId): bool
    {
        $response = $this->client->delete("/v1/vector_stores/{$storeId}", [
            'headers' => $this->headers(),
        ]);

        $payload = $this->decode($response->getBody()->getContents());

        return (bool) ($payload['deleted'] ?? false);
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
            'OpenAI-Beta' => $this->config['beta'] ?? 'assistants=v2',
        ];
    }

    protected function normalize(array $payload): array
    {
        $payload['provider'] = 'openai';
        $payload['documents'] = $payload['documents'] ?? [];

        return $payload;
    }

    protected function decode(string $payload): array
    {
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
