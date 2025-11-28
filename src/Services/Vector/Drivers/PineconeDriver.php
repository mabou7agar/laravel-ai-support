<?php

namespace LaravelAIEngine\Services\Vector\Drivers;

use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class PineconeDriver implements VectorDriverInterface
{
    protected Client $client;
    protected string $apiKey;
    protected string $environment;
    protected int $timeout;
    protected ?string $indexHost = null;

    public function __construct(array $config = [])
    {
        $this->apiKey = $config['api_key'] ?? config('ai-engine.vector.drivers.pinecone.api_key');
        $this->environment = $config['environment'] ?? config('ai-engine.vector.drivers.pinecone.environment', 'us-west1-gcp');
        $this->timeout = $config['timeout'] ?? config('ai-engine.vector.drivers.pinecone.timeout', 30);

        $this->client = new Client([
            'timeout' => $this->timeout,
            'headers' => [
                'Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function createCollection(string $name, int $dimensions, array $config = []): bool
    {
        try {
            $response = $this->client->post("https://controller.{$this->environment}.pinecone.io/databases", [
                'json' => [
                    'name' => $name,
                    'dimension' => $dimensions,
                    'metric' => $config['metric'] ?? 'cosine',
                    'pods' => $config['pods'] ?? 1,
                    'replicas' => $config['replicas'] ?? 1,
                    'pod_type' => $config['pod_type'] ?? 'p1.x1',
                ],
            ]);

            return $response->getStatusCode() === 201;
        } catch (GuzzleException $e) {
            Log::error('Pinecone create index failed', [
                'index' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function deleteCollection(string $name): bool
    {
        try {
            $response = $this->client->delete("https://controller.{$this->environment}.pinecone.io/databases/{$name}");
            return $response->getStatusCode() === 202;
        } catch (GuzzleException $e) {
            Log::error('Pinecone delete index failed', [
                'index' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function collectionExists(string $name): bool
    {
        try {
            $response = $this->client->get("https://controller.{$this->environment}.pinecone.io/databases/{$name}");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    public function upsert(string $collection, array $vectors): bool
    {
        try {
            $indexHost = $this->getIndexHost($collection);
            if (!$indexHost) {
                return false;
            }

            $vectors = array_map(function ($vector) {
                return [
                    'id' => (string) $vector['id'],
                    'values' => $vector['vector'],
                    'metadata' => $vector['metadata'] ?? [],
                ];
            }, $vectors);

            $response = $this->client->post("{$indexHost}/vectors/upsert", [
                'json' => [
                    'vectors' => $vectors,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Pinecone upsert failed', [
                'index' => $collection,
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
            $indexHost = $this->getIndexHost($collection);
            if (!$indexHost) {
                return [];
            }

            $body = [
                'vector' => $vector,
                'topK' => $limit,
                'includeMetadata' => true,
                'includeValues' => false,
            ];

            if (!empty($filters)) {
                $body['filter'] = $filters;
            }

            $response = $this->client->post("{$indexHost}/query", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $results = array_map(function ($match) {
                return [
                    'id' => $match['id'],
                    'score' => $match['score'],
                    'metadata' => $match['metadata'] ?? [],
                ];
            }, $data['matches'] ?? []);

            // Apply threshold filtering
            if ($threshold > 0) {
                $results = array_filter($results, function ($result) use ($threshold) {
                    return $result['score'] >= $threshold;
                });
            }

            return array_values($results);
        } catch (GuzzleException $e) {
            Log::error('Pinecone search failed', [
                'index' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function delete(string $collection, array $ids): bool
    {
        try {
            $indexHost = $this->getIndexHost($collection);
            if (!$indexHost) {
                return false;
            }

            $response = $this->client->post("{$indexHost}/vectors/delete", [
                'json' => [
                    'ids' => array_map('strval', $ids),
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Pinecone delete failed', [
                'index' => $collection,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getCollectionInfo(string $collection): array
    {
        try {
            $response = $this->client->get("https://controller.{$this->environment}.pinecone.io/databases/{$collection}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            return $data['database'] ?? [];
        } catch (GuzzleException $e) {
            Log::error('Pinecone get index info failed', [
                'index' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function get(string $collection, string $id): ?array
    {
        try {
            $indexHost = $this->getIndexHost($collection);
            if (!$indexHost) {
                return null;
            }

            $response = $this->client->get("{$indexHost}/vectors/fetch", [
                'query' => ['ids' => $id],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $vector = $data['vectors'][$id] ?? null;

            if (!$vector) {
                return null;
            }

            return [
                'id' => $id,
                'vector' => $vector['values'] ?? [],
                'metadata' => $vector['metadata'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return null;
        }
    }

    public function updateMetadata(string $collection, string $id, array $metadata): bool
    {
        try {
            $indexHost = $this->getIndexHost($collection);
            if (!$indexHost) {
                return false;
            }

            $response = $this->client->post("{$indexHost}/vectors/update", [
                'json' => [
                    'id' => (string) $id,
                    'setMetadata' => $metadata,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Pinecone update metadata failed', [
                'index' => $collection,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function count(string $collection, array $filters = []): int
    {
        try {
            $indexHost = $this->getIndexHost($collection);
            if (!$indexHost) {
                return 0;
            }

            $response = $this->client->get("{$indexHost}/describe_index_stats");
            $data = json_decode($response->getBody()->getContents(), true);
            
            return $data['totalVectorCount'] ?? 0;
        } catch (GuzzleException $e) {
            Log::error('Pinecone count failed', [
                'index' => $collection,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function scroll(string $collection, int $limit = 100, ?string $offset = null): array
    {
        // Pinecone doesn't have native scroll/pagination
        // This is a simplified implementation using list operation
        Log::warning('Pinecone scroll operation is limited - consider using search with filters instead');
        
        return [
            'points' => [],
            'next_offset' => null,
        ];
    }

    /**
     * Get index host URL
     */
    protected function getIndexHost(string $indexName): ?string
    {
        if ($this->indexHost) {
            return $this->indexHost;
        }

        try {
            $info = $this->getCollectionInfo($indexName);
            $status = $info['status'] ?? null;
            
            if ($status && isset($status['host'])) {
                $this->indexHost = "https://{$status['host']}";
                return $this->indexHost;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get Pinecone index host', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
