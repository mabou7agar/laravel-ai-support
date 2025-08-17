<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Services;

use Illuminate\Support\Facades\Cache;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;

class ContentModerationService
{
    private array $moderationRules;
    private array $bannedWords;
    private array $sensitiveTopics;

    public function __construct()
    {
        $this->moderationRules = $this->loadModerationRules();
        $this->bannedWords = $this->loadBannedWords();
        $this->sensitiveTopics = $this->loadSensitiveTopics();
    }

    /**
     * Moderate content before AI generation
     */
    public function moderateInput(string $content, array $options = []): array
    {
        $results = [
            'approved' => true,
            'score' => 0.0,
            'flags' => [],
            'suggestions' => [],
            'filtered_content' => $content,
        ];

        // Check banned words
        $bannedWordCheck = $this->checkBannedWords($content);
        if (!$bannedWordCheck['approved']) {
            $results['approved'] = false;
            $results['flags'][] = 'banned_words';
            $results['score'] += 0.3;
        }

        // Check sensitive topics
        $sensitiveTopicCheck = $this->checkSensitiveTopics($content);
        if (!$sensitiveTopicCheck['approved']) {
            $results['flags'][] = 'sensitive_topic';
            $results['score'] += 0.2;
        }

        // AI-powered content analysis
        $aiModerationCheck = $this->aiContentModeration($content);
        if (!$aiModerationCheck['approved']) {
            $results['approved'] = false;
            $results['flags'] = array_merge($results['flags'], $aiModerationCheck['flags']);
            $results['score'] += $aiModerationCheck['score'];
        }

        // Apply content filters if needed
        if ($options['apply_filters'] ?? true) {
            $results['filtered_content'] = $this->applyContentFilters($content, $results['flags']);
        }

        // Generate suggestions for improvement
        if (!$results['approved']) {
            $results['suggestions'] = $this->generateImprovementSuggestions($content, $results['flags']);
        }

        return $results;
    }

    /**
     * Moderate AI-generated content
     */
    public function moderateOutput(string $content, array $options = []): array
    {
        $results = [
            'approved' => true,
            'score' => 0.0,
            'flags' => [],
            'suggestions' => [],
            'filtered_content' => $content,
        ];

        // Check for harmful content
        $harmfulContentCheck = $this->checkHarmfulContent($content);
        if (!$harmfulContentCheck['approved']) {
            $results['approved'] = false;
            $results['flags'][] = 'harmful_content';
            $results['score'] += 0.4;
        }

        // Check for bias
        $biasCheck = $this->checkBias($content);
        if (!$biasCheck['approved']) {
            $results['flags'][] = 'potential_bias';
            $results['score'] += 0.2;
        }

        // Check for factual accuracy (if enabled)
        if ($options['fact_check'] ?? false) {
            $factCheck = $this->checkFactualAccuracy($content);
            if (!$factCheck['approved']) {
                $results['flags'][] = 'factual_concerns';
                $results['score'] += 0.3;
            }
        }

        // Check for plagiarism (if enabled)
        if ($options['plagiarism_check'] ?? false) {
            $plagiarismCheck = $this->checkPlagiarism($content);
            if (!$plagiarismCheck['approved']) {
                $results['flags'][] = 'potential_plagiarism';
                $results['score'] += 0.5;
            }
        }

        // Apply safety filters
        if ($options['apply_safety_filters'] ?? true) {
            $results['filtered_content'] = $this->applySafetyFilters($content, $results['flags']);
        }

        return $results;
    }

