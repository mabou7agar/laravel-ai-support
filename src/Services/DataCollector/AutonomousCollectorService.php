<?php

namespace LaravelAIEngine\Services\DataCollector;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI-Autonomous Data Collection Service
 * 
 * Unlike the traditional DataCollector which requires field definitions,
 * this service gives AI full autonomy to:
 * - Understand the goal and context
 * - Use tools to search/create entities
 * - Have natural conversations
 * - Produce structured output when ready
 * 
 * Usage:
 * ```php
 * $service = app(AutonomousCollectorService::class);
 * 
 * $config = new AutonomousCollectorConfig(
 *     goal: 'Create a sales invoice',
 *     tools: [
 *         'find_customer' => ['handler' => fn($q) => Customer::search($q)->get()],
 *         'find_product' => ['handler' => fn($q) => Product::search($q)->get()],
 *     ],
 *     outputSchema: [
 *         'customer_id' => 'integer|required',
 *         'items' => ['type' => 'array', 'items' => [...]],
 *     ],
 *     onComplete: fn($data) => Invoice::create($data),
 * );
 * 
 * $response = $service->start($sessionId, $config, "Create invoice with 2 products");
 * $response = $service->process($sessionId, "For customer Mohamed");
 * ```
 */
class AutonomousCollectorService
{
    protected string $cachePrefix = 'autonomous_collector_';
    protected int $cacheTtl = 3600;

    public function __construct(
        protected AIEngineService $ai
    ) {}

    /**
     * Start a new autonomous collection session
     */
    public function start(
        string $sessionId,
        AutonomousCollectorConfig $config,
        string $initialMessage = ''
    ): AutonomousCollectorResponse {
        // Initialize session state
        $state = [
            'session_id' => $sessionId,
            'config' => $this->serializeConfig($config),
            'status' => 'collecting',
            'conversation' => [],
            'collected_data' => [],
            'tool_results' => [],
            'turn_count' => 0,
            'started_at' => now()->toIso8601String(),
        ];

        // Add initial message if provided
        if ($initialMessage) {
            $state['conversation'][] = [
                'role' => 'user',
                'content' => $initialMessage,
            ];
        }

        $this->saveState($sessionId, $state);

        // Process the initial message
        if ($initialMessage) {
            return $this->processInternal($sessionId, $state, $config);
        }

        // Generate greeting
        $greeting = $this->generateGreeting($config);
        
        $state['conversation'][] = [
            'role' => 'assistant',
            'content' => $greeting,
        ];
        $this->saveState($sessionId, $state);

        return new AutonomousCollectorResponse(
            success: true,
            message: $greeting,
            status: 'collecting',
            collectedData: [],
            isComplete: false,
        );
    }

    /**
     * Process a user message
     */
    public function process(string $sessionId, string $message): AutonomousCollectorResponse
    {
        $state = $this->getState($sessionId);
        
        if (!$state) {
            return new AutonomousCollectorResponse(
                success: false,
                message: 'No active session found.',
                status: 'error',
            );
        }

        // Reconstruct config
        $config = $this->deserializeConfig($state['config']);
        
        // Add user message
        $state['conversation'][] = [
            'role' => 'user',
            'content' => $message,
        ];
        $state['turn_count']++;

        // Check for cancellation
        if ($this->isCancellation($message)) {
            $state['status'] = 'cancelled';
            $this->saveState($sessionId, $state);
            
            return new AutonomousCollectorResponse(
                success: true,
                message: 'Collection cancelled.',
                status: 'cancelled',
                isCancelled: true,
            );
        }

        return $this->processInternal($sessionId, $state, $config);
    }

