<?php

namespace LaravelAIEngine\Services\Media;

use OpenAI\Client as OpenAIClient;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\CreditManager;

class VisionService
{
    protected OpenAIClient $client;
    protected CreditManager $creditManager;
    protected string $model;

    public function __construct(
        OpenAIClient $client,
        CreditManager $creditManager
    ) {
        $this->client = $client;
        $this->creditManager = $creditManager;
        $this->model = config('ai-engine.vector.media.vision_model', 'gpt-4o');
    }

    /**
     * Analyze image using GPT-4 Vision
     */
    public function analyzeImage(
        string $imagePath,
        ?string $userId = null,
        ?string $prompt = null
    ): string {
        try {
            // Encode image to base64
            $imageData = $this->encodeImage($imagePath);

            // Default prompt for embedding
            $prompt = $prompt ?? $this->getDefaultPrompt();

            // Call GPT-4 Vision API
            $response = $this->client->chat()->create([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $prompt,
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $imageData,
                                ],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 500,
            ]);

            $description = $response->choices[0]->message->content;

            // Track credits
            $tokensUsed = $response->usage->totalTokens ?? 0;
            if ($userId) {
                // TODO: Integrate with proper credit system
                // $this->creditManager->deductCredits($userId, $tokensUsed, 'vision');
            }

            Log::info('Image analyzed with GPT-4 Vision', [
                'image_path' => $imagePath,
                'tokens_used' => $tokensUsed,
                'description_length' => strlen($description),
            ]);

            return $description;
        } catch (\Exception $e) {
            Log::error('Image analysis failed', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Analyze multiple images in batch
     */
    public function analyzeImageBatch(
        array $imagePaths,
        ?string $userId = null,
        ?string $prompt = null
    ): array {
        $results = [];

        foreach ($imagePaths as $index => $imagePath) {
            try {
                $results[$index] = $this->analyzeImage($imagePath, $userId, $prompt);
            } catch (\Exception $e) {
                Log::error('Batch image analysis failed for item', [
                    'index' => $index,
                    'image_path' => $imagePath,
                    'error' => $e->getMessage(),
                ]);
                $results[$index] = null;
            }
        }

        return $results;
    }

    /**
     * Ask specific question about image
     */
    public function askAboutImage(
        string $imagePath,
        string $question,
        ?string $userId = null
    ): string {
        return $this->analyzeImage($imagePath, $userId, $question);
    }

    /**
     * Detect objects in image
     */
    public function detectObjects(string $imagePath, ?string $userId = null): array
    {
        $prompt = "List all objects, people, and notable elements visible in this image. Return as a comma-separated list.";
        $response = $this->analyzeImage($imagePath, $userId, $prompt);
        
        // Parse comma-separated list
        $objects = array_map('trim', explode(',', $response));
        return array_filter($objects);
    }

    /**
     * Generate image caption
     */
    public function generateCaption(string $imagePath, ?string $userId = null): string
    {
        $prompt = "Generate a concise, descriptive caption for this image in one sentence.";
        return $this->analyzeImage($imagePath, $userId, $prompt);
    }

    /**
     * Extract text from image (OCR)
     */
    public function extractText(string $imagePath, ?string $userId = null): string
    {
        $prompt = "Extract and transcribe all visible text from this image. If there's no text, return 'No text found'.";
        return $this->analyzeImage($imagePath, $userId, $prompt);
    }

    /**
     * Describe image for accessibility
     */
    public function generateAltText(string $imagePath, ?string $userId = null): string
    {
        $prompt = "Generate a detailed alt text description for this image for accessibility purposes. Be specific and descriptive.";
        return $this->analyzeImage($imagePath, $userId, $prompt);
    }

    /**
     * Compare two images
     */
    public function compareImages(
        string $imagePath1,
        string $imagePath2,
        ?string $userId = null
    ): string {
        try {
            $imageData1 = $this->encodeImage($imagePath1);
            $imageData2 = $this->encodeImage($imagePath2);

            $response = $this->client->chat()->create([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Compare these two images. Describe the similarities and differences.',
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $imageData1],
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $imageData2],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 500,
            ]);

            $comparison = $response->choices[0]->message->content;

            // Track credits
            $tokensUsed = $response->usage->totalTokens ?? 0;
            if ($userId) {
                // TODO: Integrate with proper credit system
                // $this->creditManager->deductCredits($userId, $tokensUsed, 'vision');
            }

            return $comparison;
        } catch (\Exception $e) {
            Log::error('Image comparison failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Encode image to base64 data URL
     */
    protected function encodeImage(string $imagePath): string
    {
        if (!file_exists($imagePath)) {
            throw new \InvalidArgumentException("Image file not found: {$imagePath}");
        }

        $imageData = file_get_contents($imagePath);
        $mimeType = mime_content_type($imagePath);
        $base64 = base64_encode($imageData);

        return "data:{$mimeType};base64,{$base64}";
    }

    /**
     * Get default prompt for image analysis
     */
    protected function getDefaultPrompt(): string
    {
        return <<<PROMPT
Describe this image in detail. Include:
- Main subjects and objects
- Colors, composition, and style
- Setting and context
- Any text visible
- Notable details or features

Provide a comprehensive description suitable for semantic search and indexing.
PROMPT;
    }

    /**
     * Set vision model
     */
    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    /**
     * Get current model
     */
    public function getModel(): string
    {
        return $this->model;
    }
}
