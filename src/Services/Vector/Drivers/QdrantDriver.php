<?php

namespace LaravelAIEngine\Services\Vector\Drivers;

use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class QdrantDriver implements VectorDriverInterface
{
    protected Client $client;
    protected string $host;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? config('ai-engine.vector.drivers.qdrant.host', 'http://localhost:6333');
        $this->apiKey = $config['api_key'] ?? config('ai-engine.vector.drivers.qdrant.api_key');
        $this->timeout = $config['timeout'] ?? config('ai-engine.vector.drivers.qdrant.timeout', 30);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey) {
            $headers['api-key'] = $this->apiKey;
        }

        $this->client = new Client([
            'base_uri' => $this->host,
            'timeout' => $this->timeout,
            'headers' => $headers,
        ]);
    }

    public function createCollection(string $name, int $dimensions, array $config = []): bool
    {
        try {
            $response = $this->client->put("/collections/{$name}", [
                'json' => [
                    'vectors' => [
                        'size' => $dimensions,
                        'distance' => $config['distance'] ?? 'Cosine',
                    ],
                    'optimizers_config' => [
                        'default_segment_number' => $config['segment_number'] ?? 2,
                    ],
                    'replication_factor' => $config['replication_factor'] ?? 1,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant create collection failed', [
                'collection' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function deleteCollection(string $name): bool
    {
        try {
            $response = $this->client->delete("/collections/{$name}");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant delete collection failed', [
                'collection' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function collectionExists(string $name): bool
    {
        try {
            $response = $this->client->get("/collections/{$name}");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    public function upsert(string $collection, array $vectors): bool
    {
        try {
            $points = array_map(function ($vector) {
                return [
                    'id' => $vector['id'],
                    'vector' => $vector['vector'],
                    'payload' => $vector['metadata'] ?? [],
                ];
            }, $vectors);

            $response = $this->client->put("/collections/{$collection}/points", [
                'json' => [
                    'points' => $points,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant upsert failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        float $threshold = 0.0,
        array $filters = []
    ): array {
        try {
            $body = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($threshold > 0) {
                $body['score_threshold'] = $threshold;
            }

            if (!empty($filters)) {
                $body['filter'] = $this->buildFilter($filters);
            }

            $response = $this->client->post("/collections/{$collection}/points/search", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(function ($result) {
                return [
                    'id' => $result['id'],
                    'score' => $result['score'],
                    'metadata' => $result['payload'] ?? [],
                ];
            }, $data['result'] ?? []);
        } catch (GuzzleException $e) {
            Log::error('Qdrant search failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function delete(string $collection, array $ids): bool
    {
        try {
            $response = $this->client->post("/collections/{$collection}/points/delete", [
                'json' => [
                    'points' => $ids,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant delete failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getCollectionInfo(string $collection): array
    {
        try {
            $response = $this->client->get("/collections/{$collection}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            return $data['result'] ?? [];
        } catch (GuzzleException $e) {
            Log::error('Qdrant get collection info failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function get(string $collection, string $id): ?array
    {
        try {
            $response = $this->client->get("/collections/{$collection}/points/{$id}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            $result = $data['result'] ?? null;
            if (!$result) {
                return null;
            }

            return [
                'id' => $result['id'],
                'vector' => $result['vector'] ?? [],
                'metadata' => $result['payload'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return null;
        }
    }

    public function updateMetadata(string $collection, string $id, array $metadata): bool
    {
        try {
            $response = $this->client->post("/collections/{$collection}/points/payload", [
                'json' => [
                    'points' => [$id],
                    'payload' => $metadata,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant update metadata failed', [
                'collection' => $collection,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function count(string $collection, array $filters = []): int
    {
        try {
            $body = ['exact' => true];
            
            if (!empty($filters)) {
                $body['filter'] = $this->buildFilter($filters);
            }

            $response = $this->client->post("/collections/{$collection}/points/count", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['result']['count'] ?? 0;
        } catch (GuzzleException $e) {
            Log::error('Qdrant count failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function scroll(string $collection, int $limit = 100, ?string $offset = null): array
    {
        try {
            $body = [
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($offset) {
                $body['offset'] = $offset;
            }

            $response = $this->client->post("/collections/{$collection}/points/scroll", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return [
                'points' => array_map(function ($point) {
                    return [
                        'id' => $point['id'],
                        'metadata' => $point['payload'] ?? [],
                    ];
                }, $data['result']['points'] ?? []),
                'next_offset' => $data['result']['next_page_offset'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('Qdrant scroll failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return ['points' => [], 'next_offset' => null];
        }
    }

    /**
     * Build Qdrant filter from array
     */
    protected function buildFilter(array $filters): array
    {
        $must = [];

        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $must[] = [
                    'key' => $key,
                    'match' => ['any' => $value],
                ];
            } else {
                $must[] = [
                    'key' => $key,
                    'match' => ['value' => $value],
                ];
            }
        }

        return ['must' => $must];
    }
}
