<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Drivers\Perplexity;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use MagicAI\LaravelAIEngine\Drivers\BaseEngineDriver;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;

class PerplexityEngineDriver extends BaseEngineDriver
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
        return $this->generateText($request);
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        return $this->generateTextStream($request);
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($this->getApiKey())) {
            return false;
        }
        
        if (!$this->supports($request->model->getContentType())) {
            return false;
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::PERPLEXITY;
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
                prompt: 'Hello',
                engine: EngineEnum::PERPLEXITY,
                model: EntityEnum::PERPLEXITY_LLAMA_3_1_SONAR_LARGE_128K_ONLINE
            );
            
            $response = $this->generateText($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate text content with real-time web search
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $messages = $this->buildMessages($request);
            
            $payload = [
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens ?? 4096,
                'temperature' => $request->temperature ?? 0.2,
                'top_p' => $request->parameters['top_p'] ?? 0.9,
                'search_domain_filter' => $request->parameters['search_domain_filter'] ?? [],
                'return_citations' => $request->parameters['return_citations'] ?? true,
                'search_recency_filter' => $request->parameters['search_recency_filter'] ?? 'month',
                'top_k' => $request->parameters['top_k'] ?? 0,
                'stream' => false,
                'presence_penalty' => $request->parameters['presence_penalty'] ?? 0,
                'frequency_penalty' => $request->parameters['frequency_penalty'] ?? 1,
            ];

            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $content = $data['choices'][0]['message']['content'] ?? '';
            $tokensUsed = $data['usage']['total_tokens'] ?? $this->calculateTokensUsed($content);
            $citations = $data['citations'] ?? [];

            return AIResponse::success(
                $content,
                $request->engine,
                $request->model
            )->withUsage(
                tokensUsed: $tokensUsed,
                creditsUsed: $tokensUsed * $request->model->creditIndex()
            )->withRequestId($data['id'] ?? null)
             ->withFinishReason($data['choices'][0]['finish_reason'] ?? null)
             ->withDetailedUsage([
                 'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                 'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                 'total_tokens' => $tokensUsed,
                 'citations' => $citations,
                 'sources_count' => count($citations),
                 'search_performed' => true,
             ]);

        } catch (RequestException $e) {
            return AIResponse::error(
                'Perplexity API error: ' . $e->getMessage(),
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
     * Generate streaming text content with real-time search
     */
    public function generateTextStream(AIRequest $request): \Generator
    {
        try {
            $messages = $this->buildMessages($request);
            
            $payload = [
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens ?? 4096,
                'temperature' => $request->temperature ?? 0.2,
                'stream' => true,
                'return_citations' => true,
            ];

            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            while (!$stream->eof()) {
                $line = trim($stream->read(1024));
                if (strpos($line, 'data: ') === 0) {
                    $jsonData = substr($line, 6);
                    if ($jsonData === '[DONE]') {
                        break;
                    }
                    
                    $data = json_decode($jsonData, true);
                    if (isset($data['choices'][0]['delta']['content'])) {
                        yield $data['choices'][0]['delta']['content'];
                    }
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('Perplexity streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Perform web search and research
     */
    public function webSearch(AIRequest $request): AIResponse
    {
        try {
            $searchQuery = $request->prompt;
            $domainFilter = $request->parameters['domain_filter'] ?? [];
            $recencyFilter = $request->parameters['recency_filter'] ?? 'month';
            $maxResults = $request->parameters['max_results'] ?? 10;

            // Use Perplexity's search-focused model
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are a research assistant. Provide comprehensive, well-sourced information based on current web search results. Include citations and links where possible.'
                ],
                [
                    'role' => 'user',
                    'content' => "Research and provide detailed information about: {$searchQuery}"
                ]
            ];

            $payload = [
                'model' => 'llama-3.1-sonar-large-128k-online',
                'messages' => $messages,
                'max_tokens' => 4096,
                'temperature' => 0.1,
                'search_domain_filter' => $domainFilter,
                'search_recency_filter' => $recencyFilter,
                'return_citations' => true,
                'return_images' => $request->parameters['return_images'] ?? false,
            ];

            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $content = $data['choices'][0]['message']['content'] ?? '';
            $citations = $data['citations'] ?? [];
            $images = $data['images'] ?? [];

            return AIResponse::success(
                $content,
                $request->engine,
                $request->model
            )->withDetailedUsage([
                'search_query' => $searchQuery,
                'citations' => $citations,
                'sources_count' => count($citations),
                'images' => $images,
                'domain_filter' => $domainFilter,
                'recency_filter' => $recencyFilter,
                'research_type' => 'web_search',
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Perplexity web search error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Generate academic research
     */
    public function academicResearch(AIRequest $request): AIResponse
    {
        try {
            $researchTopic = $request->prompt;
            
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are an academic research assistant. Provide scholarly, well-researched information with proper citations. Focus on peer-reviewed sources, academic papers, and authoritative publications.'
                ],
                [
                    'role' => 'user',
                    'content' => "Conduct academic research on: {$researchTopic}. Include recent studies, key findings, and scholarly perspectives."
                ]
            ];

            $payload = [
                'model' => 'llama-3.1-sonar-large-128k-online',
                'messages' => $messages,
                'max_tokens' => 4096,
                'temperature' => 0.1,
                'search_domain_filter' => ['scholar.google.com', 'pubmed.ncbi.nlm.nih.gov', 'arxiv.org', 'jstor.org'],
                'return_citations' => true,
            ];

            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $content = $data['choices'][0]['message']['content'] ?? '';
            $citations = $data['citations'] ?? [];

            return AIResponse::success(
                $content,
                $request->engine,
                $request->model
            )->withDetailedUsage([
                'research_topic' => $researchTopic,
                'citations' => $citations,
                'academic_sources' => count($citations),
                'research_type' => 'academic',
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Perplexity academic research error: ' . $e->getMessage(),
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
            'Image generation not supported by Perplexity',
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
            ['id' => 'llama-3.1-sonar-small-128k-online', 'name' => 'Llama 3.1 Sonar Small Online', 'type' => 'search'],
            ['id' => 'llama-3.1-sonar-large-128k-online', 'name' => 'Llama 3.1 Sonar Large Online', 'type' => 'search'],
            ['id' => 'llama-3.1-sonar-huge-128k-online', 'name' => 'Llama 3.1 Sonar Huge Online', 'type' => 'search'],
            ['id' => 'llama-3.1-sonar-small-128k-chat', 'name' => 'Llama 3.1 Sonar Small Chat', 'type' => 'chat'],
            ['id' => 'llama-3.1-sonar-large-128k-chat', 'name' => 'Llama 3.1 Sonar Large Chat', 'type' => 'chat'],
        ];
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'web_search', 'research', 'citations', 'streaming'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::PERPLEXITY;
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::LLAMA_3_1_SONAR_LARGE_ONLINE;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('Perplexity API key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Build messages array for chat completion
     */
    private function buildMessages(AIRequest $request): array
    {
        $messages = [];

        // Add system message if provided
        if ($request->systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemPrompt,
            ];
        }

        // Add conversation history if provided
        if (!empty($request->messages)) {
            $messages = array_merge($messages, $request->messages);
        }

        // Add the main prompt
        $messages[] = [
            'role' => 'user',
            'content' => $request->prompt,
        ];

        return $messages;
    }
}
