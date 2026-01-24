<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent Entity Service - AI-Powered Entity Resolution Enhancements
 * 
 * Provides advanced AI capabilities for entity resolution:
 * - Natural language data extraction
 * - Smart field inference
 * - Entity relationship detection
 * - Context-aware prompts
 * - Smart search field selection
 */
class IntelligentEntityService
{
    protected $ai;
    
    public function __construct(AIEngineService $ai)
    {
        $this->ai = $ai;
    }
    
    /**
     * Infer missing field values based on context and existing data
     */
    public function inferMissingFields(array $existingData, array $allFields, string $entityType, UnifiedActionContext $context): array
    {
        $inferred = [];
        
        // Get entity name for inference
        $entityName = $existingData['name'] ?? $existingData['product'] ?? null;
        
        if (!$entityName) {
            return $inferred;
        }
        
        // Infer category from product name
        if (in_array('category', $allFields) && !isset($existingData['category'])) {
            $inferred['category'] = $this->inferCategory($entityName);
        }
        
        // Infer brand from product name
        if (in_array('brand', $allFields) && !isset($existingData['brand'])) {
            $brand = $this->inferBrand($entityName);
            if ($brand) {
                $inferred['brand'] = $brand;
            }
        }
        
        // Infer type from product name
        if (in_array('type', $allFields) && !isset($existingData['type'])) {
            $type = $this->inferType($entityName);
            if ($type) {
                $inferred['type'] = $type;
            }
        }
        
        // Infer quantity default
        if (in_array('quantity', $allFields) && !isset($existingData['quantity'])) {
            $inferred['quantity'] = 1;
        }
        
        return $inferred;
    }
    
    /**
     * Infer category from entity name
     */
    private function inferCategory(string $name): string
    {
        $name = strtolower($name);
        
        // Electronics
        if (preg_match('/laptop|computer|phone|tablet|monitor|keyboard|mouse|headphone|speaker|camera|iphone|ipad|macbook|samsung|dell|hp|lenovo/i', $name)) {
            return 'Electronics';
        }
        
        // Furniture
        if (preg_match('/desk|chair|table|sofa|bed|cabinet|shelf|couch|dresser/i', $name)) {
            return 'Furniture';
        }
        
        // Clothing
        if (preg_match('/shirt|pants|dress|jacket|shoes|hat|socks|jeans|sweater/i', $name)) {
            return 'Clothing';
        }
        
        // Food & Beverage
        if (preg_match('/food|drink|coffee|tea|snack|meal|pizza|burger/i', $name)) {
            return 'Food & Beverage';
        }
        
        // Office Supplies
        if (preg_match('/pen|paper|notebook|folder|stapler|printer|ink/i', $name)) {
            return 'Office Supplies';
        }
        
        return 'General';
    }
    
