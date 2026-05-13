<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK\FileStores;

use GuzzleHttp\Client;
use LaravelAIEngine\Contracts\FileStoreDriverInterface;
use LaravelAIEngine\Files\Document;

class AnthropicFileStoreDriver implements FileStoreDriverInterface
{
    public function __construct(
        protected array $config = [],
        protected ?Client $client = null
    ) {
        $this->client ??= new Client([
            'base_uri' => $this->config['base_url'] ?? 'https://api.anthropic.com',
            'timeout' => $this->config['timeout'] ?? 60,
        ]);
    }

    public function upload(Document|string $document, array $metadata = []): array
    {
        $document = $document instanceof Document
            ? $document
            : Document::fromPath($document, $metadata);

        $path = $document->disk === null
            ? $document->source
            : tempnam(sys_get_temp_dir(), 'ai-engine-anthropic-file-');

        if ($document->disk !== null) {
            file_put_contents($path, $document->content());
        }

        try {
            $response = $this->client->post('/v1/files', [
                'headers' => $this->headers(),
                'multipart' => [[
                    'name' => 'file',
                    'contents' => fopen($path, 'r'),
                    'filename' => basename($document->source),
                ]],
            ]);
        } finally {
            if ($document->disk !== null && is_string($path) && is_file($path)) {
                unlink($path);
            }
        }

        return $this->decode($response->getBody()->getContents());
    }

    public function get(string $fileId): ?array
    {
        $response = $this->client->get("/v1/files/{$fileId}", [
            'headers' => $this->headers(),
        ]);

        return $this->decode($response->getBody()->getContents());
    }

    public function delete(string $fileId): bool
    {
        $response = $this->client->delete("/v1/files/{$fileId}", [
            'headers' => $this->headers(),
        ]);

        $payload = $this->decode($response->getBody()->getContents());

        return (bool) ($payload['deleted'] ?? true);
    }

    protected function headers(): array
    {
        return [
            'x-api-key' => $this->config['api_key'] ?? '',
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'files-api-2025-04-14',
        ];
    }

    protected function decode(string $payload): array
    {
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
