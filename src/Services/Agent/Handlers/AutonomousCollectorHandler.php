<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Handler for Autonomous Collector sessions
 * 
 * Integrates AutonomousCollector with AgentOrchestrator so that:
 * 1. Active collector sessions are automatically detected
 * 2. Messages are routed to the collector
 * 3. State is managed through UnifiedActionContext
 */
class AutonomousCollectorHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AutonomousCollectorService $collectorService
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        $sessionId = $context->sessionId;
        $action = $options['action'] ?? 'continue_autonomous_collector';
        
        Log::channel('ai-engine')->info('AutonomousCollectorHandler processing', [
            'session_id' => $sessionId,
            'action' => $action,
            'message' => substr($message, 0, 100),
        ]);

        // Check if this is starting a new collector
        if ($action === 'start_autonomous_collector') {
            return $this->handleStartCollector($message, $context, $options);
        }

        // Continuing existing collector
        $collectorState = $context->get('autonomous_collector');
        
        if (!$collectorState) {
            Log::channel('ai-engine')->warning('AutonomousCollectorHandler called without active collector');
            return AgentResponse::failure(
                message: 'No active collector session.',
                context: $context
            );
        }

        // Get the config from registry (static registry persists across requests)
        $configName = $collectorState['config_name'] ?? null;
        $config = $configName ? AutonomousCollectorRegistry::getConfig($configName) : null;
        
        // Fallback to service's registered config (for backward compatibility)
        if (!$config && $configName) {
            $config = $this->collectorService->getRegisteredConfig($configName);
        }
        
        if (!$config) {
            Log::channel('ai-engine')->error('Collector config not found', [
                'config_name' => $configName,
                'available_configs' => array_keys(AutonomousCollectorRegistry::getConfigs()),
            ]);
            return AgentResponse::failure(
                message: 'Collector configuration not found.',
                context: $context
            );
        }

        // Process the message through the collector
        $response = $this->processCollectorMessage($sessionId, $message, $config, $context);
        
        // Update context state based on response
        $this->updateContextState($context, $response);
        
        return $response;
    }

    /**
     * Handle starting a new autonomous collector
     */
    protected function handleStartCollector(string $message, UnifiedActionContext $context, array $options = []): AgentResponse
    {
        // First check if match was already found by MessageAnalyzer (avoid duplicate AI call)
        $match = $options['collector_match'] ?? null;
        
        // Fallback to finding config if not passed (shouldn't happen normally)
        if (!$match) {
            $match = \LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::findConfigForMessage($message);
        }
        
        if ($match) {
            Log::channel('ai-engine')->info('Starting autonomous collector from registry', [
                'name' => $match['name'],
                'goal' => $match['config']->goal,
            ]);
            
            return $this->startCollector($context, $match['config'], $message);
        }
        
        Log::channel('ai-engine')->warning('No autonomous collector config found for message', [
            'message' => substr($message, 0, 100),
        ]);
        
        return AgentResponse::conversational(
            message: "I couldn't find a matching collector for your request. Can you please clarify what you'd like to do?",
            context: $context
        );
    }

    /**
     * Process message through the autonomous collector
     */
    protected function processCollectorMessage(
        string $sessionId,
        string $message,
        AutonomousCollectorConfig $config,
        UnifiedActionContext $context
    ): AgentResponse {
        // Get current collector state from context
        $collectorState = $context->get('autonomous_collector', []);
        $status = $collectorState['status'] ?? 'collecting';
        
        // Handle confirmation response
        if ($status === 'confirming') {
            if ($this->isConfirmation($message)) {
                // User confirmed - execute completion
                $collectedData = $collectorState['collected_data'] ?? [];
                
                try {
                    $result = $config->executeOnComplete($collectedData);
                    
                    // Clear collector state
                    $context->forget('autonomous_collector');
                    
                    Log::channel('ai-engine')->info('Autonomous collector completed', [
                        'session_id' => $sessionId,
                        'data' => $collectedData,
                    ]);
                    
                    // Build detailed success message
                    $successMessage = $this->buildSuccessMessage($result, $collectedData, $config);
                    
                    return AgentResponse::success(
                        message: $successMessage,
                        context: $context,
                        data: ['result' => $result, 'collected_data' => $collectedData]
                    );
                    
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->error('Collector completion failed', [
                        'error' => $e->getMessage(),
                    ]);
                    
                    return AgentResponse::failure(
                        message: "Failed to complete: {$e->getMessage()}",
                        context: $context
                    );
                }
            } elseif ($this->isDenial($message)) {
                // User denied - go back to collecting
                $collectorState['status'] = 'collecting';
                $context->set('autonomous_collector', $collectorState);
                
                return AgentResponse::needsUserInput(
                    message: "No problem. What would you like to change?",
                    context: $context
                );
            }
        }
        
        // Handle cancellation
        if ($this->isCancellation($message)) {
            $context->forget('autonomous_collector');
            
            return AgentResponse::success(
                message: 'Collection cancelled.',
                context: $context
            );
        }
        
        // Process through AI with tools
        $aiResponse = $this->generateAIResponse($message, $config, $collectorState, $context);
        
        return $aiResponse;
    }

    /**
     * Generate AI response with tool execution
     */
    protected function generateAIResponse(
        string $message,
        AutonomousCollectorConfig $config,
        array $collectorState,
        UnifiedActionContext $context
    ): AgentResponse {
        $conversation = $collectorState['conversation'] ?? [];
        $toolResults = $collectorState['tool_results'] ?? [];
        
        // Add user message to conversation
        $conversation[] = ['role' => 'user', 'content' => $message];
        
        // Build system prompt
        $systemPrompt = $config->buildSystemPrompt();
        
        // Add tool results context
        if (!empty($toolResults)) {
            $systemPrompt .= "\n\n## Recent Tool Results\n";
            foreach (array_slice($toolResults, -5) as $result) {
                $toolName = $result['tool'] ?? 'unknown';
                $toolResult = $result['result'] ?? $result;
                $systemPrompt .= "- {$toolName}: " . json_encode($toolResult, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        
        // Build conversation prompt
        $conversationPrompt = $this->buildConversationPrompt($conversation);
        
        try {
            $ai = app(\LaravelAIEngine\Services\AIEngineService::class);
            
            // Add tool instructions
            $fullPrompt = $conversationPrompt;
            $tools = $config->getToolDefinitions();
            
            if (!empty($tools)) {
                $fullPrompt .= "\n\n---\nIf you need to use a tool, respond with:\n";
                $fullPrompt .= "```tool\n{\"tool\": \"tool_name\", \"arguments\": {...}}\n```\n";
                $fullPrompt .= "Otherwise, respond naturally to the user.\n";
                $fullPrompt .= "When you have all required information, output the final data as:\n";
                $fullPrompt .= "```json\n{...final output...}\n```";
            }
            
            $response = $ai->generate(
                new \LaravelAIEngine\DTOs\AIRequest(
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
                    return $this->handleToolCall($toolCall, $config, $collectorState, $conversation, $context);
                }
            }
            
            // Check for final output
            $finalOutput = $this->extractFinalOutput($content);
            if ($finalOutput !== null) {
                return $this->handleFinalOutput($finalOutput, $content, $config, $collectorState, $conversation, $context);
            }
            
            // Regular response
            $conversation[] = ['role' => 'assistant', 'content' => $content];
            $collectorState['conversation'] = $conversation;
            $context->set('autonomous_collector', $collectorState);
            
            return AgentResponse::needsUserInput(
                message: $content,
                context: $context
            );
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AI generation failed', ['error' => $e->getMessage()]);
            
            return AgentResponse::failure(
                message: "I encountered an error. Please try again.",
                context: $context
            );
        }
    }

    /**
     * Handle tool call from AI
     */
    protected function handleToolCall(
        array $toolCall,
        AutonomousCollectorConfig $config,
        array $collectorState,
        array $conversation,
        UnifiedActionContext $context
    ): AgentResponse {
        $toolName = $toolCall['tool'];
        $arguments = $toolCall['arguments'] ?? [];
        $toolResults = $collectorState['tool_results'] ?? [];
        
        try {
            $result = $config->executeTool($toolName, $arguments);
            
            // Convert models to arrays
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
            
            Log::channel('ai-engine')->info('Tool executed', [
                'tool' => $toolName,
                'arguments' => $arguments,
            ]);
            
        } catch (\Exception $e) {
            $toolResults[] = [
                'tool' => $toolName,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'success' => false,
            ];
        }
        
        // Add tool result to conversation
        $resultStr = json_encode(end($toolResults)['result'] ?? end($toolResults)['error'], JSON_UNESCAPED_UNICODE);
        $conversation[] = [
            'role' => 'system',
            'content' => "Tool {$toolName} result: " . substr($resultStr, 0, 500),
        ];
        
        $collectorState['conversation'] = $conversation;
        $collectorState['tool_results'] = $toolResults;
        $context->set('autonomous_collector', $collectorState);
        
        // Continue processing with tool results
        return $this->generateAIResponse('', $config, $collectorState, $context);
    }

    /**
     * Handle final output from AI
     */
    protected function handleFinalOutput(
        array $finalOutput,
        string $aiMessage,
        AutonomousCollectorConfig $config,
        array $collectorState,
        array $conversation,
        UnifiedActionContext $context
    ): AgentResponse {
        // Validate output
        $errors = $config->validateOutput($finalOutput);
        
        if (!empty($errors)) {
            $conversation[] = [
                'role' => 'system',
                'content' => "Output validation failed: " . implode(', ', $errors),
            ];
            $collectorState['conversation'] = $conversation;
            $context->set('autonomous_collector', $collectorState);
            
            return $this->generateAIResponse('', $config, $collectorState, $context);
        }
        
        // Store collected data
        $collectorState['collected_data'] = $finalOutput;
        $collectorState['status'] = $config->confirmBeforeComplete ? 'confirming' : 'completed';
        
        // Clean message
        $cleanMessage = preg_replace('/```json\s*\n?.*?\n?```/s', '', $aiMessage);
        $cleanMessage = trim($cleanMessage);
        
        if ($config->confirmBeforeComplete) {
            $summary = $this->generateSummary($finalOutput);
            $confirmMessage = $cleanMessage ?: "I've collected all the information.";
            $confirmMessage .= "\n\n**Summary:**\n" . $summary;
            $confirmMessage .= "\n\nShall I proceed? (yes/no)";
            
            $conversation[] = ['role' => 'assistant', 'content' => $confirmMessage];
            $collectorState['conversation'] = $conversation;
            $context->set('autonomous_collector', $collectorState);
            
            return AgentResponse::needsUserInput(
                message: $confirmMessage,
                context: $context,
                data: ['collected_data' => $finalOutput, 'requires_confirmation' => true]
            );
        }
        
        // Execute completion directly
        try {
            $result = $config->executeOnComplete($finalOutput);
            $context->forget('autonomous_collector');
            
            return AgentResponse::success(
                message: '✅ Successfully completed!',
                context: $context,
                data: ['result' => $result]
            );
        } catch (\Exception $e) {
            return AgentResponse::failure(
                message: "Failed: {$e->getMessage()}",
                context: $context
            );
        }
    }

    /**
     * Extract final JSON output from AI message
     */
    protected function extractFinalOutput(string $message): ?array
    {
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
     * Generate summary of collected data
     */
    protected function generateSummary(array $data, int $depth = 0): string
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (isset($value[0])) {
                    $lines[] = "{$indent}- **{$key}**: " . count($value) . " item(s)";
                    foreach ($value as $item) {
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
     * Build conversation prompt
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
     * Update context state based on response
     */
    protected function updateContextState(UnifiedActionContext $context, AgentResponse $response): void
    {
        // Context is already updated in the processing methods
    }

    /**
     * Check if message is confirmation
     */
    protected function isConfirmation(string $message): bool
    {
        $message = strtolower(trim($message));
        return in_array($message, ['yes', 'y', 'confirm', 'proceed', 'ok', 'sure', 'go ahead', 'do it']);
    }

    /**
     * Check if message is denial
     */
    protected function isDenial(string $message): bool
    {
        $message = strtolower(trim($message));
        return in_array($message, ['no', 'n', 'cancel', 'change', 'modify', 'edit']);
    }

    /**
     * Check if message is cancellation
     */
    protected function isCancellation(string $message): bool
    {
        $message = strtolower(trim($message));
        return in_array($message, ['cancel', 'stop', 'quit', 'exit', 'nevermind', 'never mind']);
    }

    public function canHandle(string $action): bool
    {
        return in_array($action, ['continue_autonomous_collector', 'start_autonomous_collector']);
    }

    /**
     * Build detailed success message from completion result
     */
    protected function buildSuccessMessage(mixed $result, array $collectedData, AutonomousCollectorConfig $config): string
    {
        // If result contains a custom message, use it
        if (is_array($result) && isset($result['message'])) {
            return $result['message'];
        }

        // Build a detailed success message
        $message = "✅ **{$config->goal} - Completed Successfully!**\n\n";

        // Add result details if available
        if (is_array($result)) {
            if (isset($result['invoice_number']) || isset($result['invoice_id'])) {
                $invoiceNum = $result['invoice_number'] ?? $result['invoice_id'] ?? 'N/A';
                $message .= "**Invoice #:** {$invoiceNum}\n";
            }
            
            if (isset($result['customer'])) {
                $message .= "**Customer:** {$result['customer']}\n";
            }
            
            if (isset($result['total'])) {
                $message .= "**Total:** \${$result['total']}\n";
            }
            
            if (isset($result['id'])) {
                $message .= "**Record ID:** {$result['id']}\n";
            }
        }

        // Add items summary from collected data
        if (isset($collectedData['items']) && is_array($collectedData['items'])) {
            $itemCount = count($collectedData['items']);
            $message .= "\n**Items ({$itemCount}):**\n";
            
            foreach ($collectedData['items'] as $item) {
                $name = $item['name'] ?? 'Unknown';
                $qty = $item['quantity'] ?? 1;
                $price = $item['unit_price'] ?? $item['price'] ?? 0;
                $total = $item['total'] ?? ($qty * $price);
                $message .= "• {$name} × {$qty} @ \${$price} = \${$total}\n";
            }
        }

        // Add totals from collected data
        if (isset($collectedData['subtotal'])) {
            $message .= "\n**Subtotal:** \${$collectedData['subtotal']}";
        }
        if (isset($collectedData['tax']) && $collectedData['tax'] > 0) {
            $message .= "\n**Tax:** \${$collectedData['tax']}";
        }
        if (isset($collectedData['total'])) {
            $message .= "\n**Total:** \${$collectedData['total']}";
        }

        return $message;
    }

    /**
     * Start a new autonomous collector session
     */
    public function startCollector(
        UnifiedActionContext $context,
        AutonomousCollectorConfig $config,
        string $initialMessage = ''
    ): AgentResponse {
        // Register config for later retrieval
        $this->collectorService->registerConfig($config);
        
        // Initialize collector state in context
        $collectorState = [
            'config_name' => $config->name,
            'status' => 'collecting',
            'conversation' => [],
            'collected_data' => [],
            'tool_results' => [],
            'started_at' => now()->toIso8601String(),
        ];
        
        $context->set('autonomous_collector', $collectorState);
        
        Log::channel('ai-engine')->info('Autonomous collector started', [
            'session_id' => $context->sessionId,
            'config_name' => $config->name,
            'goal' => $config->goal,
        ]);
        
        // Process initial message if provided
        if ($initialMessage) {
            return $this->handle($initialMessage, $context);
        }
        
        // Generate greeting
        $greeting = "Hello! I'll help you {$config->goal}. What would you like to do?";
        $collectorState['conversation'][] = ['role' => 'assistant', 'content' => $greeting];
        $context->set('autonomous_collector', $collectorState);
        
        return AgentResponse::needsUserInput(
            message: $greeting,
            context: $context
        );
    }
}
