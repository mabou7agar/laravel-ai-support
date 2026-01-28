<?php

namespace LaravelAIEngine\Services\DataCollector;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AIRequest;
use Illuminate\Support\Facades\Log;

/**
 * Registry for Autonomous Collector Configs
 * 
 * Apps can register their configs here to enable auto-detection.
 * Detection is AI-driven based on the config's goal, not simple keywords.
 * 
 * Usage in your app's ServiceProvider:
 * ```php
 * use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
 * 
 * public function boot()
 * {
 *     AutonomousCollectorRegistry::register('invoice', [
 *         'config' => fn() => InvoiceAutonomousConfig::create(),
 *         'description' => 'Create a sales invoice with customer and products',
 *     ]);
 * }
 * ```
 */
class AutonomousCollectorRegistry
{
    protected static array $configs = [];

    /**
     * Register an autonomous collector config
     */
    public static function register(string $name, array $configData): void
    {
        static::$configs[$name] = $configData;
    }

    /**
     * Get all registered configs
     */
    public static function getConfigs(): array
    {
        return static::$configs;
    }

    /**
     * Find matching config for a message using AI
     * 
     * AI analyzes the message against registered config goals/descriptions
     * to determine if any collector should handle it.
     */
    public static function findConfigForMessage(string $message): ?array
    {
        if (empty(static::$configs)) {
            return null;
        }

        // Build context of available collectors for AI
        $collectorsContext = [];
        foreach (static::$configs as $name => $configData) {
            $config = $configData['config'];
            if ($config instanceof \Closure) {
                $config = $config();
            }
            
            $collectorsContext[$name] = [
                'goal' => $config->goal ?? '',
                'description' => $configData['description'] ?? $config->description ?? '',
            ];
        }

        // Use AI to determine if message matches any collector
        try {
            $match = static::detectWithAI($message, $collectorsContext);
            
            if ($match) {
                $configData = static::$configs[$match];
                $configInstance = $configData['config'];
                
                if ($configInstance instanceof \Closure) {
                    $configInstance = $configInstance();
                }
                
                Log::channel('ai-engine')->info('AI matched autonomous collector', [
                    'message' => substr($message, 0, 100),
                    'matched_collector' => $match,
                ]);
                
                return [
                    'name' => $match,
                    'config' => $configInstance,
                    'description' => $configData['description'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI collector detection failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return null;
    }

    /**
     * Use AI to detect if message should trigger a collector
     */
    protected static function detectWithAI(string $message, array $collectors): ?string
    {
        if (empty($collectors)) {
            return null;
        }

        $ai = app(\LaravelAIEngine\Services\AIEngineService::class);
        
        // Build prompt
        $prompt = "User message: \"{$message}\"\n\n";
        $prompt .= "Available data collectors:\n";
        
        $index = 1;
        $indexMap = [];
        foreach ($collectors as $name => $info) {
            $prompt .= "{$index}. {$info['goal']}";
            if (!empty($info['description'])) {
                $prompt .= " - {$info['description']}";
            }
            $prompt .= "\n";
            $indexMap[$index] = $name;
            $index++;
        }
        
        // Get detection prompt from config (app can customize)
        $detectionPrompt = config('ai-engine.autonomous_collector.detection_prompt') ?? static::getDefaultDetectionPrompt();
        $prompt .= "\n" . str_replace('{count}', (string) count($collectors), $detectionPrompt);

        $response = $ai->generate(new AIRequest(
            prompt: $prompt,
            maxTokens: 5,
            temperature: 0
        ));

        $result = trim($response->getContent());
        $selectedNumber = (int) preg_replace('/[^0-9]/', '', $result);
        
        Log::channel('ai-engine')->debug('AI collector detection result', [
            'message' => substr($message, 0, 50),
            'ai_response' => $result,
            'selected_number' => $selectedNumber,
        ]);

        if ($selectedNumber > 0 && isset($indexMap[$selectedNumber])) {
            return $indexMap[$selectedNumber];
        }

        return null;
    }

    /**
     * Get default detection prompt (can be overridden via config)
     */
    protected static function getDefaultDetectionPrompt(): string
    {
        return <<<PROMPT
Does the user want to CREATE/MAKE/ADD a NEW item that matches one of these collectors?

MATCH ONLY IF user explicitly wants to CREATE something NEW:
- "create invoice for John" → MATCH (creating new invoice)
- "make a bill" → MATCH (creating new bill)
- "add new customer" → MATCH (creating new customer)

DO NOT MATCH for these (respond with 0):
- "invoices at 26-01-2026" → NO (searching/filtering existing data)
- "show my invoices" → NO (listing existing data)
- "list bills from January" → NO (searching existing data)
- "how many invoices" → NO (counting existing data)
- "find invoice #123" → NO (searching existing data)
- Any query with dates, filters, or search terms → NO (searching, not creating)

Respond with ONLY the number (1-{count}) or '0' if no match.
PROMPT;
    }

    /**
     * Get config by name
     */
    public static function getConfig(string $name): ?AutonomousCollectorConfig
    {
        if (!isset(static::$configs[$name])) {
            return null;
        }
        
        $config = static::$configs[$name]['config'];
        
        if ($config instanceof \Closure) {
            return $config();
        }
        
        return $config;
    }

    /**
     * Check if a config exists
     */
    public static function has(string $name): bool
    {
        return isset(static::$configs[$name]);
    }

    /**
     * Clear all registered configs (useful for testing)
     */
    public static function clear(): void
    {
        static::$configs = [];
    }

    /**
     * Get collector goals for node advertisement
     * 
     * Returns array of collector info that can be stored in AINode.autonomous_collectors
     * for cross-node routing decisions.
     */
    public static function getCollectorGoals(): array
    {
        $goals = [];
        
        foreach (static::$configs as $name => $configData) {
            $config = $configData['config'];
            if ($config instanceof \Closure) {
                $config = $config();
            }
            
            $goals[] = [
                'name' => $name,
                'goal' => $config->goal ?? '',
                'description' => $configData['description'] ?? $config->description ?? '',
            ];
        }
        
        return $goals;
    }
}
