<?php

namespace LaravelAIEngine\Drivers\PlagiarismCheck;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PlagiarismCheckEngineDriver implements EngineDriverInterface
{
    private Client $client;
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('ai-engine.engines.plagiarism_check.api_key');
        $this->baseUrl = config('ai-engine.engines.plagiarism_check.base_url', 'https://api.plagiarismcheck.org');

        if (empty($this->apiKey)) {
            throw new AIEngineException('Plagiarism Check API key is required');
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('ai-engine.engines.plagiarism_check.timeout', 60),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        try {
            switch ($request->entity) {
                case EntityEnum::PLAGIARISM_BASIC:
                    return $this->checkPlagiarismBasic($request);
                case EntityEnum::PLAGIARISM_ADVANCED:
                    return $this->checkPlagiarismAdvanced($request);
                case EntityEnum::PLAGIARISM_ACADEMIC:
                    return $this->checkPlagiarismAcademic($request);
                default:
                    throw new AIEngineException("Entity {$request->entity->value} not supported by Plagiarism Check driver");
            }
        } catch (RequestException $e) {
            throw new AIEngineException('Plagiarism Check API request failed: ' . $e->getMessage());
        }
    }

    private function checkPlagiarismBasic(AIRequest $request): AIResponse
    {
        $payload = [
            'text' => $request->prompt,
            'language' => $request->parameters['language'] ?? 'en',
            'check_type' => 'basic',
            'include_citations' => $request->parameters['include_citations'] ?? false,
            'exclude_quotes' => $request->parameters['exclude_quotes'] ?? true,
        ];

        $response = $this->client->post('/v1/check', [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $this->formatPlagiarismResponse($data, $request);
    }

    private function checkPlagiarismAdvanced(AIRequest $request): AIResponse
    {
        $payload = [
            'text' => $request->prompt,
            'language' => $request->parameters['language'] ?? 'en',
            'check_type' => 'advanced',
            'include_citations' => $request->parameters['include_citations'] ?? true,
            'exclude_quotes' => $request->parameters['exclude_quotes'] ?? true,
            'check_paraphrasing' => $request->parameters['check_paraphrasing'] ?? true,
            'similarity_threshold' => $request->parameters['similarity_threshold'] ?? 15,
            'sources' => $request->parameters['sources'] ?? ['web', 'academic', 'publications'],
        ];

        $response = $this->client->post('/v1/check/advanced', [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $this->formatPlagiarismResponse($data, $request);
    }

    private function checkPlagiarismAcademic(AIRequest $request): AIResponse
    {
        $payload = [
            'text' => $request->prompt,
            'language' => $request->parameters['language'] ?? 'en',
            'check_type' => 'academic',
            'include_citations' => $request->parameters['include_citations'] ?? true,
            'exclude_quotes' => $request->parameters['exclude_quotes'] ?? true,
            'check_paraphrasing' => $request->parameters['check_paraphrasing'] ?? true,
            'check_ai_content' => $request->parameters['check_ai_content'] ?? true,
            'similarity_threshold' => $request->parameters['similarity_threshold'] ?? 10,
            'sources' => $request->parameters['sources'] ?? ['academic', 'publications', 'dissertations', 'web'],
            'citation_style' => $request->parameters['citation_style'] ?? 'apa',
        ];

        $response = $this->client->post('/v1/check/academic', [
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $this->formatPlagiarismResponse($data, $request);
    }

    private function formatPlagiarismResponse(array $data, AIRequest $request): AIResponse
    {
        $results = [
            'overall_similarity' => $data['similarity_percentage'] ?? 0,
            'uniqueness_percentage' => 100 - ($data['similarity_percentage'] ?? 0),
            'total_sources_found' => count($data['sources'] ?? []),
            'word_count' => $data['word_count'] ?? str_word_count($request->prompt),
            'character_count' => $data['character_count'] ?? strlen($request->prompt),
            'check_id' => $data['check_id'] ?? Str::uuid(),
            'status' => $this->getStatusFromSimilarity($data['similarity_percentage'] ?? 0),
            'sources' => $this->formatSources($data['sources'] ?? []),
            'highlighted_text' => $data['highlighted_text'] ?? null,
            'ai_content_detected' => $data['ai_content_percentage'] ?? 0,
            'paraphrasing_detected' => $data['paraphrasing_percentage'] ?? 0,
            'recommendations' => $this->generateRecommendations($data),
        ];

        return new AIResponse(
            content: json_encode($results),
            usage: [
                'words_checked' => $results['word_count'],
                'sources_scanned' => $results['total_sources_found'],
                'total_cost' => $request->entity->creditIndex(),
            ],
            metadata: [
                'model' => $request->entity->value,
                'engine' => EngineEnum::PLAGIARISM_CHECK->value,
                'check_type' => $request->parameters['check_type'] ?? 'basic',
                'language' => $request->parameters['language'] ?? 'en',
                'similarity_threshold' => $request->parameters['similarity_threshold'] ?? 15,
                'plagiarism_results' => $results,
            ]
        );
    }

    private function formatSources(array $sources): array
    {
        return array_map(function ($source) {
            return [
                'title' => $source['title'] ?? 'Unknown Source',
                'url' => $source['url'] ?? null,
                'similarity_percentage' => $source['similarity'] ?? 0,
                'matched_words' => $source['matched_words'] ?? 0,
                'source_type' => $source['type'] ?? 'web',
                'publication_date' => $source['date'] ?? null,
                'author' => $source['author'] ?? null,
                'domain' => $source['domain'] ?? null,
                'snippet' => $source['snippet'] ?? null,
            ];
        }, $sources);
    }

    private function getStatusFromSimilarity(float $similarity): string
    {
        return match (true) {
            $similarity >= 50 => 'high_risk',
            $similarity >= 25 => 'medium_risk',
            $similarity >= 15 => 'low_risk',
            default => 'original',
        };
    }

    private function generateRecommendations(array $data): array
    {
        $recommendations = [];
        $similarity = $data['similarity_percentage'] ?? 0;
        $aiContent = $data['ai_content_percentage'] ?? 0;
        $paraphrasing = $data['paraphrasing_percentage'] ?? 0;

        if ($similarity > 30) {
            $recommendations[] = 'High similarity detected. Consider rewriting or properly citing sources.';
        }

        if ($similarity > 15 && $similarity <= 30) {
            $recommendations[] = 'Moderate similarity found. Review flagged sections and add proper citations.';
        }

        if ($aiContent > 50) {
            $recommendations[] = 'High AI-generated content detected. Consider adding more original analysis.';
        }

        if ($paraphrasing > 25) {
            $recommendations[] = 'Significant paraphrasing detected. Ensure proper attribution to original sources.';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Content appears to be original with low similarity to existing sources.';
        }

        return $recommendations;
    }

    public function stream(AIRequest $request): \Generator
    {
        // Plagiarism checking doesn't support streaming
        // Return the full response as a single chunk
        $response = $this->generate($request);
        yield $response->getContent();
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::PLAGIARISM_BASIC->value => [
                'name' => 'Basic Plagiarism Check',
                'description' => 'Basic similarity detection against web sources',
                'features' => ['web_sources', 'basic_similarity'],
                'max_words' => 5000,
            ],
            EntityEnum::PLAGIARISM_ADVANCED->value => [
                'name' => 'Advanced Plagiarism Check',
                'description' => 'Advanced detection with paraphrasing and multiple sources',
                'features' => ['web_sources', 'academic_sources', 'paraphrasing_detection', 'citations'],
                'max_words' => 25000,
            ],
            EntityEnum::PLAGIARISM_ACADEMIC->value => [
                'name' => 'Academic Plagiarism Check',
                'description' => 'Comprehensive academic integrity checking with AI detection',
                'features' => ['all_sources', 'ai_detection', 'paraphrasing_detection', 'citations', 'academic_formatting'],
                'max_words' => 50000,
            ],
        ];
    }

    public function validateRequest(AIRequest $request): bool
    {
        // Check if model is supported
        if (!in_array($request->entity, [
            EntityEnum::PLAGIARISM_BASIC,
            EntityEnum::PLAGIARISM_ADVANCED,
            EntityEnum::PLAGIARISM_ACADEMIC,
        ])) {
            throw new AIEngineException("Model {$request->entity->value} is not supported by Plagiarism Check driver");
        }

        // Validate text content
        if (empty($request->prompt)) {
            throw new AIEngineException('Text content is required for plagiarism checking');
        }

        // Check word count limits
        $wordCount = str_word_count($request->prompt);
        $maxWords = $this->getMaxWordsForModel($request->entity);

        if ($wordCount > $maxWords) {
            throw new AIEngineException("Text exceeds maximum word limit of {$maxWords} words for {$request->entity->value}");
        }

        return true;
    }

    private function getMaxWordsForModel(EntityEnum $entity): int
    {
        return match ($entity) {
            EntityEnum::PLAGIARISM_BASIC => 5000,
            EntityEnum::PLAGIARISM_ADVANCED => 25000,
            EntityEnum::PLAGIARISM_ACADEMIC => 50000,
            default => 5000,
        };
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::PLAGIARISM_CHECK;
    }
}
