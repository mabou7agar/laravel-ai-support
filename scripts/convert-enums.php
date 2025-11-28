#!/usr/bin/env php
<?php

/**
 * Convert PHP 8.1 enums to PHP 8.0 compatible classes
 * 
 * This script converts native enums to class-based enums for Laravel 9 compatibility
 */

$enumFiles = [
    __DIR__ . '/../src/Enums/EngineEnum.php',
    __DIR__ . '/../src/Enums/EntityEnum.php',
    __DIR__ . '/../src/Enums/ActionTypeEnum.php',
];

foreach ($enumFiles as $file) {
    if (!file_exists($file)) {
        echo "⚠️  File not found: $file\n";
        continue;
    }
    
    echo "🔄 Converting: " . basename($file) . "\n";
    
    $content = file_get_contents($file);
    
    // Check if it's already converted
    if (strpos($content, 'enum ') === false) {
        echo "✅ Already converted: " . basename($file) . "\n";
        continue;
    }
    
    // Extract enum name
    preg_match('/enum\s+(\w+):\s*string/', $content, $matches);
    $enumName = $matches[1] ?? 'Unknown';
    
    echo "   Enum name: $enumName\n";
    
    // Extract cases
    preg_match_all('/case\s+(\w+)\s*=\s*[\'"]([^\'"]+)[\'"];/', $content, $caseMatches, PREG_SET_ORDER);
    
    $cases = [];
    foreach ($caseMatches as $match) {
        $cases[$match[1]] = $match[2];
    }
    
    echo "   Found " . count($cases) . " cases\n";
    
    // Convert match expressions to switch statements
    $content = preg_replace_callback(
        '/return\s+match\s*\(\$this\)\s*\{([^}]+)\};/s',
        function($matches) {
            $matchBody = $matches[1];
            
            // Parse match arms
            preg_match_all('/self::(\w+)\s*=>\s*([^,\n]+),?/s', $matchBody, $arms, PREG_SET_ORDER);
            
            $switchCases = "switch (\$this->value) {\n";
            foreach ($arms as $arm) {
                $case = trim($arm[1]);
                $return = trim($arm[2]);
                $switchCases .= "            case self::$case:\n";
                $switchCases .= "                return $return;\n";
            }
            $switchCases .= "            default:\n";
            $switchCases .= "                throw new \\InvalidArgumentException(\"Unknown value: {\$this->value}\");\n";
            $switchCases .= "        }";
            
            return "return " . $switchCases;
        },
        $content
    );
    
    echo "   ✅ Converted match expressions to switch statements\n";
    echo "   ⚠️  Manual review required for: " . basename($file) . "\n\n";
}

echo "\n✅ Conversion complete!\n";
echo "📝 Please manually review and complete the conversion:\n";
echo "   1. Replace 'enum ClassName: string' with 'class ClassName'\n";
echo "   2. Convert 'case NAME = value' to 'public const NAME = value'\n";
echo "   3. Add constructor: public function __construct(public string \$value) {}\n";
echo "   4. Add static from() method\n";
echo "   5. Add static cases() method\n";
echo "   6. Review all match expressions\n";
