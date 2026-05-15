<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Pexels;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class PexelsEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);

        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
            'headers' => $this->buildHeaders(),
        ]);
    }

    /**
     * Generate content using Pexels — the prompt becomes the search query.
     */
    public function generate(AIRequest $request): AIResponse
    {
        return $this->searchImages($request);
    }

    /**
     * Streaming is not supported by the Pexels REST API.
     */
    public function stream(AIRequest $request): \Generator
    {
        throw new \InvalidArgumentException('Streaming not supported by Pexels');
    }

    /**
     * Validate the request before processing.
     */
    public function validateRequest(AIRequest $request): bool
    {
        return !empty($this->getApiKey());
    }

    /**
     * Get the engine this driver handles.
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::PEXELS);
    }

    /**
     * Check if the engine supports a specific capability.
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities());
    }

    /**
     * Test the engine connection.
     */
    public function test(): bool
    {
        try {
            $testRequest = new AIRequest(
                prompt: 'nature',
                engine: EngineEnum::from(EngineEnum::PEXELS),
                model: EntityEnum::from(EntityEnum::PEXELS_SEARCH)
            );

            $response = $this->searchImages($testRequest);
            return $response->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Text generation is not supported by Pexels.
     */
    public function generateText(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Text generation not supported by Pexels',
            $request->getEngine(),
            $request->getModel()
        );
    }

    /**
     * Search for photos — the request prompt is used as the search query.
     *
     * Optional parameters (via $request->getParameters()):
     *   - page          (int,    default 1)
     *   - per_page      (int,    default 15, max 80)
     *   - orientation   (string, 'landscape'|'portrait'|'square')
     *   - size          (string, 'large'|'medium'|'small')
     *   - color         (string, hex or named colour)
     *   - locale        (string, e.g. 'en-US')
     */
    public function searchImages(AIRequest $request): AIResponse
    {
        try {
            $query = $request->getPrompt();
            $params = $request->getParameters();

            $page = $params['page'] ?? 1;
            $perPage = min((int) ($params['per_page'] ?? 15), 80);
            $orientation = $params['orientation'] ?? null;
            $size = $params['size'] ?? null;
            $color = $params['color'] ?? null;
            $locale = $params['locale'] ?? null;

            $queryParams = [
                'query'    => $query,
                'page'     => $page,
                'per_page' => $perPage,
            ];

            if ($orientation) {
                $queryParams['orientation'] = $orientation;
            }
            if ($size) {
                $queryParams['size'] = $size;
            }
            if ($color) {
                $queryParams['color'] = $color;
            }
            if ($locale) {
                $queryParams['locale'] = $locale;
            }

            $response = $this->httpClient->get('/v1/search', [
                'query' => $queryParams,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $photos = $this->formatPhotoResults($data['photos'] ?? []);
            $totalResults = $data['total_results'] ?? 0;
            $nextPage = $data['next_page'] ?? null;
            $prevPage = $data['prev_page'] ?? null;

            return AIResponse::success(
                json_encode($photos),
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'search_query'   => $query,
                'photos_count'   => count($photos),
                'total_results'  => $totalResults,
                'current_page'   => $page,
                'per_page'       => $perPage,
                'next_page'      => $nextPage,
                'prev_page'      => $prevPage,
                'orientation'    => $orientation,
                'size_filter'    => $size,
                'color_filter'   => $color,
                'locale'         => $locale,
            ]);

        } catch (RequestException $e) {
            return AIResponse::error(
                'Pexels API error: ' . $e->getMessage(),
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
     * Search for a photo matching the prompt and return results in the
     * standard AIResponse format. Alias for searchImages() to satisfy
     * the conventional generateImage() contract expected by callers.
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        return $this->searchImages($request);
    }

    /**
     * Get curated photos (Pexels editorial picks).
     *
     * Optional parameters (via $request->getParameters()):
     *   - page     (int, default 1)
     *   - per_page (int, default 15, max 80)
     */
    public function getCuratedPhotos(AIRequest $request): AIResponse
    {
        try {
            $params = $request->getParameters();
            $page = $params['page'] ?? 1;
            $perPage = min((int) ($params['per_page'] ?? 15), 80);

            $response = $this->httpClient->get('/v1/curated', [
                'query' => [
                    'page'     => $page,
                    'per_page' => $perPage,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $photos = $this->formatPhotoResults($data['photos'] ?? []);

            return AIResponse::success(
                json_encode($photos),
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'photos_count'  => count($photos),
                'current_page'  => $page,
                'per_page'      => $perPage,
                'total_results' => $data['total_results'] ?? 0,
                'next_page'     => $data['next_page'] ?? null,
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Pexels curated photos error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    /**
     * Get a single photo by its Pexels ID.
     *
     * Pass the ID either as the prompt or via parameters['photo_id'].
     */
    public function getPhotoDetails(AIRequest $request): AIResponse
    {
        try {
            $photoId = $request->getParameters()['photo_id'] ?? $request->getPrompt();

            if (!$photoId) {
                throw new \InvalidArgumentException('Photo ID is required');
            }

            $response = $this->httpClient->get("/v1/photos/{$photoId}");
            $data = json_decode($response->getBody()->getContents(), true);
            $photo = $this->formatPhotoResult($data);

            return AIResponse::success(
                json_encode($photo),
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'photo_id'      => $photoId,
                'photo_details' => $photo,
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Pexels photo details error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
            );
        }
    }

    /**
     * Get available models for this engine.
     */
    public function getAvailableModels(): array
    {
        return [
            ['id' => EntityEnum::PEXELS_SEARCH, 'name' => 'Pexels Photo Search', 'type' => 'photo_search'],
        ];
    }

    // -------------------------------------------------------------------------
    // Protected / private helpers
    // -------------------------------------------------------------------------

    /**
     * Get supported capabilities for this engine.
     */
    protected function getSupportedCapabilities(): array
    {
        return ['photo_search', 'curated_photos', 'photo_details', 'stock_photos', 'images', 'search'];
    }

    /**
     * Get the engine enum instance.
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::PEXELS);
    }

    /**
     * Get the default model for this engine.
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::from(EntityEnum::PEXELS_SEARCH);
    }

    /**
     * Validate the engine configuration.
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('Pexels API key is required. Set PEXELS_API_KEY in your environment.');
        }
    }

    /**
     * Build the HTTP request headers required by the Pexels API.
     * Authentication uses a bare API key in the Authorization header.
     */
    protected function buildHeaders(): array
    {
        return [
            'Authorization' => $this->config['api_key'],
            'Accept'        => 'application/json',
            'User-Agent'    => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Get the Pexels API base URL.
     */
    protected function getBaseUrl(): string
    {
        return $this->config['base_url'] ?? 'https://api.pexels.com';
    }

    /**
     * Format an array of raw Pexels photo objects.
     */
    private function formatPhotoResults(array $photos): array
    {
        return array_map([$this, 'formatPhotoResult'], $photos);
    }

    /**
     * Normalise a single raw Pexels photo object into a consistent shape
     * that matches the structure used by UnsplashEngineDriver for easy
     * interchangeability.
     */
    private function formatPhotoResult(array $photo): array
    {
        $src = $photo['src'] ?? [];

        return [
            'id'          => (string) ($photo['id'] ?? ''),
            'description' => $photo['alt'] ?? '',
            'alt'         => $photo['alt'] ?? '',
            'urls'        => [
                'original'  => $src['original'] ?? '',
                'large2x'   => $src['large2x'] ?? '',
                'large'     => $src['large'] ?? '',
                'medium'    => $src['medium'] ?? '',
                'small'     => $src['small'] ?? '',
                'portrait'  => $src['portrait'] ?? '',
                'landscape' => $src['landscape'] ?? '',
                'tiny'      => $src['tiny'] ?? '',
            ],
            'width'       => $photo['width'] ?? 0,
            'height'      => $photo['height'] ?? 0,
            'avg_color'   => $photo['avg_color'] ?? '#000000',
            'photographer' => [
                'name' => $photo['photographer'] ?? '',
                'url'  => $photo['photographer_url'] ?? '',
                'id'   => (string) ($photo['photographer_id'] ?? ''),
            ],
            'pexels_url'  => $photo['url'] ?? '',
            'liked'       => $photo['liked'] ?? false,
        ];
    }
}
