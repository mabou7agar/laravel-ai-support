<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Media\DocumentService;

/**
 * FileAnalysisService - Complete file analysis service
 * 
 * Handles:
 * - File content extraction (CSV, PDF, Word, Text)
 * - Image analysis using GPT-4o vision
 * - Document analysis with RAG
 * - Action suggestions based on content
 */
class FileAnalysisService
{
    public function __construct(
        protected ?ChatService $chatService = null,
        protected ?\LaravelAIEngine\Services\ConversationService $conversationService = null,
        protected ?DocumentService $documentService = null
    ) {
        $this->chatService = $chatService ?? app(ChatService::class);
        $this->conversationService = $conversationService ?? app(\LaravelAIEngine\Services\ConversationService::class);
        $this->documentService = $documentService ?? app(DocumentService::class);
    }

    /**
     * Analyze file completely - handles images, documents, extraction, and suggestions
     * 
     * @param \Illuminate\Http\UploadedFile $file Uploaded file
     * @param string $message User message/instructions
     * @param string $sessionId Session identifier
     * @param string $engine AI engine (openai, anthropic, etc.)
     * @param string $model AI model
     * @param bool $useRag Enable RAG
     * @param array $ragCollections RAG collections to search
     * @param mixed $userId User ID
     * @return array Complete analysis result
     */
    public function analyzeFile(
        $file,
        string $message,
        string $sessionId,
        string $engine = 'openai',
        string $model = 'gpt-4o',
        bool $useRag = true,
        array $ragCollections = [],
        $userId = null
    ): array {
        $mimeType = $file->getMimeType();
        $isImage = str_starts_with($mimeType, 'image/');
        
        if ($isImage) {
            return $this->analyzeImage($file, $message, $sessionId, $engine, $userId);
        } else {
            return $this->analyzeDocument($file, $message, $sessionId, $engine, $model, $useRag, $ragCollections, $userId);
        }
    }

