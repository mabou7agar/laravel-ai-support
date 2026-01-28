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
     * 
     * @param string $message User message
     * @param int|null $userId User ID for permission checking
     */
    public static function findConfigForMessage(string $message, ?int $userId = null): ?array
    {
        if (empty(static::$configs)) {
            return null;
        }

        // Build context of available collectors for AI (only those user has permission for)
        $collectorsContext = [];
        foreach (static::$configs as $name => $configData) {
            // Check permissions using getAllowedOperations
            $configClass = $configData['class'] ?? null;
            if ($configClass && method_exists($configClass, 'getAllowedOperations')) {
                $allowedOps = $configClass::getAllowedOperations($userId);
                
                // Determine required operation from collector name
                $requiredOp = static::getRequiredOperation($name);
                if (!in_array($requiredOp, $allowedOps)) {
                    Log::channel('ai-engine')->debug('Skipping collector due to permissions', [
                        'collector' => $name,
                        'required_op' => $requiredOp,
                        'allowed_ops' => $allowedOps,
                        'user_id' => $userId,
                    ]);
                    continue; // Skip this collector - user doesn't have permission
                }
            }
            
            $config = $configData['config'];
            if ($config instanceof \Closure) {
                $config = $config();
            }
            
            $collectorsContext[$name] = [
                'goal' => $config->goal ?? '',
                'description' => $configData['description'] ?? $config->description ?? '',
            ];
        }
        
        if (empty($collectorsContext)) {
            return null; // No collectors available for this user
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
     * Get required operation from collector name
     */
    protected static function getRequiredOperation(string $name): string
    {
        if (str_contains($name, '_delete')) {
            return 'delete';
        }
        if (str_contains($name, '_update')) {
            return 'update';
        }
        // Default is create for base collectors like 'invoice', 'bill', etc.
        return 'create';
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
        
        // Build prompt with collectors list
        $prompt = "User message: \"{$message}\"\n\n";
        $prompt .= "Available data collectors:\n";
        
        $index = 1;
        $indexMap = [];
        $examples = [];
        foreach ($collectors as $name => $info) {
            $prompt .= "{$index}. {$info['goal']}";
            if (!empty($info['description'])) {
                $prompt .= " - {$info['description']}";
            }
            $prompt .= "\n";
            $indexMap[$index] = $name;
            // Build dynamic examples from collector names
            $examples[] = "\"create {$name}\" → {$index}";
            $index++;
        }
        
        // Build detection prompt dynamically
        $detectionPrompt = static::buildDetectionPrompt($examples, count($collectors));
        $prompt .= "\n" . $detectionPrompt;

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
     * Build detection prompt dynamically from discovered collectors
     */
    protected static function buildDetectionPrompt(array $examples, int $count): string
    {
        $examplesText = implode("\n", array_map(fn($e) => "- {$e}", $examples));
        
        return <<<PROMPT
Which collector matches the user's CREATE intent? Reply with the number only.

Examples:
{$examplesText}

Reply 0 if user is SEARCHING/LISTING/COUNTING (not creating).

Number (1-{$count}) or 0:
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
