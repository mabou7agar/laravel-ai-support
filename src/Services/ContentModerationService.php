<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Contracts\ModerationRuleInterface;
use LaravelAIEngine\Services\Moderation\Rules\RegexModerationRule;

class ContentModerationService
{
    private array $rules = [];

    public function __construct()
    {
        $this->loadRules();
    }

    /**
     * Load moderation rules from config/cache
     */
    protected function loadRules(): void
    {
        // 1. Load Banned Words
        if (config('ai-engine-moderation.features.banned_words_check', true)) {
            $this->rules[] = new RegexModerationRule(
                'banned_words',
                config('ai-engine-moderation.banned_words', []),
                config('ai-engine-moderation.scores.banned_word', 0.3),
                'banned_words'
            );
        }

        // 2. Load Sensitive Topics
        if (config('ai-engine-moderation.features.sensitive_topic_check', true)) {
            $this->rules[] = new RegexModerationRule(
                'sensitive_topics',
                config('ai-engine-moderation.sensitive_topics', []),
                config('ai-engine-moderation.scores.sensitive_topic', 0.2),
                'sensitive_topic'
            );
        }

        // 3. Load Harmful Content Patterns
        if (config('ai-engine-moderation.features.harmful_content_check', true)) {
            $patterns = config('ai-engine-moderation.patterns.harmful', []);
            $this->rules[] = new RegexModerationRule(
                'harmful_content',
                $patterns,
                config('ai-engine-moderation.scores.harmful_content', 0.4),
                'harmful_content'
            );
        }

        // 4. Load Bias Patterns
        if (config('ai-engine-moderation.features.bias_check', true)) {
            $patterns = config('ai-engine-moderation.patterns.bias', []);
            $this->rules[] = new RegexModerationRule(
                'potential_bias',
                $patterns,
                config('ai-engine-moderation.scores.bias', 0.2),
                'potential_bias'
            );
        }

        // Load custom cached rules here if needed, implementing ModerationRuleInterface
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

        // Apply all loaded rules
        foreach ($this->rules as $rule) {
            $ruleResult = $rule->check($content);
            if (!$ruleResult['approved']) {
                $results['flags'] = array_merge($results['flags'], $ruleResult['flags']);
                $results['score'] += $ruleResult['score'];

                // If it's a high severity flag (like harmful content), mark as not approved
                if (in_array('harmful_content', $ruleResult['flags']) || in_array('banned_words', $ruleResult['flags'])) {
                    $results['approved'] = false;
                }
            }
        }

        // AI-powered content analysis (if enabled)
        if (config('ai-engine-moderation.features.ai_moderation', true)) {
            $aiModerationCheck = $this->aiContentModeration($content);
            if (!$aiModerationCheck['approved']) {
                $results['approved'] = false;
                $results['flags'] = array_merge($results['flags'], $aiModerationCheck['flags']);
                $results['score'] += $aiModerationCheck['score'];
            }
        }

        // Cap score at 1.0
        $results['score'] = min($results['score'], 1.0);

        // Apply content filters if needed
        if ($options['apply_filters'] ?? true) {
            $results['filtered_content'] = $this->applyContentFilters($content, $results['flags']);
        }

        // Generate suggestions for improvement
        if (!$results['approved'] || !empty($results['flags'])) {
            $results['suggestions'] = $this->generateImprovementSuggestions($content, $results['flags']);
        }

        return $results;
    }

    /**
     * Moderate AI-generated content
     */
    public function moderateOutput(string $content, array $options = []): array
    {
        // For now using the same rules, but logic could be separated
        // Often output moderation focuses more on safety/harm execution
        return $this->moderateInput($content, $options);
    }

    /**
     * Check content against OpenAI moderation API
     */
    public function openAIModeration(string $content): array
    {
        try {
            // Keep existing simulated logic or replace with actual API call if key is present
            $aiEngine = app('ai-engine');

            // Use OpenAI's moderation endpoint
            $request = new AIRequest(
                prompt: $content,
                engine: EngineEnum::OPENAI,
                model: EntityEnum::GPT_4O, // Moderation would use specific endpoint
                parameters: ['moderation' => true]
            );

            // This serves as a placeholder for the actual API integration
            return [
                'approved' => true,
                'flags' => [],
                'scores' => [],
                'details' => [],
            ];

        } catch (\Exception $e) {
            return [
                'approved' => true,
                'error' => $e->getMessage(),
            ];
        }
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
                model: \LaravelAIEngine\Enums\EntityEnum::GPT_4_0_MINI,
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
     * Apply content filters
     */
    private function applyContentFilters(string $content, array $flags): string
    {
        $filteredContent = $content;

        // Naive masking for banned words, relying on config
        if (in_array('banned_words', $flags)) {
            $bannedWords = config('ai-engine-moderation.banned_words', []);
            foreach ($bannedWords as $word) {
                // Determine if word is in content case-insensitively
                if (stripos($content, $word) !== false) {
                    $filteredContent = str_ireplace($word, str_repeat('*', strlen($word)), $filteredContent);
                }
            }
        }

        return $filteredContent;
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

        if (in_array('harmful_content', $flags)) {
            $suggestions[] = 'Content flagged as potentially harmful';
        }

        return $suggestions;
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
}