    /**
     * Analyze image file using GPT-4o vision
     */
    protected function analyzeImage($file, string $message, string $sessionId = '', string $engine = 'openai', $userId = null): array
    {
        // Read image as base64
        $imageData = base64_encode(file_get_contents($file->getRealPath()));
        $mimeType = $file->getMimeType();
        $dataUrl = "data:{$mimeType};base64,{$imageData}";

        // Use OpenAI client directly for vision
        $apiKey = config('ai-engine.engines.openai.api_key');
        
        if (empty($apiKey)) {
            throw new \Exception('OpenAI API key is not configured for image analysis.');
        }

        $client = \OpenAI::client($apiKey);
        
        $systemPrompt = "You are an expert at analyzing images and extracting information. Extract all relevant visible data in a structured format. Be thorough and accurate. For transactional documents, include parties, dates, line items, totals, taxes, and payment details when visible.";
        
        $response = $client->chat()->create([
            'model' => 'gpt-4o',
            'max_tokens' => 2000,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $message,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $content = $response->choices[0]->message->content ?? '';

        // Try to extract structured data from the response
        $extractedData = $this->tryExtractStructuredData($content);
        
        // Suggest actions based on content
        $suggestions = $this->suggestActions($content, $file->getClientOriginalName());

        // Store in conversation history for follow-up support
        if (!empty($sessionId) && !empty($userId)) {
            try {
                $conversationId = $this->conversationService->getOrCreateConversation($sessionId, $userId, $engine, 'gpt-4o');
                $conversationManager = app(\LaravelAIEngine\Services\ConversationManager::class);
                
                $fileName = $file->getClientOriginalName();
                $userMessage = "[Uploaded image: {$fileName}]\n\n{$message}";
                
                // Add user message with file analysis data
                $conversationManager->addUserMessage($conversationId, $userMessage, [
                    'file_name' => $fileName,
                    'file_type' => $mimeType,
                    'is_file_upload' => true,
                    'is_image' => true,
                    'file_analysis' => [
                        'content' => $content,
                        'extracted_data' => $extractedData,
                    ],
                ]);
                
                // Add assistant response
                $aiResponse = new \LaravelAIEngine\DTOs\AIResponse(
                    content: $content,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                    model: new \LaravelAIEngine\Enums\EntityEnum('gpt-4o'),
                    metadata: [
                        'file_analysis' => true,
                        'image_analysis' => true,
                        'extracted_data' => $extractedData,
                    ]
                );
                $conversationManager->addAssistantMessage($conversationId, $content, $aiResponse);
                
                // Clear cache
                \Illuminate\Support\Facades\Cache::forget("conversation_history:{$conversationId}");
                
                Log::info('Image analysis stored in conversation history', [
                    'conversation_id' => $conversationId,
                    'file_name' => $fileName,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to store image analysis in conversation history', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'content' => $content,
            'extracted_data' => $extractedData,
            'rag_enabled' => false,
            'file_content' => "[Image: {$file->getClientOriginalName()}]\n\nAI Analysis:\n{$content}",
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Analyze document file with text extraction and RAG
     */
    protected function analyzeDocument(
        $file,
        string $message,
        string $sessionId,
        string $engine,
        string $model,
        bool $useRag,
        array $ragCollections,
        $userId
    ): array {
        // Extract text content
        $content = $this->extractContent($file);

        if (empty($content)) {
            throw new \Exception('Could not extract text from the file. The file may be empty, corrupted, or in an unsupported format.');
        }

        // Truncate very long content to avoid token limits
        $maxContentLength = 15000;
        if (strlen($content) > $maxContentLength) {
            $content = substr($content, 0, $maxContentLength) . "\n\n[Content truncated due to length...]";
        }

        // Build prompt with file content and analysis instructions
        $fileName = $file->getClientOriginalName();
        $extension = strtoupper($file->getClientOriginalExtension());
        
        $fullMessage = "I've uploaded a {$extension} document named '{$fileName}' with the following content:\n\n---\n{$content}\n---\n\n";
        
        // Add specific instructions based on user message or default
        if (empty($message) || $message === 'Analyze this file and extract relevant information.') {
            $fullMessage .= "Please analyze this document and extract all relevant information. For transactional documents, extract parties, dates, line items, subtotals, taxes, totals, and payment details when available. Present the data in a clear, structured format.";
        } else {
            $fullMessage .= $message;
        }

        // Use ChatService for RAG-enabled analysis
        $response = $this->chatService->processMessage(
            message: $fullMessage,
            sessionId: $sessionId,
            engine: $engine,
            model: $model,
            useMemory: true,
            useActions: false,
            useRag: $useRag,
            ragCollections: $ragCollections,
            userId: $userId
        );

        $metadata = $response->getMetadata();

        // Try to extract structured data
        $extractedData = $this->tryExtractStructuredData($response->getContent());
        
        // Suggest actions based on content
        $suggestions = $this->suggestActions($content, $fileName);

        // Store file analysis data in the most recent user message
        try {
            $conversationId = $this->conversationService->getOrCreateConversation($sessionId, $userId, $engine, $model);
            
            // Get the most recent user message and add file_analysis metadata
            $recentMessage = \LaravelAIEngine\Models\Message::inConversation($conversationId)
                ->byRole('user')
                ->recent()
                ->first();
            
            if ($recentMessage) {
                $messageMetadata = $recentMessage->metadata ?? [];
                $messageMetadata['file_analysis'] = [
                    'content' => $response->getContent(),
                    'extracted_data' => $extractedData,
                ];
                $messageMetadata['file_name'] = $fileName;
                $messageMetadata['is_file_upload'] = true;
                
                $recentMessage->update(['metadata' => $messageMetadata]);
                
                Log::info('File analysis data added to conversation message', [
                    'conversation_id' => $conversationId,
                    'message_id' => $recentMessage->message_id,
                    'file_name' => $fileName,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to add file analysis to message metadata', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'content' => $response->getContent(),
            'extracted_data' => $extractedData,
            'sources' => $metadata['sources'] ?? [],
            'rag_enabled' => $metadata['rag_enabled'] ?? false,
            'file_content' => $content,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Extract content from file and suggest actions
     * 
     * @param \Illuminate\Http\UploadedFile $file Uploaded file
     * @return array ['content' => string, 'suggestions' => array]
     */
    public function extractAndSuggest($file): array
    {
        $content = $this->extractContent($file);
        $suggestions = $this->suggestActions($content, $file->getClientOriginalName());
        
        return [
            'content' => $content,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Analyze content and suggest actions
     * 
     * @param string $content Extracted file content
     * @param string $fileName Original filename (for context)
     * @return array Suggested actions with confidence scores
     */
    public function suggestActions(string $content, string $fileName = ''): array
    {
        if (empty($content)) {
            return [];
        }
        
        // Analyze content and generate suggestions
        return $this->keywordAnalysis($content);
    }

    /**
     * Extract text content from uploaded file
     */
    public function extractContent($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        return $this->documentService->extractText($path, $extension);
    }

    /**
     * Keyword-based analysis to detect content type and suggest actions
     */
    protected function keywordAnalysis(string $content): array
    {
        $suggestions = [];

        foreach ((array) config('ai-engine.file_analysis.keyword_suggestions', []) as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }

            $pattern = (string) ($suggestion['pattern'] ?? '');
            if ($pattern === '' || @preg_match($pattern, '') === false) {
                continue;
            }

            if (preg_match($pattern, $content)) {
                $suggestions[] = [
                    'action_id' => (string) ($suggestion['action_id'] ?? ''),
                    'action_label' => (string) ($suggestion['action_label'] ?? ''),
                    'confidence' => (int) ($suggestion['confidence'] ?? 50),
                    'reason' => (string) ($suggestion['reason'] ?? 'File matched configured keywords.'),
                    'detected_fields' => array_values((array) ($suggestion['detected_fields'] ?? [])),
                ];
            }
        }
        
        return $suggestions;
    }


    /**
     * Try to extract structured data from AI response
     */
    protected function tryExtractStructuredData(string $content): ?array
    {
        // Look for JSON blocks in the response
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $jsonStr = trim($matches[1]);
            $data = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        // Try to parse the entire content as JSON
        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        // Extract key-value pairs from common patterns
        $extracted = [];
        
        // Pattern: "Key: Value" or "**Key**: Value"
        if (preg_match_all('/\*?\*?([A-Za-z\s]+)\*?\*?:\s*([^\n]+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim(strtolower(str_replace(' ', '_', $match[1])));
                $value = trim($match[2]);
                if (strlen($key) < 30 && strlen($value) < 200) {
                    $extracted[$key] = $value;
                }
            }
        }

        return !empty($extracted) ? $extracted : null;
    }
}
