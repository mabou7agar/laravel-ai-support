<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Unsplash;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class UnsplashEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->httpClient = new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
            'headers' => $this->buildHeaders(),
        ]);
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
        return $this->searchImages($request);
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        throw new \InvalidArgumentException('Streaming not supported by Unsplash');
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($this->getApiKey())) {
            return false;
        }
        
        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::UNSPLASH;
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities());
    }

    /**
     * Test the engine connection
     */
    public function test(): bool
    {
        try {
            $testRequest = new AIRequest(
                prompt: 'nature',
                engine: EngineEnum::UNSPLASH,
                model: EntityEnum::UNSPLASH_SEARCH
            );
            
            $response = $this->searchImages($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate text content (not supported)
     */
    public function generateText(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Text generation not supported by Unsplash',
            $request->getEngine(),
            $request->getModel()
        );
    }

    /**
     * Search for stock photos
     */
    public function searchPhotos(AIRequest $request): AIResponse
    {
        try {
            $query = $request->getPrompt();
            $page = $request->getParameters()['page'] ?? 1;
            $perPage = $request->getParameters()['per_page'] ?? 20;
            $orderBy = $request->getParameters()['order_by'] ?? 'relevant';
            $collections = $request->getParameters()['collections'] ?? null;
            $contentFilter = $request->getParameters()['content_filter'] ?? 'low';
            $color = $request->getParameters()['color'] ?? null;
            $orientation = $request->getParameters()['orientation'] ?? null;

            $params = [
                'query' => $query,
                'page' => $page,
                'per_page' => min($perPage, 30), // Max 30 per page
                'order_by' => $orderBy,
                'content_filter' => $contentFilter,
            ];

            if ($collections) {
                $params['collections'] = $collections;
            }
            if ($color) {
                $params['color'] = $color;
            }
            if ($orientation) {
                $params['orientation'] = $orientation;
            }

            $response = $this->httpClient->get('/search/photos', [
                'query' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $photos = $this->formatPhotoResults($data['results'] ?? []);
            $totalResults = $data['total'] ?? 0;
            $totalPages = $data['total_pages'] ?? 0;

            return AIResponse::success(
                json_encode($photos),
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'search_query' => $query,
                'photos_count' => count($photos),
                'total_results' => $totalResults,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'order_by' => $orderBy,
                'content_filter' => $contentFilter,
                'color_filter' => $color,
                'orientation_filter' => $orientation,
            ]);

        } catch (RequestException $e) {
            return AIResponse::error(
                'Unsplash API error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        } catch (\Exception $e) {
            return AIResponse::error(
                'Unexpected error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    /**
     * Get random photos
     */
    public function getRandomPhotos(AIRequest $request): AIResponse
    {
        try {
            $count = $request->getParameters()['count'] ?? 10;
            $collections = $request->getParameters()['collections'] ?? null;
            $topics = $request->getParameters()['topics'] ?? null;
            $username = $request->getParameters()['username'] ?? null;
            $query = $request->getParameters()['query'] ?? null;
            $orientation = $request->getParameters()['orientation'] ?? null;
            $contentFilter = $request->getParameters()['content_filter'] ?? 'low';

            $params = [
                'count' => min($count, 30), // Max 30 photos
                'content_filter' => $contentFilter,
            ];

            if ($collections) {
                $params['collections'] = $collections;
            }
            if ($topics) {
                $params['topics'] = $topics;
            }
            if ($username) {
                $params['username'] = $username;
            }
            if ($query) {
                $params['query'] = $query;
            }
            if ($orientation) {
                $params['orientation'] = $orientation;
            }

            $response = $this->httpClient->get('/photos/random', [
                'query' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            // Handle both single photo and array of photos
            $photos = is_array($data) && isset($data[0]) ? $data : [$data];
            $formattedPhotos = $this->formatPhotoResults($photos);

            return AIResponse::success(
                json_encode($formattedPhotos),
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'photos_count' => count($formattedPhotos),
                'collections' => $collections,
                'topics' => $topics,
                'username' => $username,
                'query' => $query,
                'orientation' => $orientation,
                'content_filter' => $contentFilter,
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Unsplash random photos error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    /**
     * Get photo details by ID
     */
    public function getPhotoDetails(AIRequest $request): AIResponse
    {
        try {
            $photoId = $request->getParameters()['photo_id'] ?? $request->getPrompt();
            
            if (!$photoId) {
                throw new \InvalidArgumentException('Photo ID is required');
            }

            $response = $this->httpClient->get("/photos/{$photoId}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            $photo = $this->formatPhotoResult($data);

            return AIResponse::success(
                json_encode($photo),
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'photo_id' => $photoId,
                'photo_details' => $photo,
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Unsplash photo details error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    /**
     * Download photo (track download for Unsplash API guidelines)
     */
    public function downloadPhoto(AIRequest $request): AIResponse
    {
        try {
            $photoId = $request->getParameters()['photo_id'] ?? $request->getPrompt();
            
            if (!$photoId) {
                throw new \InvalidArgumentException('Photo ID is required');
            }

            // First, trigger download tracking
            $response = $this->httpClient->get("/photos/{$photoId}/download");
            $downloadData = json_decode($response->getBody()->getContents(), true);
            
            $downloadUrl = $downloadData['url'] ?? null;
            
            if (!$downloadUrl) {
                throw new \RuntimeException('Download URL not available');
            }

            return AIResponse::success(
                $downloadUrl,
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'photo_id' => $photoId,
                'download_url' => $downloadUrl,
                'download_tracked' => true,
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Unsplash download error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    /**
     * Generate images (not supported)
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Image generation not supported by Unsplash',
            $request->getEngine(),
            $request->getModel()
        );
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        return [
            ['id' => 'unsplash-search', 'name' => 'Unsplash Photo Search', 'type' => 'photo_search'],
            ['id' => 'unsplash-random', 'name' => 'Unsplash Random Photos', 'type' => 'random_photos'],
            ['id' => 'unsplash-collections', 'name' => 'Unsplash Collections', 'type' => 'collections'],
        ];
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['photo_search', 'random_photos', 'photo_details', 'photo_download', 'stock_photos'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return new EngineEnum(EngineEnum::UNSPLASH);
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::UNSPLASH_SEARCH;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['access_key'])) {
            throw new \InvalidArgumentException('Unsplash Access Key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Authorization' => 'Client-ID ' . $this->config['access_key'],
            'Accept' => 'application/json',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Format multiple photo results
     */
    private function formatPhotoResults(array $photos): array
    {
        return array_map([$this, 'formatPhotoResult'], $photos);
    }

    /**
     * Format single photo result
     */
    private function formatPhotoResult(array $photo): array
    {
        return [
            'id' => $photo['id'] ?? '',
            'description' => $photo['description'] ?? $photo['alt_description'] ?? '',
            'alt_description' => $photo['alt_description'] ?? '',
            'urls' => [
                'raw' => $photo['urls']['raw'] ?? '',
                'full' => $photo['urls']['full'] ?? '',
                'regular' => $photo['urls']['regular'] ?? '',
                'small' => $photo['urls']['small'] ?? '',
                'thumb' => $photo['urls']['thumb'] ?? '',
            ],
            'width' => $photo['width'] ?? 0,
            'height' => $photo['height'] ?? 0,
            'color' => $photo['color'] ?? '#000000',
            'blur_hash' => $photo['blur_hash'] ?? '',
            'downloads' => $photo['downloads'] ?? 0,
            'likes' => $photo['likes'] ?? 0,
            'user' => [
                'id' => $photo['user']['id'] ?? '',
                'username' => $photo['user']['username'] ?? '',
                'name' => $photo['user']['name'] ?? '',
                'profile_image' => $photo['user']['profile_image']['medium'] ?? '',
                'portfolio_url' => $photo['user']['portfolio_url'] ?? '',
                'bio' => $photo['user']['bio'] ?? '',
            ],
            'tags' => array_map(function ($tag) {
                return [
                    'title' => $tag['title'] ?? '',
                    'type' => $tag['type'] ?? 'landing_page',
                ];
            }, $photo['tags'] ?? []),
            'created_at' => $photo['created_at'] ?? '',
            'updated_at' => $photo['updated_at'] ?? '',
            'promoted_at' => $photo['promoted_at'] ?? null,
            'sponsorship' => $photo['sponsorship'] ?? null,
            'topic_submissions' => $photo['topic_submissions'] ?? [],
            'premium' => $photo['premium'] ?? false,
            'plus' => $photo['plus'] ?? false,
        ];
    }
}
