<?php

namespace LaravelAIEngine\Services\AI;

/**
 * Dynamic field detector
 * Intelligently detects field values in data arrays using pattern matching
 * No hardcoded field names - uses semantic patterns
 */
class FieldDetector
{
    /**
     * System fields to skip when detecting identifiers
     */
    private static array $systemFieldPatterns = ['_at$', '^id$', '_id$'];
    
    /**
     * Default field name for identifier (used when config doesn't specify)
     */
    private static string $defaultIdentifierField = 'name';
    
    /**
     * Default field name for quantity (used when config doesn't specify)
     */
    private static string $defaultQuantityField = 'quantity';
    
    /**
     * Common patterns for price fields (for display formatting only)
     */
    private static array $pricePatterns = ['price', 'cost', 'rate', 'fee', 'charge'];

    /**
     * Detect the primary identifier field in an item
     * Returns the first non-empty string field that looks like a name/identifier
     * 
     * @param array $item The item to search
     * @param array $priorityFields Optional priority fields to check first (from config)
     */
    public static function detectIdentifier(array $item, array $priorityFields = []): ?string
    {
        if (empty($item)) {
            return null;
        }

        // Check priority fields first (from config)
        foreach ($priorityFields as $field) {
            if (isset($item[$field]) && is_string($item[$field]) && !empty(trim($item[$field]))) {
                return $item[$field];
            }
        }

        // Scan all fields and find the first string value that looks like an identifier
        foreach ($item as $key => $value) {
            if (is_string($value) && !empty(trim($value)) && !is_numeric($value)) {
                // Skip system fields using patterns
                if (self::isSystemField($key)) {
                    continue;
                }
                return $value;
            }
        }

        return null;
    }

    /**
     * Detect a numeric field by semantic pattern
     * 
     * @param array $item The item to search
     * @param array $patterns Patterns to match in field names (e.g., ['qty', 'quantity', 'count'])
     * @param mixed $default Default value if not found
     */
    public static function detectNumericByPattern(array $item, array $patterns, $default = null)
    {
        foreach ($item as $key => $value) {
            if (is_numeric($value)) {
                $keyLower = strtolower($key);
                foreach ($patterns as $pattern) {
                    if (str_contains($keyLower, strtolower($pattern))) {
                        return $value;
                    }
                }
            }
        }
        return $default;
    }

    /**
     * Detect price field in an item
     * Uses semantic patterns to find price-like fields
     * 
     * @param array $item The item to search
     * @param array $patterns Optional custom patterns (defaults to common price patterns)
     */
    public static function detectPrice(array $item, array $patterns = []): ?float
    {
        // Use provided patterns or common semantic patterns
        $searchPatterns = !empty($patterns) ? $patterns : ['price', 'cost', 'rate', 'fee', 'charge'];
        
        $value = self::detectNumericByPattern($item, $searchPatterns, null);
        
        return $value !== null ? (float) $value : null;
    }

    /**
     * Extract all detail fields from an item
     * Returns a string combining all non-system fields
     */
    public static function extractDetails(array $item): string
    {
        $details = [];

        foreach ($item as $key => $value) {
            // Skip system fields
            if (self::isSystemField($key)) {
                continue;
            }

            if (is_string($value) && !empty(trim($value))) {
                $details[] = trim($value);
            }
        }

        return implode(' ', $details);
    }

    /**
     * Check if a field name matches system field patterns
     */
    private static function isSystemField(string $fieldName): bool
    {
        foreach (self::$systemFieldPatterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $fieldName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merge two data arrays intelligently
     * Decides which fields to keep based on completeness
     */
    public static function mergeData(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            // Skip null or empty values from source
            if ($value === null || $value === '') {
                continue;
            }

            // If target doesn't have this field, add it
            if (!isset($target[$key])) {
                $target[$key] = $value;
                continue;
            }

            // If target field is empty but source has value, replace
            if (empty($target[$key]) && !empty($value)) {
                $target[$key] = $value;
                continue;
            }

            // For numeric fields, prefer non-zero values
            if (is_numeric($value) && is_numeric($target[$key])) {
                if ($target[$key] == 0 && $value != 0) {
                    $target[$key] = $value;
                }
            }
        }

        return $target;
    }

    /**
     * Get field value by pattern matching
     * 
     * @param array $item The item to search
     * @param array $patterns Patterns to match in field names
     * @param mixed $default Default value if not found
     */
    public static function getFieldByPattern(array $item, array $patterns, $default = null)
    {
        foreach ($item as $key => $value) {
            $keyLower = strtolower($key);
            foreach ($patterns as $pattern) {
                if (str_contains($keyLower, strtolower($pattern))) {
                    return $value;
                }
            }
        }
        return $default;
    }
    
    /**
     * Detect the field name that matches price patterns
     * Returns the key name, not the value
     * 
     * @param array $item The item to search
     * @param array $priorityFields Optional priority fields from config
     */
    public static function detectPriceFieldName(array $item, array $priorityFields = []): ?string
    {
        // Check priority fields first (from config)
        foreach ($priorityFields as $field) {
            if (isset($item[$field]) && is_numeric($item[$field])) {
                return $field;
            }
        }
        
        // Check common price patterns
        foreach ($item as $key => $value) {
            if (is_numeric($value)) {
                $keyLower = strtolower($key);
                foreach (self::$pricePatterns as $pattern) {
                    if (str_contains($keyLower, $pattern)) {
                        return $key;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get price patterns (for display formatting)
     */
    public static function getPricePatterns(): array
    {
        return self::$pricePatterns;
    }
    
    /**
     * Convert a string value to an array item with identifier and quantity fields
     * Uses config-provided field names or defaults
     * 
     * @param string $value The string value to convert
     * @param array $config Optional config with identifier_field, quantity_field, search_fields
     * @param int $defaultQuantity Default quantity value (default: 1)
     * @return array The converted array item
     */
    public static function stringToArrayItem(string $value, array $config = [], int $defaultQuantity = 1): array
    {
        $fieldNames = self::getFieldNames($config);
        return [$fieldNames['identifier'] => $value, $fieldNames['quantity'] => $defaultQuantity];
    }
    
    /**
     * Convert a string to an array of items (wraps stringToArrayItem in an array)
     * 
     * @param string $value The string value to convert
     * @param array $config Optional config with identifier_field, quantity_field, search_fields
     * @param int $defaultQuantity Default quantity value (default: 1)
     * @return array Array containing the converted item
     */
    public static function stringToItemArray(string $value, array $config = [], int $defaultQuantity = 1): array
    {
        return [self::stringToArrayItem($value, $config, $defaultQuantity)];
    }
    
    /**
     * Get the identifier and quantity field names from config
     * 
     * @param array $config Optional config with identifier_field, quantity_field, search_fields
     * @return array ['identifier' => string, 'quantity' => string]
     */
    public static function getFieldNames(array $config = []): array
    {
        return [
            'identifier' => $config['identifier_field'] ?? ($config['search_fields'][0] ?? self::$defaultIdentifierField),
            'quantity' => $config['quantity_field'] ?? self::$defaultQuantityField,
        ];
    }
}
