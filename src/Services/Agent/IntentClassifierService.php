<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class IntentClassifierService
{
    public function __construct(
        protected array $config = []
    ) {
    }

    public function classify(string $message, UnifiedActionContext $context, bool $hasEntityListContext = false): array
    {
        return [
            'has_entity_list_context' => $hasEntityListContext,
            'is_option_selection' => $this->isOptionSelection($message, $context),
            'is_positional_reference' => $this->isPositionalReference($message),
            'is_explicit_list_request' => $this->isExplicitListRequest($message),
            'is_explicit_entity_lookup' => $this->isExplicitEntityLookupRequest($message),
            'is_follow_up_question' => $this->isLikelyFollowUpQuestion($message),
        ];
    }

    public function isLikelyFollowUpQuestion(string $message): bool
    {
        $normalized = strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        if (str_contains($message, '?')) {
            return true;
        }

        $followUpKeywords = $this->getConfig('followup_keywords', []);

        if (!empty($followUpKeywords) && $this->containsAnyWord($message, $followUpKeywords)) {
            return true;
        }

        $pronouns = $this->getConfig('followup_pronouns', []);
        return !empty($pronouns) && $this->containsAnyWord($message, $pronouns);
    }

    public function isExplicitListRequest(string $message): bool
    {
        $listVerbs = $this->getConfig('list_verbs', []);
        if (!empty($listVerbs) && $this->containsAnyWord($message, $listVerbs)) {
            return true;
        }

        $refreshWords = $this->getConfig('refresh_words', []);
        if (!empty($refreshWords) && $this->containsAnyWord($message, $refreshWords)) {
            return true;
        }

        $recordTerms = $this->getConfig('record_terms', []);
        if (empty($recordTerms)) {
            return false;
        }

        return preg_match('/\b(all|every)\s+(' . implode('|', array_map('preg_quote', $recordTerms)) . ')\b/i', $message) === 1;
    }

    public function isExplicitEntityLookupRequest(string $message): bool
    {
        $entityTerms = $this->getConfig('entity_terms', []);
        if (empty($entityTerms)) {
            return false;
        }

        $entityPattern = implode('|', array_map('preg_quote', $entityTerms));

        if (preg_match('/\b(' . $entityPattern . ')\s*(?:#|id\s*)?\d+\b/i', $message)) {
            return true;
        }

        return preg_match('/\b(details?|open|view|get)\b.*\b(?:#\d+|id\s*\d+)\b/i', $message) === 1;
    }

    public function isPositionalReference(string $message): bool
    {
        $ordinalWords = $this->getConfig('ordinal_words', []);
        $entityWords = $this->getConfig('positional_entity_words', []);
        $patternParts = [];

        if (!empty($ordinalWords)) {
            $patternParts[] = implode('|', array_map('preg_quote', $ordinalWords));
        }

        $patternParts[] = 'the\s+\d+(?:st|nd|rd|th)?';
        $patternParts[] = 'number\s+\d+';

        if (!empty($entityWords)) {
            $entityPattern = implode('|', array_map('preg_quote', $entityWords));
            $patternParts[] = '(?:' . $entityPattern . ')\s*(?:#\s*)?\d+';
        }

        $pattern = '/\b(' . implode('|', $patternParts) . ')\b/i';
        if (!preg_match($pattern, $message)) {
            return false;
        }

        $position = $this->extractPosition($message);
        $maxPosition = (int) $this->getConfig('max_positional_index', 100);

        return $position !== null && $position >= 1 && $position <= $maxPosition;
    }

    public function extractPosition(string $message): ?int
    {
        $ordinalMap = $this->getConfig('ordinal_map', []);

        foreach ($ordinalMap as $word => $position) {
            if (preg_match('/\b' . preg_quote((string) $word, '/') . '\b/i', $message)) {
                return (int) $position;
            }
        }

        if (preg_match('/\b(?:the\s+|number\s+)?(\d+)\b/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function isOptionSelection(string $message, UnifiedActionContext $context): bool
    {
        $trimmed = trim($message);
        if (!is_numeric($trimmed)) {
            return false;
        }

        $optionNumber = (int) $trimmed;
        if ($optionNumber < 1) {
            return false;
        }

        $lastEntityList = $context->metadata['last_entity_list'] ?? [];
        if (is_array($lastEntityList) && !empty($lastEntityList['entity_ids']) && is_array($lastEntityList['entity_ids'])) {
            $start = max(1, (int) ($lastEntityList['start_position'] ?? 1));
            $end = (int) ($lastEntityList['end_position'] ?? ($start + count($lastEntityList['entity_ids']) - 1));
            if ($optionNumber >= $start && $optionNumber <= $end) {
                return true;
            }
        }

        $maxOption = (int) $this->getConfig('max_option_selection', 10);
        if ($optionNumber > $maxOption) {
            return false;
        }

        $history = $context->conversationHistory ?? [];
        if (empty($history)) {
            return false;
        }

        $lastAssistantMessage = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                $lastAssistantMessage = $history[$i]['content'] ?? '';
                break;
            }
        }

        if (!$lastAssistantMessage) {
            return false;
        }

        return preg_match('/\b\d+[\.\)]\s+/m', $lastAssistantMessage) === 1;
    }

    protected function containsAnyWord(string $message, array $words): bool
    {
        if (empty($words)) {
            return false;
        }

        $pattern = '/\b(' . implode('|', array_map(fn ($word) => preg_quote((string) $word, '/'), $words)) . ')\b/i';
        return preg_match($pattern, $message) === 1;
    }

    protected function getConfig(string $key, $default = null)
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        try {
            return config("ai-agent.intent.{$key}", $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
