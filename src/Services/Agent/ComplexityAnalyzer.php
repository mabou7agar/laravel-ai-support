<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ComplexityAnalyzer
{
    protected array $discoveredModels = [];
    
    public function __construct(
        protected AIEngineService $ai
    ) {
        $this->loadDiscoveredModels();
    }
    
    /**
     * Load discovered models from cache
     */
    protected function loadDiscoveredModels(): void
    {
        $this->discoveredModels = Cache::get('agent_discovered_models', []);
        
        if (!empty($this->discoveredModels)) {
            Log::channel('ai-engine')->debug('Loaded discovered models for complexity analysis', [
                'count' => count($this->discoveredModels),
            ]);
        }
    }

    public function analyze(string $message, UnifiedActionContext $context): array
    {
        $cacheKey = $this->getCacheKey($message, $context);
        
        if (config('ai-agent.cache.enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                Log::channel('ai-engine')->debug('Complexity analysis cache hit', [
                    'cache_key' => $cacheKey,
                ]);
                return $cached;
            }
        }
        
        $analysis = $this->performAnalysis($message, $context);
        
        if (config('ai-agent.cache.enabled', true)) {
            Cache::put($cacheKey, $analysis, config('ai-agent.cache.ttl', 300));
        }
        
        return $analysis;
    }

    protected function performAnalysis(string $message, UnifiedActionContext $context): array
    {
        $prompt = $this->buildAnalysisPrompt($message, $context);
        
        $request = new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from('openai'),
            model: EntityEnum::from('gpt-4o-mini'),
            maxTokens: 300,
            temperature: 0,
            metadata: ['purpose' => 'complexity_analysis']
        );
        
        try {
            $response = $this->ai->generate($request);
            $analysis = $this->parseAnalysisResponse($response->content);
            
            Log::channel('ai-engine')->info('Complexity analysis completed', [
                'complexity' => $analysis['complexity'],
                'strategy' => $analysis['suggested_strategy'],
                'confidence' => $analysis['confidence'],
            ]);
            
            return $analysis;
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Complexity analysis failed', [
                'error' => $e->getMessage(),
            ]);
            
            return $this->getDefaultAnalysis();
        }
    }

    protected function buildAnalysisPrompt(string $message, UnifiedActionContext $context): string
    {
        $prompt = "Analyze this user request and determine the best execution strategy.\n\n";
        $prompt .= "User Message: \"{$message}\"\n\n";
        
        if (!empty($context->conversationHistory)) {
            $prompt .= "Recent Conversation:\n";
            foreach (array_slice($context->conversationHistory, -3) as $msg) {
                $prompt .= "- {$msg['role']}: {$msg['content']}\n";
            }
            $prompt .= "\n";
        }
        
        if ($context->pendingAction) {
            $prompt .= "Pending Action: {$context->pendingAction['label']}\n";
            $missingFields = $context->pendingAction['missing_fields'] ?? [];
            if (!empty($missingFields)) {
                $prompt .= "Missing Fields: " . implode(', ', $missingFields) . "\n";
            }
            $prompt .= "\n";
        }
        
        if ($context->currentWorkflow) {
            $prompt .= "Current Workflow: {$context->currentWorkflow}\n";
            $prompt .= "Current Step: {$context->currentStep}\n\n";
        }
        
        $prompt .= "Analyze:\n";
        $prompt .= "1. What is the user trying to do?\n";
        $prompt .= "2. How much data did they provide?\n";
        $prompt .= "3. How many fields are missing?\n";
        $prompt .= "4. How complex is this request?\n";
        $prompt .= "5. Does this require multiple steps or conditional logic?\n";
        $prompt .= "6. Will this require validation of related entities?\n";
        $prompt .= "7. Will this require creating dependent entities?\n\n";
        
        $prompt .= "Complexity Levels:\n";
        $prompt .= "- SIMPLE: All data provided, can execute immediately, no dependencies\n";
        $prompt .= "  Examples:\n";
        $prompt .= "  • 'Create post titled Hello World'\n";
        $prompt .= "  • 'Delete user 123'\n";
        $prompt .= "  • 'Update product price to 99'\n\n";
        
        $prompt .= "- MEDIUM: Some data provided, needs 2-5 more fields, simple validation\n";
        $prompt .= "  Examples:\n";
        $prompt .= "  • 'Create a course' (needs name, description, duration)\n";
        $prompt .= "  • 'Add new category' (needs name, description)\n";
        $prompt .= "  • 'Create user account' (needs email, name, password)\n\n";
        
        $prompt .= "- HIGH: Complex multi-step request with conditional logic, entity validation, or dependent creation\n";
        $prompt .= "  IMPORTANT: These requests require agent_mode:\n";
        $prompt .= "  Examples:\n";
        $prompt .= "  • 'Create invoice' - Must validate customer, products, prices, quantities\n";
        $prompt .= "  • 'Create order' - Must check inventory, validate products, calculate totals\n";
        $prompt .= "  • 'Create invoice with product X' - Must check if product exists, create if not, validate category\n";
        $prompt .= "  • 'Make purchase order' - Must validate supplier, products, prices\n";
        $prompt .= "  • 'Create bill' - Must validate items, calculate totals, check accounts\n";
        $prompt .= "  • Any request involving 'invoice', 'order', 'purchase', 'bill', 'transaction'\n";
        $prompt .= "  • Any request that says 'check if X exists' or 'create X if not exists'\n";
        $prompt .= "  • Any request requiring multiple confirmations\n";
        $prompt .= "  • Any request involving price calculations or inventory checks\n\n";
        
        $prompt .= "Strategies:\n";
        $prompt .= "- quick_action: Execute immediately with provided data (SIMPLE only)\n";
        $prompt .= "- guided_flow: Step-by-step data collection (MEDIUM only)\n";
        $prompt .= "- agent_mode: Multi-step reasoning with conditional logic (HIGH only - REQUIRED for invoices, orders, bills)\n";
        $prompt .= "- conversational: Just chatting, no action needed\n\n";
        
        $prompt .= "CRITICAL RULES:\n";
        $prompt .= "1. If message contains 'invoice', 'order', 'purchase', 'bill' → complexity MUST be HIGH → strategy MUST be agent_mode\n";
        $prompt .= "2. If request requires checking if entities exist → complexity MUST be HIGH → strategy MUST be agent_mode\n";
        $prompt .= "3. If request requires creating dependent entities → complexity MUST be HIGH → strategy MUST be agent_mode\n";
        $prompt .= "4. If request involves multiple validations → complexity MUST be HIGH → strategy MUST be agent_mode\n\n";
        
        // Add discovered models to prompt
        if (!empty($this->discoveredModels)) {
            $prompt .= "DISCOVERED MODELS IN THIS APPLICATION:\n";
            $prompt .= "The following models are available with their complexity pre-analyzed:\n\n";
            
            foreach ($this->discoveredModels as $model) {
                $prompt .= "• {$model['display_name']} ({$model['complexity']} complexity)\n";
                $prompt .= "  Description: {$model['description']}\n";
                $prompt .= "  Relationships: {$model['relationship_count']}\n";
                $prompt .= "  Strategy: {$model['strategy']}\n";
                $prompt .= "  Keywords: " . implode(', ', array_slice($model['keywords'], 0, 3)) . "\n";
                
                if ($model['complexity'] === 'HIGH') {
                    $prompt .= "  ⚠️ MUST use agent_mode (has relationships requiring validation)\n";
                }
                
                $prompt .= "\n";
            }
            
            $prompt .= "When user mentions any of these models or their keywords, use the pre-analyzed complexity and strategy.\n\n";
        }
        
        $prompt .= "Respond in JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"complexity\": \"simple|medium|high\",\n";
        $prompt .= "  \"intent\": \"create|modify|delete|query|chat\",\n";
        $prompt .= "  \"data_completeness\": 0.0-1.0,\n";
        $prompt .= "  \"missing_fields_count\": 0-10,\n";
        $prompt .= "  \"needs_guidance\": true|false,\n";
        $prompt .= "  \"requires_conditional_logic\": true|false,\n";
        $prompt .= "  \"suggested_strategy\": \"quick_action|guided_flow|agent_mode|conversational\",\n";
        $prompt .= "  \"confidence\": 0.0-1.0,\n";
        $prompt .= "  \"reasoning\": \"Brief explanation\"\n";
        $prompt .= "}";
        
        return $prompt;
    }

    protected function parseAnalysisResponse(string $content): array
    {
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            
            if ($json) {
                return array_merge($this->getDefaultAnalysis(), $json);
            }
        }
        
        return $this->getDefaultAnalysis();
    }

    protected function getDefaultAnalysis(): array
    {
        return [
            'complexity' => 'medium',
            'intent' => 'chat',
            'data_completeness' => 0.5,
            'missing_fields_count' => 0,
            'needs_guidance' => false,
            'requires_conditional_logic' => false,
            'suggested_strategy' => config('ai-agent.default_strategy', 'conversational'),
            'confidence' => 0.3,
            'reasoning' => 'Default analysis (AI analysis unavailable)',
        ];
    }

    protected function getCacheKey(string $message, UnifiedActionContext $context): string
    {
        $contextHash = md5(json_encode([
            'pending_action' => $context->pendingAction,
            'current_strategy' => $context->currentStrategy,
            'current_workflow' => $context->currentWorkflow,
            'history_count' => count($context->conversationHistory),
        ]));
        
        return "complexity_analysis:" . md5($message) . ":{$contextHash}";
    }
}