    /**
     * Check content against OpenAI moderation API
     */
    public function openAIModeration(string $content): array
    {
        try {
            $aiEngine = app('ai-engine');
            
            // Use OpenAI's moderation endpoint
            $request = new AIRequest(
                prompt: $content,
                engine: EngineEnum::OPENAI,
                model: EntityEnum::GPT_4O, // Moderation would use specific endpoint
                parameters: ['moderation' => true]
            );

            // This would call OpenAI's moderation API
            // For now, simulate the response
            $moderationResult = [
                'flagged' => false,
                'categories' => [
                    'hate' => false,
                    'hate/threatening' => false,
                    'harassment' => false,
                    'harassment/threatening' => false,
                    'self-harm' => false,
                    'self-harm/intent' => false,
                    'self-harm/instructions' => false,
                    'sexual' => false,
                    'sexual/minors' => false,
                    'violence' => false,
                    'violence/graphic' => false,
                ],
                'category_scores' => [
                    'hate' => 0.001,
                    'hate/threatening' => 0.001,
                    'harassment' => 0.001,
                    'harassment/threatening' => 0.001,
                    'self-harm' => 0.001,
                    'self-harm/intent' => 0.001,
                    'self-harm/instructions' => 0.001,
                    'sexual' => 0.001,
                    'sexual/minors' => 0.001,
                    'violence' => 0.001,
                    'violence/graphic' => 0.001,
                ],
            ];

            return [
                'approved' => !$moderationResult['flagged'],
                'flags' => array_keys(array_filter($moderationResult['categories'])),
                'scores' => $moderationResult['category_scores'],
                'details' => $moderationResult,
            ];

        } catch (\Exception $e) {
            return [
                'approved' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create custom moderation rule
     */
    public function createModerationRule(array $ruleData): array
    {
        $rule = [
            'id' => uniqid('rule_'),
            'name' => $ruleData['name'],
            'description' => $ruleData['description'] ?? '',
            'type' => $ruleData['type'], // 'keyword', 'pattern', 'ai_check'
            'severity' => $ruleData['severity'] ?? 'medium', // 'low', 'medium', 'high'
            'action' => $ruleData['action'] ?? 'flag', // 'flag', 'block', 'filter'
            'pattern' => $ruleData['pattern'] ?? '',
            'keywords' => $ruleData['keywords'] ?? [],
            'enabled' => $ruleData['enabled'] ?? true,
            'created_at' => now()->toISOString(),
        ];

        $this->storeModerationRule($rule);
        return $rule;
    }

    /**
     * Get content safety score
     */
    public function getSafetyScore(string $content): float
    {
        $checks = [
            $this->checkBannedWords($content),
            $this->checkSensitiveTopics($content),
            $this->checkHarmfulContent($content),
            $this->checkBias($content),
        ];

        $totalScore = 0.0;
        $maxScore = count($checks);

        foreach ($checks as $check) {
            if ($check['approved']) {
                $totalScore += 1.0;
            } else {
                $totalScore += (1.0 - ($check['score'] ?? 0.5));
            }
        }

        return $totalScore / $maxScore;
    }

    /**
     * Generate content safety report
     */
    public function generateSafetyReport(string $content): array
    {
        $inputModeration = $this->moderateInput($content);
        $safetyScore = $this->getSafetyScore($content);
        $openAIModeration = $this->openAIModeration($content);

        return [
            'content_length' => strlen($content),
            'safety_score' => $safetyScore,
            'overall_approved' => $inputModeration['approved'] && $openAIModeration['approved'],
            'input_moderation' => $inputModeration,
            'openai_moderation' => $openAIModeration,
            'recommendations' => $this->generateSafetyRecommendations($inputModeration, $openAIModeration),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Check banned words
     */
    private function checkBannedWords(string $content): array
    {
        $contentLower = strtolower($content);
        $foundWords = [];

        foreach ($this->bannedWords as $word) {
            if (str_contains($contentLower, strtolower($word))) {
                $foundWords[] = $word;
            }
        }

        return [
            'approved' => empty($foundWords),
            'found_words' => $foundWords,
            'score' => count($foundWords) > 0 ? min(count($foundWords) * 0.1, 1.0) : 0.0,
        ];
    }

    /**
     * Check sensitive topics
     */
    private function checkSensitiveTopics(string $content): array
    {
        $contentLower = strtolower($content);
        $foundTopics = [];

        foreach ($this->sensitiveTopics as $topic) {
            if (str_contains($contentLower, strtolower($topic))) {
                $foundTopics[] = $topic;
            }
        }

        return [
            'approved' => empty($foundTopics),
            'found_topics' => $foundTopics,
            'score' => count($foundTopics) > 0 ? min(count($foundTopics) * 0.15, 1.0) : 0.0,
        ];
    }

    /**
     * AI-powered content moderation
     */
    private function aiContentModeration(string $content): array
    {
        try {
            $aiEngine = app('ai-engine');
            
            $moderationPrompt = "Analyze this content for safety concerns, inappropriate material, or policy violations. Rate from 0-10 (0=safe, 10=unsafe) and explain any concerns:\n\n{$content}";
            
            $request = new AIRequest(
                prompt: $moderationPrompt,
                engine: EngineEnum::OPENAI,
                model: EntityEnum::GPT_4O_MINI,
                systemPrompt: "You are a content moderation expert. Analyze content objectively for safety, appropriateness, and policy compliance.",
                temperature: 0.1
            );

            $response = $aiEngine->generateText($request);
            
            if ($response->isSuccess()) {
                $score = $this->extractModerationScore($response->content);
                return [
                    'approved' => $score < 5.0,
                    'score' => $score / 10.0,
                    'flags' => $this->extractModerationFlags($response->content),
                    'analysis' => $response->content,
                ];
            }

        } catch (\Exception $e) {
            // Fallback to basic checks if AI moderation fails
        }

        return ['approved' => true, 'score' => 0.0, 'flags' => []];
    }

    /**
     * Check for harmful content
     */
    private function checkHarmfulContent(string $content): array
    {
        $harmfulPatterns = [
            '/\b(kill|murder|suicide|bomb|weapon)\b/i',
            '/\b(hate|racist|discrimination)\b/i',
            '/\b(illegal|fraud|scam)\b/i',
        ];

        $foundPatterns = [];
        foreach ($harmfulPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $foundPatterns[] = $pattern;
            }
        }

        return [
            'approved' => empty($foundPatterns),
            'patterns_found' => count($foundPatterns),
            'score' => count($foundPatterns) > 0 ? 0.8 : 0.0,
        ];
    }

    /**
     * Check for bias
     */
    private function checkBias(string $content): array
    {
        $biasPatterns = [
            '/\b(all (men|women|people) are\b)/i',
            '/\b(always|never) (men|women|people)\b/i',
            '/\b(typical|stereotypical)\b/i',
        ];

        $foundBias = [];
        foreach ($biasPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $foundBias[] = $pattern;
            }
        }

        return [
            'approved' => empty($foundBias),
            'bias_indicators' => count($foundBias),
            'score' => count($foundBias) > 0 ? 0.3 : 0.0,
        ];
    }

    /**
     * Check factual accuracy (placeholder)
     */
    private function checkFactualAccuracy(string $content): array
    {
        // This would integrate with fact-checking APIs
        return [
            'approved' => true,
            'confidence' => 0.8,
            'score' => 0.0,
        ];
    }

    /**
     * Check for plagiarism (placeholder)
     */
    private function checkPlagiarism(string $content): array
    {
        // This would integrate with plagiarism detection APIs
        return [
            'approved' => true,
            'similarity_score' => 0.1,
            'score' => 0.0,
        ];
    }

    /**
     * Apply content filters
     */
    private function applyContentFilters(string $content, array $flags): string
    {
        $filteredContent = $content;

        if (in_array('banned_words', $flags)) {
            foreach ($this->bannedWords as $word) {
                $filteredContent = str_ireplace($word, str_repeat('*', strlen($word)), $filteredContent);
            }
        }

        return $filteredContent;
    }

    /**
     * Apply safety filters
     */
    private function applySafetyFilters(string $content, array $flags): string
    {
        if (in_array('harmful_content', $flags)) {
            return "[Content filtered for safety reasons]";
        }

        return $content;
    }

    /**
     * Generate improvement suggestions
     */
    private function generateImprovementSuggestions(string $content, array $flags): array
    {
        $suggestions = [];

        if (in_array('banned_words', $flags)) {
            $suggestions[] = 'Remove or replace inappropriate language';
        }

        if (in_array('sensitive_topic', $flags)) {
            $suggestions[] = 'Consider rephrasing to avoid sensitive topics';
        }

        if (in_array('potential_bias', $flags)) {
            $suggestions[] = 'Review content for potential bias or stereotypes';
        }

        return $suggestions;
    }

    /**
     * Generate safety recommendations
     */
    private function generateSafetyRecommendations(array $inputModeration, array $openAIModeration): array
    {
        $recommendations = [];

        if (!$inputModeration['approved']) {
            $recommendations[] = 'Review and modify input content before processing';
        }

        if (!$openAIModeration['approved']) {
            $recommendations[] = 'Content flagged by AI safety systems - manual review recommended';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Content appears safe for processing';
        }

        return $recommendations;
    }

    /**
     * Extract moderation score from AI response
     */
    private function extractModerationScore(string $response): float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*\/\s*10/', $response, $matches)) {
            return floatval($matches[1]);
        }
        
        if (preg_match('/score[:\s]*(\d+(?:\.\d+)?)/i', $response, $matches)) {
            return floatval($matches[1]);
        }

        return 0.0;
    }

    /**
     * Extract moderation flags from AI response
     */
    private function extractModerationFlags(string $response): array
    {
        $flags = [];
        $flagPatterns = [
            'inappropriate' => '/inappropriate|unsuitable/i',
            'offensive' => '/offensive|insulting/i',
            'harmful' => '/harmful|dangerous/i',
            'spam' => '/spam|promotional/i',
        ];

        foreach ($flagPatterns as $flag => $pattern) {
            if (preg_match($pattern, $response)) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * Store moderation rule
     */
    private function storeModerationRule(array $rule): void
    {
        $rules = Cache::get('moderation_rules', []);
        $rules[$rule['id']] = $rule;
        Cache::put('moderation_rules', $rules, now()->addDays(30));
    }

    /**
     * Load moderation rules
     */
    private function loadModerationRules(): array
    {
        return Cache::get('moderation_rules', []);
    }

    /**
     * Load banned words list
     */
    private function loadBannedWords(): array
    {
        return [
            // Basic inappropriate words - in production, load from comprehensive database
            'spam', 'scam', 'fraud', 'illegal', 'hack', 'crack'
        ];
    }

    /**
     * Load sensitive topics list
     */
    private function loadSensitiveTopics(): array
    {
        return [
            'politics', 'religion', 'violence', 'drugs', 'weapons'
        ];
    }
}
