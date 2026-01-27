<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use Illuminate\Support\Facades\Log;

/**
 * Autonomous RAG Analyzer - Uses AI to intelligently understand queries
 * No hardcoded rules - pure AI intelligence like Autonomous Collector
 */
class AutonomousRAGAnalyzer
{
    public function __construct(
        protected AIEngineService $aiEngine
    ) {}

    /**
     * Analyze query using pure AI intelligence
     * AI decides what to search for, which collections to use, and how to interpret context
     */
    public function analyze(
        string $query,
        array $conversationHistory = [],
        array $availableCollections = []
    ): array {
        try {
            // Build context from conversation history
            $contextSummary = $this->buildContextSummary($conversationHistory);
            
            // Build collections info
            $collectionsInfo = $this->buildCollectionsInfo($availableCollections);
            
            // Let AI make ALL decisions
            $prompt = $this->buildAutonomousPrompt($query, $contextSummary, $collectionsInfo);
            
            $request = new AIRequest(
                prompt: $prompt,
                maxTokens: 500,
                temperature: 0.1, // Low temperature for consistent decisions
                responseFormat: 'json'
            );

            $response = $this->aiEngine->generate($request);
            $analysis = json_decode($response->getContent(), true);
            
            if (!$analysis) {
                throw new \Exception('Failed to parse AI analysis');
            }
            
            Log::channel('ai-engine')->info('Autonomous RAG analysis', [
                'query' => $query,
                'needs_context' => $analysis['needs_context'] ?? false,
                'reasoning' => $analysis['reasoning'] ?? '',
                'search_queries' => $analysis['search_queries'] ?? [],
                'collections' => $analysis['collections'] ?? [],
            ]);
            
            return $analysis;
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Autonomous RAG analysis failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback: simple analysis
            return [
                'needs_context' => true,
                'reasoning' => 'Fallback analysis',
                'search_queries' => [$query],
                'collections' => $availableCollections,
                'query_type' => 'informational',
            ];
        }
    }

    /**
     * Build autonomous prompt that lets AI make all decisions
     */
    protected function buildAutonomousPrompt(
        string $query,
        string $contextSummary,
        string $collectionsInfo
    ): string {
        return <<<PROMPT
You are an intelligent query analyzer. Your job is to understand what the user wants and determine how to search for it.

CURRENT QUERY: "{$query}"

{$contextSummary}

{$collectionsInfo}

YOUR TASK:
Analyze the query and conversation context to determine:
1. What is the user actually asking for? (consider context from previous messages)
2. What should we search for? (generate semantic search terms)
3. Which collections are relevant? (based on descriptions)
4. Is this a continuation of a previous query? (if user says "more", "next", etc.)

IMPORTANT RULES:
- If user says "more", "next", "continue" → They want more results from the PREVIOUS query
- If user asks about something mentioned in previous responses → Search for that specific thing
- If user's query is vague → Use conversation context to understand what they mean
- Generate search terms that will actually match indexed content
- Only select collections whose descriptions match the query intent
- Be smart about synonyms and related terms

RESPOND WITH JSON:
{
  "needs_context": true/false,
  "reasoning": "brief explanation of your decision",
  "search_queries": ["term1", "term2"],
  "collections": ["Full\\\\Namespace\\\\ClassName"],
  "query_type": "informational|aggregate|continuation",
  "is_continuation": true/false,
  "previous_query_reference": "what the user is referring to from history"
}

Think step by step:
1. What does the user want?
2. Is this related to previous conversation?
3. What terms will find relevant content?
4. Which collections match this intent?

RESPOND WITH ONLY JSON:
PROMPT;
    }

    /**
     * Build context summary from conversation history
     */
    protected function buildContextSummary(array $conversationHistory): string
    {
        if (empty($conversationHistory)) {
            return "CONVERSATION CONTEXT: This is the first message in the conversation.";
        }

        // Get last 3 exchanges for context
        $recentMessages = array_slice($conversationHistory, -6);
        
        $summary = "CONVERSATION CONTEXT (last few messages):\n";
        foreach ($recentMessages as $msg) {
            $role = $msg['role'] === 'user' ? 'USER' : 'ASSISTANT';
            $content = mb_substr($msg['content'], 0, 200);
            $summary .= "{$role}: {$content}\n";
        }
        
        return $summary;
    }

    /**
     * Build collections info with descriptions
     */
    protected function buildCollectionsInfo(array $availableCollections): string
    {
        if (empty($availableCollections)) {
            return "AVAILABLE COLLECTIONS: None specified - you can search any collection.";
        }

        $info = "AVAILABLE COLLECTIONS:\n";
        
        foreach ($availableCollections as $collection) {
            $name = class_basename($collection);
            $description = '';
            
            // Try to get description from model
            if (class_exists($collection)) {
                try {
                    $instance = new $collection();
                    if (method_exists($instance, 'getRAGDescription')) {
                        $description = $instance->getRAGDescription();
                    }
                    if (method_exists($instance, 'getRAGDisplayName')) {
                        $name = $instance->getRAGDisplayName();
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }
            
            $info .= "- {$name}";
            if ($description) {
                $info .= ": {$description}";
            }
            $info .= "\n  Class: {$collection}\n";
        }
        
        return $info;
    }
}
