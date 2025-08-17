<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Serper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class SerperEngineDriver extends BaseEngineDriver
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
        return $this->searchWeb($request);
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        throw new \InvalidArgumentException('Streaming not supported by Serper');
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
        return EngineEnum::SERPER;
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
                prompt: 'test search',
                engine: EngineEnum::SERPER,
                model: EntityEnum::SERPER_SEARCH
            );
            
            $response = $this->searchWeb($testRequest);
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
            'Text generation not supported by Serper',
            $request->engine,
            $request->model
        );
    }

    /**
     * Perform web search
     */
    public function webSearch(AIRequest $request): AIResponse
    {
        try {
            $query = $request->prompt;
            $searchType = $request->parameters['search_type'] ?? 'search';
            $location = $request->parameters['location'] ?? 'us';
            $language = $request->parameters['language'] ?? 'en';
            $num = $request->parameters['num'] ?? 10;
            $page = $request->parameters['page'] ?? 1;

            $payload = [
                'q' => $query,
                'gl' => $location,
                'hl' => $language,
                'num' => $num,
                'page' => $page,
            ];

            // Add specific parameters based on search type
            if ($searchType === 'images') {
                $payload['type'] = 'images';
                $payload['safe'] = $request->parameters['safe'] ?? 'active';
            } elseif ($searchType === 'news') {
                $payload['type'] = 'news';
                $payload['tbs'] = $request->parameters['time_filter'] ?? 'qdr:d'; // Last day
            } elseif ($searchType === 'videos') {
                $payload['type'] = 'videos';
            } elseif ($searchType === 'shopping') {
                $payload['type'] = 'shopping';
            }

            $response = $this->httpClient->post('/search', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $results = $this->formatSearchResults($data, $searchType);
            $searchInfo = $data['searchParameters'] ?? [];

            return AIResponse::success(
                json_encode($results),
                $request->engine,
                $request->model
            )->withDetailedUsage([
                'search_query' => $query,
                'search_type' => $searchType,
                'results_count' => count($results),
                'total_results' => $data['searchInformation']['totalResults'] ?? 0,
                'search_time' => $data['searchInformation']['searchTime'] ?? 0,
                'location' => $location,
                'language' => $language,
                'search_info' => $searchInfo,
            ]);

        } catch (RequestException $e) {
            return AIResponse::error(
                'Serper API error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        } catch (\Exception $e) {
            return AIResponse::error(
                'Unexpected error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Search for images
     */
    public function searchImages(AIRequest $request): AIResponse
    {
        $request->parameters['search_type'] = 'images';
        return $this->webSearch($request);
    }

    /**
     * Search for news
     */
    public function searchNews(AIRequest $request): AIResponse
    {
        $request->parameters['search_type'] = 'news';
        return $this->webSearch($request);
    }

    /**
     * Search for videos
     */
    public function searchVideos(AIRequest $request): AIResponse
    {
        $request->parameters['search_type'] = 'videos';
        return $this->webSearch($request);
    }

    /**
     * Search for shopping results
     */
    public function searchShopping(AIRequest $request): AIResponse
    {
        $request->parameters['search_type'] = 'shopping';
        return $this->webSearch($request);
    }

    /**
     * Get search suggestions
     */
    public function getSearchSuggestions(AIRequest $request): AIResponse
    {
        try {
            $query = $request->prompt;
            
            $response = $this->httpClient->get('/autocomplete', [
                'query' => ['q' => $query],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $suggestions = $data['suggestions'] ?? [];

            return AIResponse::success(
                json_encode($suggestions),
                $request->engine,
                $request->model
            )->withDetailedUsage([
                'query' => $query,
                'suggestions_count' => count($suggestions),
                'suggestions' => $suggestions,
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Serper suggestions error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Generate images (not supported)
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Image generation not supported by Serper',
            $request->engine,
            $request->model
        );
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        return [
            ['id' => 'serper-search', 'name' => 'Serper Web Search', 'type' => 'search'],
            ['id' => 'serper-images', 'name' => 'Serper Image Search', 'type' => 'image_search'],
            ['id' => 'serper-news', 'name' => 'Serper News Search', 'type' => 'news_search'],
            ['id' => 'serper-videos', 'name' => 'Serper Video Search', 'type' => 'video_search'],
            ['id' => 'serper-shopping', 'name' => 'Serper Shopping Search', 'type' => 'shopping_search'],
        ];
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['web_search', 'image_search', 'news_search', 'video_search', 'shopping_search', 'autocomplete'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::SERPER;
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::SERPER_SEARCH;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('Serper API key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-API-KEY' => $this->getApiKey(),
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Format search results based on search type
     */
    private function formatSearchResults(array $data, string $searchType): array
    {
        return match ($searchType) {
            'images' => $this->formatImageResults($data),
            'news' => $this->formatNewsResults($data),
            'videos' => $this->formatVideoResults($data),
            'shopping' => $this->formatShoppingResults($data),
            default => $this->formatWebResults($data),
        };
    }

    /**
     * Format web search results
     */
    private function formatWebResults(array $data): array
    {
        $results = [];
        
        foreach ($data['organic'] ?? [] as $result) {
            $results[] = [
                'title' => $result['title'] ?? '',
                'link' => $result['link'] ?? '',
                'snippet' => $result['snippet'] ?? '',
                'position' => $result['position'] ?? 0,
                'date' => $result['date'] ?? null,
                'sitelinks' => $result['sitelinks'] ?? [],
            ];
        }

        return $results;
    }

    /**
     * Format image search results
     */
    private function formatImageResults(array $data): array
    {
        $results = [];
        
        foreach ($data['images'] ?? [] as $result) {
            $results[] = [
                'title' => $result['title'] ?? '',
                'imageUrl' => $result['imageUrl'] ?? '',
                'imageWidth' => $result['imageWidth'] ?? 0,
                'imageHeight' => $result['imageHeight'] ?? 0,
                'thumbnailUrl' => $result['thumbnailUrl'] ?? '',
                'source' => $result['source'] ?? '',
                'link' => $result['link'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * Format news search results
     */
    private function formatNewsResults(array $data): array
    {
        $results = [];
        
        foreach ($data['news'] ?? [] as $result) {
            $results[] = [
                'title' => $result['title'] ?? '',
                'link' => $result['link'] ?? '',
                'snippet' => $result['snippet'] ?? '',
                'date' => $result['date'] ?? '',
                'source' => $result['source'] ?? '',
                'imageUrl' => $result['imageUrl'] ?? '',
                'position' => $result['position'] ?? 0,
            ];
        }

        return $results;
    }

    /**
     * Format video search results
     */
    private function formatVideoResults(array $data): array
    {
        $results = [];
        
        foreach ($data['videos'] ?? [] as $result) {
            $results[] = [
                'title' => $result['title'] ?? '',
                'link' => $result['link'] ?? '',
                'snippet' => $result['snippet'] ?? '',
                'imageUrl' => $result['imageUrl'] ?? '',
                'duration' => $result['duration'] ?? '',
                'source' => $result['source'] ?? '',
                'channel' => $result['channel'] ?? '',
                'date' => $result['date'] ?? '',
                'position' => $result['position'] ?? 0,
            ];
        }

        return $results;
    }

    /**
     * Format shopping search results
     */
    private function formatShoppingResults(array $data): array
    {
        $results = [];
        
        foreach ($data['shopping'] ?? [] as $result) {
            $results[] = [
                'title' => $result['title'] ?? '',
                'link' => $result['link'] ?? '',
                'price' => $result['price'] ?? '',
                'source' => $result['source'] ?? '',
                'imageUrl' => $result['imageUrl'] ?? '',
                'rating' => $result['rating'] ?? 0,
                'ratingCount' => $result['ratingCount'] ?? 0,
                'delivery' => $result['delivery'] ?? '',
                'position' => $result['position'] ?? 0,
            ];
        }

        return $results;
    }
}
