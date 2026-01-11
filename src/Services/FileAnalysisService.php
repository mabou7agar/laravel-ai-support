<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Log;

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
        protected ?\LaravelAIEngine\Services\ConversationService $conversationService = null
    ) {
        $this->chatService = $chatService ?? app(ChatService::class);
        $this->conversationService = $conversationService ?? app(\LaravelAIEngine\Services\ConversationService::class);
    }

    /**
     * Analyze file completely - handles images, documents, extraction, and suggestions
     * 
     * @param \Illuminate\Http\UploadedFile $file Uploaded file
     * @param string $message User message/instructions
     * @param string $sessionId Session identifier
     * @param string $engine AI engine (openai, anthropic, etc.)
     * @param string $model AI model
     * @param bool $useIntelligentRAG Enable RAG
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
        bool $useIntelligentRAG = true,
        array $ragCollections = [],
        $userId = null
    ): array {
        $mimeType = $file->getMimeType();
        $isImage = str_starts_with($mimeType, 'image/');
        
        if ($isImage) {
            return $this->analyzeImage($file, $message, $sessionId, $engine, $userId);
        } else {
            return $this->analyzeDocument($file, $message, $sessionId, $engine, $model, $useIntelligentRAG, $ragCollections, $userId);
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
        
        $systemPrompt = "You are an expert at analyzing images and extracting information. When analyzing receipts, invoices, or documents, extract all relevant data in a structured format. Be thorough and accurate. For receipts, extract: store name, date, items with prices, subtotal, tax, total, payment method if visible.";
        
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
                    engine: new \LaravelAIEngine\Enums\EngineEnum($engine),
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
        bool $useIntelligentRAG,
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
            $fullMessage .= "Please analyze this document and extract all relevant information. If this is a receipt or invoice, extract: vendor/store name, date, line items with prices, subtotal, tax, total amount, and payment method if available. Present the data in a clear, structured format.";
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
            useIntelligentRAG: $useIntelligentRAG,
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

        switch ($extension) {
            case 'txt':
                return file_get_contents($path);

            case 'csv':
                return $this->extractFromCSV($path);

            case 'pdf':
                return $this->extractFromPDF($path);

            case 'doc':
            case 'docx':
                return $this->extractFromWord($path, $extension);

            default:
                return '';
        }
    }

    /**
     * Extract content from CSV file
     */
    protected function extractFromCSV(string $path): string
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return '';
        }
        
        $content = [];
        $headers = fgetcsv($handle);
        if ($headers) {
            $content[] = 'Headers: ' . implode(', ', $headers);
        }
        
        // Read first 50 rows for analysis
        $rowCount = 0;
        while (($row = fgetcsv($handle)) !== false && $rowCount < 50) {
            $content[] = implode(', ', $row);
            $rowCount++;
        }
        
        fclose($handle);
        
        return implode("\n", $content);
    }

    /**
     * Extract content from PDF file
     */
    protected function extractFromPDF(string $path): string
    {
        // Try pdftotext command
        $output = [];
        $returnCode = 0;
        exec("pdftotext -layout " . escapeshellarg($path) . " -", $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }
        
        // Fallback: try Smalot PDF Parser if available
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                return $pdf->getText();
            } catch (\Exception $e) {
                Log::warning('PDF parsing failed', ['error' => $e->getMessage()]);
            }
        }
        
        return '';
    }

    /**
     * Extract content from Word documents
     */
    protected function extractFromWord(string $path, string $extension): string
    {
        // Try antiword for .doc
        if ($extension === 'doc') {
            $output = [];
            exec("antiword " . escapeshellarg($path), $output);
            if (!empty($output)) {
                return implode("\n", $output);
            }
        }
        
        // Try docx2txt for .docx
        if ($extension === 'docx') {
            $output = [];
            exec("docx2txt " . escapeshellarg($path) . " -", $output);
            if (!empty($output)) {
                return implode("\n", $output);
            }
        }
        
        return '';
    }

    /**
     * Keyword-based analysis to detect content type and suggest actions
     */
    protected function keywordAnalysis(string $content): array
    {
        $content = strtolower($content);
        $suggestions = [];
        
        // Product indicators
        if (preg_match('/\b(product|item|sku|price|stock|inventory)\b/i', $content)) {
            $suggestions[] = [
                'action_id' => 'create_products',
                'action_label' => 'Create Products',
                'confidence' => 70,
                'reason' => 'File contains product-related keywords',
                'detected_fields' => ['product', 'price'],
            ];
        }
        
        // Invoice indicators
        if (preg_match('/\b(invoice|customer|bill to|total|due date)\b/i', $content)) {
            $suggestions[] = [
                'action_id' => 'create_invoice',
                'action_label' => 'Create Invoice',
                'confidence' => 65,
                'reason' => 'File contains invoice-related keywords',
                'detected_fields' => ['customer', 'total'],
            ];
        }
        
        // Bill/Purchase indicators
        if (preg_match('/\b(vendor|supplier|purchase|bill from)\b/i', $content)) {
            $suggestions[] = [
                'action_id' => 'create_bill',
                'action_label' => 'Create Bill',
                'confidence' => 65,
                'reason' => 'File contains vendor/purchase keywords',
                'detected_fields' => ['vendor', 'amount'],
            ];
        }
        
        // Customer list indicators
        if (preg_match('/\b(customer|client|email|phone|contact)\b/i', $content)) {
            $suggestions[] = [
                'action_id' => 'create_customers',
                'action_label' => 'Create Customers',
                'confidence' => 60,
                'reason' => 'File contains customer contact information',
                'detected_fields' => ['name', 'email'],
            ];
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