    /**
     * Infer brand from entity name
     */
    private function inferBrand(string $name): ?string
    {
        $name = strtolower($name);
        
        $brands = [
            'apple' => ['iphone', 'ipad', 'macbook', 'imac', 'airpods'],
            'samsung' => ['galaxy', 'samsung'],
            'dell' => ['dell', 'alienware'],
            'hp' => ['hp', 'hewlett'],
            'lenovo' => ['lenovo', 'thinkpad'],
            'microsoft' => ['surface', 'xbox'],
            'sony' => ['playstation', 'sony', 'bravia'],
            'lg' => ['lg'],
            'asus' => ['asus', 'rog'],
        ];
        
        foreach ($brands as $brand => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return ucfirst($brand);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Infer type from entity name
     */
    private function inferType(string $name): ?string
    {
        $name = strtolower($name);
        
        if (preg_match('/laptop|macbook|notebook/i', $name)) return 'Laptop';
        if (preg_match('/phone|iphone|smartphone/i', $name)) return 'Phone';
        if (preg_match('/tablet|ipad/i', $name)) return 'Tablet';
        if (preg_match('/monitor|display|screen/i', $name)) return 'Monitor';
        if (preg_match('/keyboard/i', $name)) return 'Keyboard';
        if (preg_match('/mouse/i', $name)) return 'Mouse';
        
        return null;
    }
    
    /**
     * Detect which search field to use based on identifier format
     */
    public function detectSearchField(string $identifier, array $availableFields): string
    {
        $identifier = trim($identifier);
        
        // Email detection
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            if (in_array('email', $availableFields)) {
                return 'email';
            }
        }
        
        // Phone number detection
        if (preg_match('/^[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,9}$/im', $identifier)) {
            if (in_array('phone', $availableFields)) return 'phone';
            if (in_array('contact', $availableFields)) return 'contact';
        }
        
        // SKU/Code detection (alphanumeric with dashes/underscores)
        if (preg_match('/^[A-Z0-9\-_]{5,}$/i', $identifier)) {
            if (in_array('sku', $availableFields)) return 'sku';
            if (in_array('code', $availableFields)) return 'code';
        }
        
        // Default to name
        return in_array('name', $availableFields) ? 'name' : $availableFields[0];
    }
    
    /**
     * Generate context-aware prompt for missing fields
     */
    public function generateContextAwarePrompt(array $existingData, array $missingFields, string $entityType): string
    {
        $entityName = $existingData['name'] ?? $existingData['product'] ?? 'this ' . $entityType;
        
        $message = "I see you want to add **{$entityName}**.\n\n";
        
        // Show what we already know
        if (count($existingData) > 1) {
            $message .= "What I know so far:\n";
            foreach ($existingData as $key => $value) {
                if ($key !== 'name' && $key !== 'product' && !empty($value)) {
                    $message .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
                }
            }
            $message .= "\n";
        }
        
        // Ask for missing fields
        $message .= "To complete this {$entityType}, please provide:\n";
        foreach ($missingFields as $field) {
            $fieldLabel = ucfirst(str_replace('_', ' ', $field));
            $example = $this->getFieldExample($field, $entityName);
            $message .= "- **{$fieldLabel}**" . ($example ? " (e.g., {$example})" : "") . "\n";
        }
        
        return $message;
    }
    
    /**
     * Get example value for a field
     */
    private function getFieldExample(string $field, string $entityName): ?string
    {
        $examples = [
            'price' => '$99.99',
            'sale_price' => '$150',
            'purchase_price' => '$100',
            'quantity' => '1',
            'email' => 'example@company.com',
            'phone' => '+1234567890',
            'contact' => '+1234567890',
            'category' => 'Electronics',
            'brand' => 'Apple',
            'sku' => 'PROD-12345',
        ];
        
        return $examples[$field] ?? null;
    }
    
    /**
     * Interpret user's duplicate choice using AI (language-agnostic)
     */
    public function interpretDuplicateChoice(string $userInput, int $maxOptions): ?array
    {
        $prompt = "User is choosing from {$maxOptions} similar options or creating new.\n";
        $prompt .= "User response: \"{$userInput}\"\n\n";
        $prompt .= "Determine their choice:\n";
        $prompt .= "- If selecting an option (1-{$maxOptions}), return: {\"action\":\"use\",\"index\":N}\n";
        $prompt .= "- If creating new, return: {\"action\":\"create\"}\n";
        $prompt .= "- If unclear, return: null\n\n";
        $prompt .= "Examples:\n";
        $prompt .= "Input: 'use first' → {\"action\":\"use\",\"index\":0}\n";
        $prompt .= "Input: '2' → {\"action\":\"use\",\"index\":1}\n";
        $prompt .= "Input: 'create new' → {\"action\":\"create\"}\n";
        $prompt .= "Input: 'none' → {\"action\":\"create\"}\n\n";
        $prompt .= "Return ONLY valid JSON or null";

        try {
            $response = $this->ai->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                maxTokens: 50,
                temperature: 0
            ));

            $content = trim($response->getContent());
            $content = preg_replace('/^```json\s*\n?/m', '', $content);
            $content = preg_replace('/\n?```\s*$/m', '', $content);
            $content = trim($content);
            
            if ($content === 'null') {
                return null;
            }
            
            $result = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($result['action'])) {
                // Validate index is within range
                if ($result['action'] === 'use' && isset($result['index'])) {
                    if ($result['index'] >= 0 && $result['index'] < $maxOptions) {
                        return $result;
                    }
                } else if ($result['action'] === 'create') {
                    return $result;
                }
            }
            
            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AI duplicate choice interpretation failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Interpret user's modification request
     * Returns array with action, field, value, item_name, item_field or null
     */
    public function interpretModificationRequest(string $userInput, array $currentData): ?array
    {
        $prompt = "User wants to modify their data. Current data: " . json_encode($currentData) . "\n";
        $prompt .= "User request: \"{$userInput}\"\n\n";
        $prompt .= "Determine what they want to modify:\n";
        $prompt .= "- action: 'add', 'remove', 'change', or 'update_item_field'\n";
        $prompt .= "- field: which field to modify (e.g., 'items', 'products')\n";
        $prompt .= "- item_name: name of item to modify (if modifying array item)\n";
        $prompt .= "- item_field: which field within the item to update (e.g., 'price', 'sale_price', 'quantity')\n";
        $prompt .= "- value: new value (for add/change/update actions)\n\n";
        $prompt .= "Return JSON with these fields or null if unclear.\n";
        $prompt .= "Examples:\n";
        $prompt .= "- Remove item: {\"action\":\"remove\",\"field\":\"items\",\"item_name\":\"macboss\"}\n";
        $prompt .= "- Change price: {\"action\":\"update_item_field\",\"field\":\"products\",\"item_name\":\"Macbook\",\"item_field\":\"price\",\"value\":300}\n";
        $prompt .= "- Change quantity: {\"action\":\"update_item_field\",\"field\":\"items\",\"item_name\":\"Rice\",\"item_field\":\"quantity\",\"value\":10}";

        try {
            $response = $this->ai->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                maxTokens: 150,
                temperature: 0
            ));

            $content = trim($response->getContent());
            $content = preg_replace('/^```json\s*\n?/m', '', $content);
            $content = preg_replace('/\n?```\s*$/m', '', $content);
            $content = trim($content);
            
            $result = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($result['action'])) {
                Log::info('IntelligentEntityService: Modification request interpreted', [
                    'user_input' => $userInput,
                    'interpretation' => $result,
                ]);
                return $result;
            }
            
            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AI modification interpretation failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Interpret user's confirmation intent using AI
     * Returns: 'confirm', 'cancel', or null if unclear
     */
    public function interpretConfirmationIntent(string $userInput): ?string
    {
        $prompt = "The user was asked to confirm an action (yes to proceed, no to cancel).\n";
        $prompt .= "User response: \"{$userInput}\"\n\n";
        $prompt .= "Determine the user's intent:\n";
        $prompt .= "- If they want to PROCEED/CONFIRM, return: confirm\n";
        $prompt .= "- If they want to CANCEL/DECLINE, return: cancel\n";
        $prompt .= "- If unclear, return: unclear\n\n";
        $prompt .= "Return ONLY one word: confirm, cancel, or unclear";

        try {
            $response = $this->ai->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                maxTokens: 10,
                temperature: 0
            ));

            $intent = strtolower(trim($response->getContent()));
            
            if (str_contains($intent, 'confirm')) {
                return 'confirm';
            } elseif (str_contains($intent, 'cancel')) {
                return 'cancel';
            }
            
            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AI confirmation intent detection failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
