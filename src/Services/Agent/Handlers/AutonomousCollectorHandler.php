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

        // Detect if user is asking an unrelated query (e.g., "list invoices" during invoice creation)
        // This prevents the collector from staying active when user wants to do something else
        if ($this->isUnrelatedQuery($message, $config)) {
            Log::channel('ai-engine')->info('Unrelated query detected - exiting collector', [
                'message' => $message,
                'collector' => $config->name,
            ]);

            $context->forget('autonomous_collector');

            // Return a special response that tells the orchestrator to re-route this message
            return AgentResponse::failure(
                message: 'exit_and_reroute',
                context: $context,
                data: ['reroute_message' => $message]
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

        // Add prior conversation context from before collector started
        // This allows the collector to see what was discussed earlier (e.g., which invoice was shown)
        $priorHistory = $context->conversationHistory;
        if (!empty($priorHistory)) {
            $systemPrompt .= "\n\n## Prior Conversation Context\n";
            $systemPrompt .= "The following conversation happened before this task started:\n";
            foreach (array_slice($priorHistory, -10) as $msg) {
                $role = ucfirst($msg['role'] ?? 'unknown');
                $content = $msg['content'] ?? '';
                $systemPrompt .= "**{$role}:** " . substr($content, 0, 500) . "\n";
            }
            $systemPrompt .= "\nUse this context to understand what the user is referring to (e.g., invoice IDs, customer names, etc.).\n";
        }

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

            // Extract required inputs from AI response for UI form generation
            $requiredInputs = $this->extractRequiredInputs($content, $config);

            return AgentResponse::needsUserInput(
                message: $content,
                context: $context,
                requiredInputs: $requiredInputs
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

        // Clean message - remove JSON blocks and verbose explanations
        $cleanMessage = preg_replace('/```json\s*\n?.*?\n?```/s', '', $aiMessage);
        $cleanMessage = preg_replace('/Here is the JSON representation.*$/si', '', $cleanMessage);
        $cleanMessage = preg_replace('/Here\'s the final.*?:\s*/si', '', $cleanMessage);
        $cleanMessage = trim($cleanMessage);

        if ($config->confirmBeforeComplete) {
            $summary = $this->generateSummary($finalOutput);

            // Build structured confirmation message
            $confirmMessage = "📋 **Please Review:**\n\n";
            $confirmMessage .= $summary;
            $confirmMessage .= "\n\n";
            $confirmMessage .= "---\n";
            $confirmMessage .= "✅ Confirm to proceed | ❌ Cancel\n";
            $confirmMessage .= "Type: **yes** or **no**";

            $conversation[] = ['role' => 'assistant', 'content' => $confirmMessage];
            $collectorState['conversation'] = $conversation;
            $context->set('autonomous_collector', $collectorState);

            return AgentResponse::needsUserInput(
                message: $confirmMessage,
                data:    ['collected_data' => $finalOutput, 'requires_confirmation' => true],
                context: $context
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

        // Fetch entity details for user-friendly display
        $entityDetails = $this->fetchEntityDetails($data);

        // Group fields by category for better organization
        $entities = [];
        $changes = [];
        $items = [];

        foreach ($data as $key => $value) {
            // Skip internal fields (prefixed with _)
            if (str_starts_with($key, '_')) {
                continue;
            }

            // Categorize fields
            if (str_ends_with($key, '_id') && isset($entityDetails[$key])) {
                $entities[$key] = $entityDetails[$key];
            } elseif (is_array($value) && isset($value[0])) {
                $items[$key] = $value;
            } else {
                $changes[$key] = $value;
            }
        }

        // Show entity details first (customer, invoice, etc.)
        foreach ($entities as $key => $details) {
            $entityType = str_replace('_id', '', $key);
            $icon = $this->getEntityIcon($entityType);
            $lines[] = "{$indent}{$icon} **" . ucwords(str_replace('_', ' ', $entityType)) . "**:";
            foreach ($details as $field => $value) {
                $lines[] = "{$indent}  • **{$field}**: {$value}";
            }
        }

        // Show changes
        foreach ($changes as $key => $value) {
            $label = $this->formatFieldLabel($key);
            if (is_array($value)) {
                $lines[] = "{$indent}📝 **{$label}**:";
                $lines[] = $this->generateSummary($value, $depth + 1);
            } else {
                $lines[] = "{$indent}📝 **{$label}**: {$value}";
            }
        }

        // Show items/arrays
        foreach ($items as $key => $value) {
            $label = $this->formatFieldLabel($key);
            $lines[] = "{$indent}📦 **{$label}**: " . count($value) . " item(s)";
            foreach ($value as $item) {
                if (is_array($item)) {
                    $itemSummary = [];
                    foreach ($item as $k => $v) {
                        if (!is_array($v) && !str_starts_with($k, '_')) {
                            $itemLabel = $this->formatFieldLabel($k);
                            $itemSummary[] = "{$itemLabel}: {$v}";
                        }
                    }
                    $lines[] = "{$indent}  • " . implode(', ', $itemSummary);
                } else {
                    $lines[] = "{$indent}  • {$item}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Fetch entity details for user-friendly display
     * Uses collector config's entity resolvers if available
     */
    protected function fetchEntityDetails(array $data): array
    {
        $details = [];
        $config = $this->getCollectorConfig();

        if (!$config) {
            return $details;
        }

        // Get entity resolvers from config
        $entityResolvers = $config->entityResolvers ?? [];

        foreach ($data as $key => $value) {
            // Check if this is an ID field with a resolver
            if (str_ends_with($key, '_id') && isset($entityResolvers[$key])) {
                try {
                    $resolver = $entityResolvers[$key];
                    
                    // Call the resolver to get entity details
                    if (is_callable($resolver)) {
                        $entityData = $resolver($value);
                        if ($entityData && is_array($entityData)) {
                            $details[$key] = $entityData;
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Entity resolver failed', [
                        'field' => $key,
                        'value' => $value,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $details;
    }

    /**
     * Get collector config from context
     * Note: This method is not currently used but kept for potential future use
     */
    protected function getCollectorConfig(): ?AutonomousCollectorConfig
    {
        // This method would need a context parameter to work
        // For now, return null as it's not actively used
        return null;
    }

    /**
     * Get icon for entity type
     */
    protected function getEntityIcon(string $entityType): string
    {
        return match($entityType) {
            'customer', 'customer_user' => '👤',
            'invoice' => '📄',
            'product' => '📦',
            'order' => '🛒',
            default => '🔖',
        };
    }

    /**
     * Format field name to human-readable label
     */
    protected function formatFieldLabel(string $field): string
    {
        // Convert snake_case to Title Case
        return ucwords(str_replace('_', ' ', $field));
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

    /**
     * Check if message is an unrelated query that should exit the collector
     */
    protected function isUnrelatedQuery(string $message, AutonomousCollectorConfig $config): bool
    {
        $messageLower = strtolower(trim($message));

        // Common query patterns that indicate user wants to do something else
        $queryPatterns = [
            'list ',
            'show ',
            'get ',
            'find ',
            'search ',
            'display ',
            'view ',
            'what are ',
            'how many ',
            'count ',
        ];

        foreach ($queryPatterns as $pattern) {
            if (str_starts_with($messageLower, $pattern)) {
                // Check if the query is about the same entity we're collecting
                // e.g., "list invoices" during invoice creation should exit
                // but "list products" during invoice creation might be relevant
                $collectorName = $config->name;

                // If query mentions the same entity type, it's likely unrelated
                // (user wants to see existing items, not continue creating)
                if (str_contains($messageLower, $collectorName)) {
                    return true;
                }

                // Also exit for clearly unrelated entities
                $unrelatedEntities = ['invoice', 'bill', 'customer', 'vendor', 'product', 'order', 'payment'];
                foreach ($unrelatedEntities as $entity) {
                    if ($entity !== $collectorName && str_contains($messageLower, $entity)) {
                        return true;
                    }
                }
            }
        }

        return false;
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
     * Extract required inputs from AI response for UI form generation
     *
     * Analyzes the AI's response to detect what inputs are being requested
     * and returns structured input definitions for the UI to render forms.
     */
    protected function extractRequiredInputs(string $content, AutonomousCollectorConfig $config): ?array
    {
        $inputs = [];
        $contentLower = strtolower($content);

        // Check for confirmation requests (yes/no)
        $confirmationPatterns = [
            'is this correct',
            'shall i proceed',
            'would you like to',
            'do you want to',
            'proceed?',
            '(yes/no)',
        ];

        $isConfirmation = false;
        foreach ($confirmationPatterns as $pattern) {
            if (str_contains($contentLower, $pattern)) {
                $isConfirmation = true;
                break;
            }
        }

        if ($isConfirmation) {
            $inputs[] = [
                'name' => 'confirmation',
                'type' => 'confirm',
                'label' => 'Confirm',
                'required' => true,
                'options' => [
                    ['value' => 'yes', 'label' => 'Yes'],
                    ['value' => 'no', 'label' => 'No'],
                ],
            ];
        }

        // Extract structured data from various formats
        $extractedData = [];

        // Format 1: Markdown list items: - **Label:** Value or - **Label**: Value or **Label:** Value
        if (preg_match_all('/[-•]?\s*\*\*([^*]+)\*\*:?\s*([^\n]+)/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim($match[1]);
                $value = trim($match[2]);
                $key = strtolower(str_replace([' ', '-'], '_', $label));
                $value = preg_replace('/\*\*$/', '', $value);
                $value = trim($value, ' .,');

                if (!empty($value) && strlen($value) < 100) {
                    $extractedData[$key] = ['label' => $label, 'value' => $value];
                }
            }
        }

        // Format 2: Simple list items or plain lines: - Label: Value or Label: Value
        if (preg_match_all('/^[-•]?\s*([A-Za-z][A-Za-z\s]{1,20}):\s*(.+?)(?:\s{2,}|$)/im', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim($match[1]);
                $value = trim($match[2]);
                $key = strtolower(str_replace([' ', '-'], '_', $label));
                $value = trim($value, ' .,*');

                // Skip if already captured, too long, or looks like a sentence
                if (!isset($extractedData[$key]) && !empty($value) && strlen($value) < 50 && !str_contains($value, ' is ')) {
                    $extractedData[$key] = ['label' => $label, 'value' => $value];
                }
            }
        }

        // Check for category in extracted data or content
        if (isset($extractedData['category'])) {
            $inputs[] = [
                'name' => 'category',
                'type' => 'text',
                'label' => 'Category',
                'required' => false,
                'default' => $extractedData['category']['value'],
                'placeholder' => 'Enter category or keep suggested',
            ];
            unset($extractedData['category']);
        }

        // Check for price in extracted data
        if (isset($extractedData['price'])) {
            $priceValue = preg_replace('/[^\d.]/', '', $extractedData['price']['value']);
            $inputs[] = [
                'name' => 'price',
                'type' => 'number',
                'label' => 'Price',
                'required' => true,
                'default' => $priceValue,
                'placeholder' => 'Enter price',
            ];
            unset($extractedData['price']);
        }

        // Check for email input requests
        if (str_contains($contentLower, 'email') && (str_contains($contentLower, 'provide') || str_contains($contentLower, 'enter') || str_contains($contentLower, 'what is'))) {
            $inputs[] = [
                'name' => 'email',
                'type' => 'email',
                'label' => 'Email Address',
                'required' => true,
                'placeholder' => 'Enter email address',
            ];
        }

        // Check for account code input
        if (str_contains($contentLower, 'account code') && (str_contains($contentLower, 'specify') || str_contains($contentLower, 'provide'))) {
            $inputs[] = [
                'name' => 'code',
                'type' => 'text',
                'label' => 'Account Code',
                'required' => false,
                'placeholder' => 'Enter account code (optional)',
            ];
        }

        // Check for selection from options (e.g., "use existing or create new")
        if ((str_contains($contentLower, 'use this existing') || str_contains($contentLower, 'use existing'))
            && str_contains($contentLower, 'create new')) {
            $inputs[] = [
                'name' => 'selection',
                'type' => 'select',
                'label' => 'Choose an option',
                'required' => true,
                'options' => [
                    ['value' => 'use_existing', 'label' => 'Use Existing'],
                    ['value' => 'create_new', 'label' => 'Create New'],
                ],
            ];
        }

        // Add remaining extracted data as readonly fields for display
        foreach ($extractedData as $key => $data) {
            $inputs[] = [
                'name' => $key,
                'type' => 'readonly',
                'label' => $data['label'],
                'value' => $data['value'],
            ];
        }

        return !empty($inputs) ? $inputs : null;
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