    /**
     * Internal processing logic
     */
    protected function processInternal(
        string $sessionId,
        array $state,
        AutonomousCollectorConfig $config
    ): AutonomousCollectorResponse {
        // Build the AI request with tools
        $systemPrompt = $config->buildSystemPrompt();
        
        // Add tool results context if any
        if (!empty($state['tool_results'])) {
            $systemPrompt .= "\n\n## Recent Tool Results\n";
            foreach (array_slice($state['tool_results'], -5) as $result) {
                $systemPrompt .= "- {$result['tool']}: " . json_encode($result['result'], JSON_UNESCAPED_UNICODE) . "\n";
            }
        }

        // Build conversation for AI
        $conversationPrompt = $this->buildConversationPrompt($state['conversation']);

        try {
            // Generate AI response with function calling capability
            $response = $this->generateWithTools($systemPrompt, $conversationPrompt, $config);
            
            // Check if AI wants to call a tool
            if (!empty($response['tool_calls'])) {
                return $this->handleToolCalls($sessionId, $state, $config, $response);
            }

            $aiMessage = $response['content'] ?? '';
            
            // Check if AI produced final output
            $finalOutput = $this->extractFinalOutput($aiMessage);
            
            if ($finalOutput !== null) {
                return $this->handleCompletion($sessionId, $state, $config, $finalOutput, $aiMessage);
            }

            // Regular conversation response
            $state['conversation'][] = [
                'role' => 'assistant',
                'content' => $aiMessage,
            ];
            $this->saveState($sessionId, $state);

            return new AutonomousCollectorResponse(
                success: true,
                message: $aiMessage,
                status: 'collecting',
                collectedData: $state['collected_data'],
                turnCount: $state['turn_count'],
            );

        } catch (\Exception $e) {
            Log::error('AutonomousCollector error', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return new AutonomousCollectorResponse(
                success: false,
                message: "I encountered an error. Let's try again. " . $e->getMessage(),
                status: 'error',
            );
        }
    }

    /**
     * Generate AI response with tool/function calling support
     */
    protected function generateWithTools(
        string $systemPrompt,
        string $userPrompt,
        AutonomousCollectorConfig $config
    ): array {
        $tools = $config->getToolDefinitions();
        
        // Build a prompt that instructs AI to use JSON for tool calls
        $fullPrompt = $userPrompt;
        
        if (!empty($tools)) {
            $fullPrompt .= "\n\n---\nIf you need to use a tool, respond with:\n";
            $fullPrompt .= "```tool\n{\"tool\": \"tool_name\", \"arguments\": {...}}\n```\n";
            $fullPrompt .= "Otherwise, respond naturally to the user.\n";
            $fullPrompt .= "When you have all required information, output the final data as:\n";
            $fullPrompt .= "```json\n{...final output...}\n```";
        }

        $response = $this->ai->generate(
            new AIRequest(
                prompt: $fullPrompt,
                systemPrompt: $systemPrompt,
                maxTokens: 1500,
                temperature: 0.7,
            )
        );

        $content = $response->getContent();
        
        // Check for tool call
        if (preg_match('/```tool\s*\n?(.*?)\n?```/s', $content, $matches)) {
            $toolCall = json_decode(trim($matches[1]), true);
            if ($toolCall && isset($toolCall['tool'])) {
                return [
                    'content' => preg_replace('/```tool\s*\n?.*?\n?```/s', '', $content),
                    'tool_calls' => [$toolCall],
                ];
            }
        }

        return ['content' => $content, 'tool_calls' => []];
    }

    /**
     * Handle tool calls from AI
     */
    protected function handleToolCalls(
        string $sessionId,
        array $state,
        AutonomousCollectorConfig $config,
        array $response
    ): AutonomousCollectorResponse {
        $toolResults = [];
        
        foreach ($response['tool_calls'] as $toolCall) {
            $toolName = $toolCall['tool'];
            $arguments = $toolCall['arguments'] ?? [];
            
            try {
                $result = $config->executeTool($toolName, $arguments);
                
                // Convert models to arrays for JSON
                if ($result instanceof \Illuminate\Support\Collection) {
                    $result = $result->map(fn($item) => 
                        $item instanceof \Illuminate\Database\Eloquent\Model 
                            ? $item->toArray() 
                            : $item
                    )->toArray();
                } elseif ($result instanceof \Illuminate\Database\Eloquent\Model) {
                    $result = $result->toArray();
                }
                
                $toolResults[] = [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'result' => $result,
                    'success' => true,
                ];
                
                Log::info('Tool executed', [
                    'session_id' => $sessionId,
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'result_count' => is_array($result) ? count($result) : 1,
                ]);
                
            } catch (\Exception $e) {
                $toolResults[] = [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
                
                Log::warning('Tool execution failed', [
                    'session_id' => $sessionId,
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Store tool results
        $state['tool_results'] = array_merge($state['tool_results'] ?? [], $toolResults);
        
        // Add tool results to conversation as system message
        $toolResultMessage = "Tool results:\n";
        foreach ($toolResults as $tr) {
            if ($tr['success']) {
                $resultStr = is_array($tr['result']) 
                    ? json_encode($tr['result'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    : (string) $tr['result'];
                $toolResultMessage .= "- {$tr['tool']}: " . substr($resultStr, 0, 500) . "\n";
            } else {
                $toolResultMessage .= "- {$tr['tool']}: Error - {$tr['error']}\n";
            }
        }
        
        $state['conversation'][] = [
            'role' => 'system',
            'content' => $toolResultMessage,
        ];

        $this->saveState($sessionId, $state);

        // Continue processing with tool results
        return $this->processInternal($sessionId, $state, $config);
    }

    /**
     * Extract final JSON output from AI message
     */
    protected function extractFinalOutput(string $message): ?array
    {
        // Look for ```json ... ``` block
        if (preg_match('/```json\s*\n?(.*?)\n?```/s', $message, $matches)) {
            $json = trim($matches[1]);
            $data = json_decode($json, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }
        
        return null;
    }

    /**
     * Handle completion with final output
     */
    protected function handleCompletion(
        string $sessionId,
        array $state,
        AutonomousCollectorConfig $config,
        array $finalOutput,
        string $aiMessage
    ): AutonomousCollectorResponse {
        // Validate output against schema
        $errors = $config->validateOutput($finalOutput);
        
        if (!empty($errors)) {
            // Ask AI to fix the output
            $state['conversation'][] = [
                'role' => 'system',
                'content' => "Output validation failed: " . implode(', ', $errors) . ". Please collect the missing information.",
            ];
            $this->saveState($sessionId, $state);
            
            return $this->processInternal($sessionId, $state, $config);
        }

        // Store collected data
        $state['collected_data'] = $finalOutput;
        $state['status'] = $config->confirmBeforeComplete ? 'confirming' : 'completed';
        
        // Clean message (remove JSON block for display)
        $cleanMessage = preg_replace('/```json\s*\n?.*?\n?```/s', '', $aiMessage);
        $cleanMessage = trim($cleanMessage);
        
        if ($config->confirmBeforeComplete) {
            // Add confirmation request
            $summary = $this->generateSummary($finalOutput);
            $confirmMessage = $cleanMessage ?: "I've collected all the information.";
            $confirmMessage .= "\n\n**Summary:**\n" . $summary;
            $confirmMessage .= "\n\nShall I proceed? (yes/no)";
            
            $state['conversation'][] = [
                'role' => 'assistant',
                'content' => $confirmMessage,
            ];
            $this->saveState($sessionId, $state);
            
            return new AutonomousCollectorResponse(
                success: true,
                message: $confirmMessage,
                status: 'confirming',
                collectedData: $finalOutput,
                requiresConfirmation: true,
            );
        }

        // Execute completion
        return $this->executeCompletion($sessionId, $state, $config, $finalOutput);
    }

    /**
     * Execute the completion callback
     */
    protected function executeCompletion(
        string $sessionId,
        array $state,
        AutonomousCollectorConfig $config,
        array $data
    ): AutonomousCollectorResponse {
        try {
            $result = $config->executeOnComplete($data);
            
            $state['status'] = 'completed';
            $state['result'] = $result;
            $state['completed_at'] = now()->toIso8601String();
            $this->saveState($sessionId, $state);
            
            Log::info('Autonomous collection completed', [
                'session_id' => $sessionId,
                'data' => $data,
            ]);
            
            return new AutonomousCollectorResponse(
                success: true,
                message: 'Successfully completed!',
                status: 'completed',
                collectedData: $data,
                isComplete: true,
                result: $result,
            );
            
        } catch (\Exception $e) {
            Log::error('Completion execution failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            
            return new AutonomousCollectorResponse(
                success: false,
                message: "Failed to complete: {$e->getMessage()}",
                status: 'error',
                collectedData: $data,
            );
        }
    }

    /**
     * Confirm and execute completion
     */
    public function confirm(string $sessionId): AutonomousCollectorResponse
    {
        $state = $this->getState($sessionId);
        
        if (!$state || $state['status'] !== 'confirming') {
            return new AutonomousCollectorResponse(
                success: false,
                message: 'No pending confirmation.',
                status: 'error',
            );
        }

        $config = $this->deserializeConfig($state['config']);
        return $this->executeCompletion($sessionId, $state, $config, $state['collected_data']);
    }

    /**
     * Generate a greeting message
     */
    protected function generateGreeting(AutonomousCollectorConfig $config): string
    {
        return "Hello! I'll help you {$config->goal}. What would you like to do?";
    }

    /**
     * Generate a summary of collected data
     */
    protected function generateSummary(array $data, int $depth = 0): string
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (isset($value[0])) {
                    // Array of items
                    $lines[] = "{$indent}- **{$key}**: " . count($value) . " item(s)";
                    foreach ($value as $i => $item) {
                        if (is_array($item)) {
                            $itemSummary = [];
                            foreach ($item as $k => $v) {
                                if (!is_array($v)) {
                                    $itemSummary[] = "{$k}: {$v}";
                                }
                            }
                            $lines[] = "{$indent}  - " . implode(', ', $itemSummary);
                        } else {
                            $lines[] = "{$indent}  - {$item}";
                        }
                    }
                } else {
                    // Nested object
                    $lines[] = "{$indent}- **{$key}**:";
                    $lines[] = $this->generateSummary($value, $depth + 1);
                }
            } else {
                $lines[] = "{$indent}- **{$key}**: {$value}";
            }
        }
        
        return implode("\n", $lines);
    }

    /**
     * Build conversation prompt from history
     */
    protected function buildConversationPrompt(array $conversation): string
    {
        $prompt = "";
        
        foreach ($conversation as $msg) {
            $role = ucfirst($msg['role']);
            $prompt .= "{$role}: {$msg['content']}\n\n";
        }
        
        return $prompt;
    }

    /**
     * Check if message is a cancellation request
     */
    protected function isCancellation(string $message): bool
    {
        $message = strtolower(trim($message));
        return in_array($message, ['cancel', 'stop', 'quit', 'exit', 'nevermind', 'never mind']);
    }

    /**
     * Save session state
     */
    protected function saveState(string $sessionId, array $state): void
    {
        Cache::put($this->cachePrefix . $sessionId, $state, $this->cacheTtl);
    }

    /**
     * Get session state
     */
    protected function getState(string $sessionId): ?array
    {
        return Cache::get($this->cachePrefix . $sessionId);
    }

    /**
     * Check if session exists
     */
    public function hasSession(string $sessionId): bool
    {
        return Cache::has($this->cachePrefix . $sessionId);
    }

    /**
     * Get session status
     */
    public function getStatus(string $sessionId): ?string
    {
        $state = $this->getState($sessionId);
        return $state['status'] ?? null;
    }

    /**
     * Get collected data
     */
    public function getData(string $sessionId): array
    {
        $state = $this->getState($sessionId);
        return $state['collected_data'] ?? [];
    }

    /**
     * Delete session
     */
    public function deleteSession(string $sessionId): void
    {
        Cache::forget($this->cachePrefix . $sessionId);
    }

    /**
     * Serialize config for storage (closures can't be serialized)
     */
    protected function serializeConfig(AutonomousCollectorConfig $config): array
    {
        return [
            'goal' => $config->goal,
            'description' => $config->description,
            'output_schema' => $config->outputSchema,
            'confirm_before_complete' => $config->confirmBeforeComplete,
            'system_prompt_addition' => $config->systemPromptAddition,
            'context' => $config->context,
            'max_turns' => $config->maxTurns,
            'name' => $config->name,
            // Tools with closures need special handling - store tool metadata only
            'tools_meta' => array_map(fn($t) => [
                'description' => $t['description'] ?? '',
                'parameters' => $t['parameters'] ?? [],
            ], $config->tools),
        ];
    }

    /**
     * Deserialize config (tools need to be re-registered)
     */
    protected function deserializeConfig(array $data): AutonomousCollectorConfig
    {
        // Note: This returns config without tool handlers
        // For full functionality, config should be re-registered or passed fresh
        return new AutonomousCollectorConfig(
            goal: $data['goal'],
            description: $data['description'] ?? '',
            tools: [], // Tools with closures can't be restored from cache
            outputSchema: $data['output_schema'] ?? [],
            confirmBeforeComplete: $data['confirm_before_complete'] ?? true,
            systemPromptAddition: $data['system_prompt_addition'] ?? null,
            context: $data['context'] ?? [],
            maxTurns: $data['max_turns'] ?? 20,
            name: $data['name'] ?? null,
        );
    }

    /**
     * Register config with tools for session restoration
     */
    protected array $registeredConfigs = [];

    public function registerConfig(AutonomousCollectorConfig $config): void
    {
        if ($config->name) {
            $this->registeredConfigs[$config->name] = $config;
        }
    }

    public function getRegisteredConfig(string $name): ?AutonomousCollectorConfig
    {
        return $this->registeredConfigs[$name] ?? null;
    }
}
