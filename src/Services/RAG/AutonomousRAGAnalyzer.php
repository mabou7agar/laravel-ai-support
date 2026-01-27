<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\DTOs\AIRequest;
use Illuminate\Support\Facades\Log;

/**
 * Autonomous RAG Analyzer - Uses AI to intelligently understand queries
 * No hardcoded rules - pure AI intelligence like Autonomous Collector
 */
class AutonomousRAGAnalyzer
{
    public function __construct(
        protected AIEngineManager $aiEngine
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
            
            // Use the engine builder pattern
            $response = $this->aiEngine
                ->model('gpt-4o-mini')
                ->withMaxTokens(500)
                ->withTemperature(0.1)
                ->generate($prompt);
            
            $content = $response->getContent();
            
            // Extract JSON from response (may be wrapped in markdown)
            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $content = $matches[0];
            }
            
            $analysis = json_decode($content, true);
            
            if (!$analysis) {
                throw new \Exception('Failed to parse AI analysis');
            }
            
            // Ensure needs_context is true for queries that need data retrieval
            // (AI sometimes returns false incorrectly)
            if (in_array($analysis['query_type'] ?? '', ['informational', 'continuation', 'aggregate', 'detail_request'])) {
                $analysis['needs_context'] = true;
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
4. Is this a continuation/reference to previous results?

CRITICAL RULES FOR QUERY TYPES:

AGGREGATE QUERIES (query_type = "aggregate"):
- "how many", "count", "total", "number of" → User wants a COUNT, not search results
- Example: "how many emails do I have" → query_type: "aggregate"
- Example: "count my invoices" → query_type: "aggregate"
- For aggregate queries, set needs_aggregate: true

NUMBERED REFERENCES:
- If user types a NUMBER (1, 2, 3, #1, #2) → They want details about that NUMBERED ITEM from the previous response
  → Extract the title/subject/name of that item from the assistant's previous message
  → Use that exact title as the search query
  → Example: Previous response showed "1. Subject: Check This mail" → User types "1" → Search for "Check This mail"
  
DETAIL REQUESTS:
- If user says "tell me more", "more details", "expand on that" → They want more info about the LAST item discussed
  → Extract the key identifier from the previous response
  → Search for that specific item

CONTINUATION/PAGINATION:
- If user says "more", "next", "continue" → They want MORE RESULTS (pagination), not details
  → Use the same search terms as the previous query

CONTEXT-DEPENDENT SHORT QUERIES:
- Single word/short queries → ALWAYS check conversation context first
  → "1" alone means item #1 from previous list
  → "yes" might be confirmation
  → "that one" refers to something specific in context

IMPORTANT: If the assistant already answered the question in previous messages, use that information!
- Example: Assistant said "you have one email" → User asks "how many mails" → Answer is already known: 1

RESPOND WITH JSON:
{
  "needs_context": true/false,
  "reasoning": "brief explanation of your decision",
  "search_queries": ["term1", "term2"],
  "collections": ["Full\\\\Namespace\\\\ClassName"],
  "query_type": "informational|aggregate|continuation|detail_request|already_answered",
  "is_continuation": true/false,
  "is_detail_request": true/false,
  "needs_aggregate": true/false,
  "referenced_item": "the specific item name/title user is asking about",
  "answer_from_context": "if answer is already in conversation history, put it here"
}

Think step by step:
1. Is this a numbered reference or short query? Check conversation history!
2. What specific item is the user referring to?
3. What exact search terms will find that item?
4. Which collections contain this data?

RESPOND WITH ONLY JSON:
PROMPT;
    }

    /**
     * Build context summary from conversation history
     * Uses configurable message limit for efficiency
     */
    protected function buildContextSummary(array $conversationHistory): string
    {
        if (empty($conversationHistory)) {
            return "CONVERSATION CONTEXT: This is the first message in the conversation.";
        }

        // Configurable: how many messages to include in context analysis
        // Default: 6 messages (3 exchanges) - enough for "1", "more", "tell me more"
        $messageLimit = config('ai-engine.intelligent_rag.context_messages', 6);
        $contentLimit = config('ai-engine.intelligent_rag.context_content_length', 200);
        
        $recentMessages = array_slice($conversationHistory, -$messageLimit);
        
        $summary = "CONVERSATION CONTEXT (last few messages):\n";
        foreach ($recentMessages as $msg) {
            $role = $msg['role'] === 'user' ? 'USER' : 'ASSISTANT';
            $content = mb_substr($msg['content'], 0, $contentLimit);
            if (mb_strlen($msg['content']) > $contentLimit) {
                $content .= '...';
            }
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
