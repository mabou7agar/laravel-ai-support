<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\DeepSeek;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class DeepSeekEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->httpClient = new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
            'headers' => $this->buildHeaders(),
        ]);
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
        $contentType = $request->model->getContentType();
        
        return match ($contentType) {
            'text' => $this->generateText($request),
            default => throw new \InvalidArgumentException("Unsupported content type: {$contentType}")
        };
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        return $this->generateTextStream($request);
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($this->getApiKey())) {
            return false;
        }
        
        if (!$this->supports($request->model->getContentType())) {
            return false;
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::DEEPSEEK;
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities());
    }

    /**
     * Test the engine connection
     */
    public function test(): bool
    {
        try {
            $testRequest = new AIRequest(
                prompt: 'Hello',
                engine: EngineEnum::DEEPSEEK,
                model: EntityEnum::DEEPSEEK_CHAT
            );
            
            $response = $this->generateText($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate text content
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->logApiRequest('generateText', $request);
            
            $messages = $this->buildMessages($request);
            $payload = $this->buildChatPayload($request, $messages, [
                'top_p' => $request->parameters['top_p'] ?? 1.0,
                'frequency_penalty' => $request->parameters['frequency_penalty'] ?? 0.0,
                'presence_penalty' => $request->parameters['presence_penalty'] ?? 0.0,
                'stream' => false,
            ]);

            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload,
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents());
            $content = $data['choices'][0]['message']['content'] ?? '';

            return $this->buildSuccessResponse(
                $content,
                $request,
                $data,
                'deepseek'
            );

        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'text generation');
        }
    }

    /**
     * Generate streaming text content
     */
    public function generateTextStream(AIRequest $request): \Generator
    {
        try {
            $messages = $this->buildMessages($request);
            
            $payload = [
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens ?? 4096,
                'temperature' => $request->temperature ?? 0.7,
                'stream' => true,
            ];

            $response = $this->httpClient->post('/chat/completions', [
                'json' => $payload,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            while (!$stream->eof()) {
                $line = trim($stream->read(1024));
                if (strpos($line, 'data: ') === 0) {
                    $jsonData = substr($line, 6);
                    if ($jsonData === '[DONE]') {
                        break;
                    }
                    
                    $data = json_decode($jsonData, true);
                    if (isset($data['choices'][0]['delta']['content'])) {
                        yield $data['choices'][0]['delta']['content'];
                    }
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('DeepSeek streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Generate code (specialized for DeepSeek Coder models)
     */
    public function generateCode(AIRequest $request): AIResponse
    {
        // Use specialized system prompt for code generation
        $codeSystemPrompt = "You are an expert programmer. Generate clean, efficient, and well-documented code. Follow best practices and include comments where appropriate.";
        
        $originalSystemPrompt = $request->systemPrompt;
        $request->systemPrompt = $codeSystemPrompt . ($originalSystemPrompt ? "\n\n" . $originalSystemPrompt : "");
        
        $response = $this->generateText($request);
        
        // Restore original system prompt
        $request->systemPrompt = $originalSystemPrompt;
        
        return $response->withDetailedUsage([
            'code_generation' => true,
            'language_detected' => $this->detectProgrammingLanguage($response->content),
        ]);
    }

    /**
     * Generate mathematical solutions (specialized for DeepSeek Math models)
     */
    public function generateMath(AIRequest $request): AIResponse
    {
        $mathSystemPrompt = "You are an expert mathematician. Provide step-by-step solutions with clear explanations. Use proper mathematical notation and verify your answers.";
        
        $originalSystemPrompt = $request->systemPrompt;
        $request->systemPrompt = $mathSystemPrompt . ($originalSystemPrompt ? "\n\n" . $originalSystemPrompt : "");
        
        $response = $this->generateText($request);
        
        // Restore original system prompt
        $request->systemPrompt = $originalSystemPrompt;
        
        return $response->withDetailedUsage([
            'math_generation' => true,
            'contains_latex' => $this->containsLatex($response->content),
        ]);
    }

    /**
     * Generate images (not supported)
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Image generation not supported by DeepSeek',
            $request->engine,
            $request->model
        );
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        return [
            ['id' => 'deepseek-chat', 'name' => 'DeepSeek Chat', 'type' => 'chat'],
            ['id' => 'deepseek-coder', 'name' => 'DeepSeek Coder', 'type' => 'code'],
            ['id' => 'deepseek-math', 'name' => 'DeepSeek Math', 'type' => 'math'],
            ['id' => 'deepseek-reasoner', 'name' => 'DeepSeek Reasoner', 'type' => 'reasoning'],
        ];
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'code', 'math', 'reasoning', 'streaming'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return new EngineEnum(EngineEnum::DEEPSEEK);
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::DEEPSEEK_CHAT;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('DeepSeek API key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Build messages array for chat completion
     */
    private function buildMessages(AIRequest $request): array
    {
        // Use centralized method from BaseEngineDriver
        return $this->buildStandardMessages($request);
    }

    /**
     * Detect programming language in generated code
     */
    private function detectProgrammingLanguage(string $content): ?string
    {
        $patterns = [
            'php' => '/(?:<?php|class\s+\w+|function\s+\w+\s*\(|\$\w+)/i',
            'javascript' => '/(?:function\s+\w+\s*\(|const\s+\w+|let\s+\w+|var\s+\w+)/i',
            'python' => '/(?:def\s+\w+\s*\(|import\s+\w+|from\s+\w+\s+import|class\s+\w+\s*:)/i',
            'java' => '/(?:public\s+class|private\s+\w+|public\s+static\s+void\s+main)/i',
            'cpp' => '/(?:#include\s*<|using\s+namespace|int\s+main\s*\()/i',
            'sql' => '/(?:SELECT\s+|INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM)/i',
        ];

        foreach ($patterns as $language => $pattern) {
            if (preg_match($pattern, $content)) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Check if content contains LaTeX mathematical notation
     */
    private function containsLatex(string $content): bool
    {
        return preg_match('/\$\$.*?\$\$|\$.*?\$|\\\\[a-zA-Z]+/', $content) === 1;
    }
}
