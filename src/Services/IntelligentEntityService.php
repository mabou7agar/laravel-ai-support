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
     * Extract structured data from natural language input
     * Example: "2 laptops at $1500" -> {product: "laptop", quantity: 2, price: 1500}
     */
    public function extractDataFromNaturalLanguage(string $input, array $expectedFields, string $entityType): array
    {
        try {
            $fieldsJson = json_encode($expectedFields, JSON_PRETTY_PRINT);
            
            $prompt = "Extract structured data from the following user input.\n\n";
            $prompt .= "User Input: \"{$input}\"\n\n";
            $prompt .= "Entity Type: {$entityType}\n\n";
            $prompt .= "Expected Fields:\n{$fieldsJson}\n\n";
            $prompt .= "Instructions:\n";
            $prompt .= "- Extract all relevant information from the input\n";
            $prompt .= "- Handle natural language variations\n";
            $prompt .= "- Infer missing fields when possible\n";
            $prompt .= "- Return as JSON object with field names as keys\n";
            $prompt .= "- Use null for fields that cannot be determined\n\n";
            $prompt .= "Examples:\n";
            $prompt .= "Input: '2 laptops at $1500 each'\n";
            $prompt .= "Output: {\"product\": \"laptop\", \"quantity\": 2, \"price\": 1500}\n\n";
            $prompt .= "Input: 'iPhone 13 for $999'\n";
            $prompt .= "Output: {\"product\": \"iPhone 13\", \"price\": 999, \"quantity\": 1}\n\n";
            $prompt .= "JSON Response:";
            
            // TODO: Enable AI extraction once caching resolved
            // For now, use intelligent parsing
            return $this->parseNaturalLanguageFallback($input, $expectedFields);
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('NL data extraction failed', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);
            
            return $this->parseNaturalLanguageFallback($input, $expectedFields);
        }
    }
    
    /**
     * Intelligent fallback for natural language parsing
     */
    private function parseNaturalLanguageFallback(string $input, array $expectedFields): array
    {
        $extracted = [];
        $input = strtolower(trim($input));
        
        // Extract quantity
        if (in_array('quantity', $expectedFields)) {
            if (preg_match('/(\d+)\s*(x|pieces?|items?|units?)?/i', $input, $matches)) {
                $extracted['quantity'] = (int) $matches[1];
            }
        }
        
        // Extract price/cost
        if (in_array('price', $expectedFields) || in_array('cost', $expectedFields)) {
            if (preg_match('/\$?\s*(\d+(?:\.\d{2})?)/i', $input, $matches)) {
                $key = in_array('price', $expectedFields) ? 'price' : 'cost';
                $extracted[$key] = (float) $matches[1];
            }
        }
        
        // Extract sale/purchase price
        if (in_array('sale_price', $expectedFields)) {
            if (preg_match('/sale\s*(?:price)?\s*\$?\s*(\d+(?:\.\d{2})?)/i', $input, $matches)) {
                $extracted['sale_price'] = (float) $matches[1];
            }
        }
        
        if (in_array('purchase_price', $expectedFields)) {
            if (preg_match('/purchase\s*(?:price)?\s*\$?\s*(\d+(?:\.\d{2})?)/i', $input, $matches)) {
                $extracted['purchase_price'] = (float) $matches[1];
            }
        }
        
        // Extract product/item name (remove numbers and prices)
        if (in_array('product', $expectedFields) || in_array('name', $expectedFields)) {
            $name = preg_replace('/\d+\s*(x|pieces?|items?|units?)?/i', '', $input);
            $name = preg_replace('/\$?\s*\d+(?:\.\d{2})?/', '', $name);
            $name = preg_replace('/(sale|purchase)\s*(?:price)?/i', '', $name);
            $name = trim($name);
            
            if (!empty($name)) {
                $key = in_array('product', $expectedFields) ? 'product' : 'name';
                $extracted[$key] = ucwords($name);
            }
        }
        
        return $extracted;
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
     * Interpret user's duplicate choice from natural language
     */
    public function interpretDuplicateChoice(string $userInput, int $maxOptions): ?array
    {
        $input = strtolower(trim($userInput));
        
        // Check for "use" or "yes" (use first/only option)
        if (preg_match('/^(use|yes|y|ok|sure|yeah)$/i', $input)) {
            return ['action' => 'use', 'index' => 0];
        }
        
        // Check for "new" or "create"
        if (preg_match('/(new|create|different|another)/i', $input)) {
            return ['action' => 'create'];
        }
        
        // Check for number selection
        if (preg_match('/(\d+)/', $input, $matches)) {
            $number = (int) $matches[1];
            if ($number >= 1 && $number <= $maxOptions) {
                return ['action' => 'use', 'index' => $number - 1];
            }
        }
        
        // Check for ordinal words
        $ordinals = [
            'first' => 0, 'second' => 1, 'third' => 2, 'fourth' => 3, 'fifth' => 4,
            '1st' => 0, '2nd' => 1, '3rd' => 2, '4th' => 3, '5th' => 4,
        ];
        
        foreach ($ordinals as $word => $index) {
            if (str_contains($input, $word) && $index < $maxOptions) {
                return ['action' => 'use', 'index' => $index];
            }
        }
        
        // Check for "none" or "neither"
        if (preg_match('/(none|neither|no|nope)/i', $input)) {
            return ['action' => 'create'];
        }
        
        return null; // Could not interpret
    }
}
