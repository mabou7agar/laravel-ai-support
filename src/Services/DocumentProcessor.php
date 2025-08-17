<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class DocumentProcessor
{
    /**
     * Process PDF document
     */
    public function processPDF(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('PDF file not found');
        }

        // Extract text from PDF
        $text = $this->extractPDFText($filePath);
        
        // Split into chunks for processing
        $chunks = $this->splitIntoChunks($text);
        
        // Generate embeddings for each chunk
        $embeddings = $this->generateEmbeddings($chunks);
        
        return [
            'text' => $text,
            'chunks' => $chunks,
            'embeddings' => $embeddings,
            'metadata' => $this->extractPDFMetadata($filePath),
        ];
    }

    /**
     * Process Word document
     */
    public function processWord(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('Word document not found');
        }

        // Extract text from Word document
        $text = $this->extractWordText($filePath);
        
        // Split into chunks for processing
        $chunks = $this->splitIntoChunks($text);
        
        // Generate embeddings for each chunk
        $embeddings = $this->generateEmbeddings($chunks);
        
        return [
            'text' => $text,
            'chunks' => $chunks,
            'embeddings' => $embeddings,
            'metadata' => $this->extractWordMetadata($filePath),
        ];
    }

    /**
     * Process CSV document
     */
    public function processCSV(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('CSV file not found');
        }

        $data = [];
        $headers = [];
        
        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = fgetcsv($handle);
            
            while (($row = fgetcsv($handle)) !== false) {
                $data[] = array_combine($headers, $row);
            }
            
            fclose($handle);
        }

        // Convert to text for analysis
        $text = $this->csvToText($data, $headers);
        
        // Generate summary and insights
        $summary = $this->generateCSVSummary($data, $headers);
        
        return [
            'data' => $data,
            'headers' => $headers,
            'text' => $text,
            'summary' => $summary,
            'row_count' => count($data),
            'column_count' => count($headers),
        ];
    }

    /**
     * Analyze document with AI
     */
    public function analyzeDocument(string $filePath, string $analysisType = 'summary'): AIResponse
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        $processedData = match (strtolower($extension)) {
            'pdf' => $this->processPDF($filePath),
            'doc', 'docx' => $this->processWord($filePath),
            'csv' => $this->processCSV($filePath),
            default => throw new \InvalidArgumentException("Unsupported file type: {$extension}"),
        };

        // Create analysis prompt based on type
        $prompt = $this->buildAnalysisPrompt($processedData, $analysisType);
        
        // Use OpenAI for analysis
        $aiEngine = app('ai-engine');
        
        $request = new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            systemPrompt: $this->getAnalysisSystemPrompt($analysisType)
        );

        return $aiEngine->generateText($request);
    }

    /**
     * Search within document using vector embeddings
     */
    public function searchDocument(array $processedData, string $query): array
    {
        if (!isset($processedData['embeddings'])) {
            throw new \InvalidArgumentException('Document embeddings not available');
        }

        // Generate embedding for query
        $queryEmbedding = $this->generateQueryEmbedding($query);
        
        // Calculate similarity scores
        $similarities = [];
        foreach ($processedData['embeddings'] as $index => $embedding) {
            $similarity = $this->cosineSimilarity($queryEmbedding, $embedding);
            $similarities[] = [
                'chunk_index' => $index,
                'chunk' => $processedData['chunks'][$index],
                'similarity' => $similarity,
            ];
        }

        // Sort by similarity and return top results
        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        
        return array_slice($similarities, 0, 5);
    }

    /**
     * Extract text from PDF
     */
    private function extractPDFText(string $filePath): string
    {
        // This would use a PDF parsing library like smalot/pdfparser
        // For now, return placeholder
        return "PDF text extraction would be implemented here using a PDF parser library.";
    }

    /**
     * Extract text from Word document
     */
    private function extractWordText(string $filePath): string
    {
        // This would use a Word parsing library like phpoffice/phpword
        // For now, return placeholder
        return "Word document text extraction would be implemented here using PHPWord library.";
    }

    /**
     * Split text into chunks for processing
     */
    private function splitIntoChunks(string $text, int $chunkSize = 1000): array
    {
        $chunks = [];
        $words = explode(' ', $text);
        
        for ($i = 0; $i < count($words); $i += $chunkSize) {
            $chunk = implode(' ', array_slice($words, $i, $chunkSize));
            if (!empty(trim($chunk))) {
                $chunks[] = $chunk;
            }
        }
        
        return $chunks;
    }

    /**
     * Generate embeddings for text chunks
     */
    private function generateEmbeddings(array $chunks): array
    {
        $embeddings = [];
        $aiEngine = app('ai-engine');
        
        foreach ($chunks as $chunk) {
            $request = new AIRequest(
                prompt: $chunk,
                engine: EngineEnum::OPENAI,
                model: EntityEnum::TEXT_EMBEDDING_3_SMALL
            );
            
            $response = $aiEngine->generateEmbeddings($request);
            if ($response->isSuccess()) {
                $embeddings[] = json_decode($response->content, true);
            }
        }
        
        return $embeddings;
    }

    /**
     * Generate embedding for search query
     */
    private function generateQueryEmbedding(string $query): array
    {
        $aiEngine = app('ai-engine');
        
        $request = new AIRequest(
            prompt: $query,
            engine: EngineEnum::OPENAI,
            model: EntityEnum::TEXT_EMBEDDING_3_SMALL
        );
        
        $response = $aiEngine->generateEmbeddings($request);
        
        if ($response->isSuccess()) {
            return json_decode($response->content, true);
        }
        
        return [];
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Convert CSV data to text
     */
    private function csvToText(array $data, array $headers): string
    {
        $text = "CSV Data Analysis:\n";
        $text .= "Headers: " . implode(', ', $headers) . "\n\n";
        
        foreach (array_slice($data, 0, 10) as $row) { // First 10 rows
            $text .= implode(' | ', $row) . "\n";
        }
        
        return $text;
    }

    /**
     * Generate CSV summary
     */
    private function generateCSVSummary(array $data, array $headers): array
    {
        $summary = [
            'total_rows' => count($data),
            'total_columns' => count($headers),
            'columns' => [],
        ];

        foreach ($headers as $header) {
            $values = array_column($data, $header);
            $nonEmptyValues = array_filter($values, fn($v) => !empty($v));
            
            $summary['columns'][$header] = [
                'total_values' => count($values),
                'non_empty_values' => count($nonEmptyValues),
                'unique_values' => count(array_unique($nonEmptyValues)),
                'sample_values' => array_slice(array_unique($nonEmptyValues), 0, 5),
            ];
        }

        return $summary;
    }

    /**
     * Build analysis prompt
     */
    private function buildAnalysisPrompt(array $processedData, string $analysisType): string
    {
        $text = $processedData['text'] ?? '';
        
        return match ($analysisType) {
            'summary' => "Please provide a comprehensive summary of the following document:\n\n{$text}",
            'key_points' => "Extract the key points and main ideas from the following document:\n\n{$text}",
            'questions' => "Generate relevant questions that could be answered by this document:\n\n{$text}",
            'insights' => "Provide insights and analysis of the following document:\n\n{$text}",
            default => "Analyze the following document:\n\n{$text}",
        };
    }

    /**
     * Get system prompt for analysis
     */
    private function getAnalysisSystemPrompt(string $analysisType): string
    {
        return match ($analysisType) {
            'summary' => 'You are a document summarization expert. Provide clear, concise summaries that capture the main points.',
            'key_points' => 'You are an expert at extracting key information. Focus on the most important points and main ideas.',
            'questions' => 'You are an expert at generating relevant questions. Create questions that test understanding of the content.',
            'insights' => 'You are a document analysis expert. Provide deep insights and thoughtful analysis.',
            default => 'You are a document analysis expert. Provide helpful analysis of the given content.',
        };
    }

    /**
     * Extract PDF metadata
     */
    private function extractPDFMetadata(string $filePath): array
    {
        return [
            'file_size' => filesize($filePath),
            'file_type' => 'PDF',
            'created_at' => date('Y-m-d H:i:s', filectime($filePath)),
            'modified_at' => date('Y-m-d H:i:s', filemtime($filePath)),
        ];
    }

    /**
     * Extract Word metadata
     */
    private function extractWordMetadata(string $filePath): array
    {
        return [
            'file_size' => filesize($filePath),
            'file_type' => 'Word Document',
            'created_at' => date('Y-m-d H:i:s', filectime($filePath)),
            'modified_at' => date('Y-m-d H:i:s', filemtime($filePath)),
        ];
    }
}
