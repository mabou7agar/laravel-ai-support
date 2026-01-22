<?php

if (!function_exists('discover_vectorizable_models')) {
    /**
     * Discover all models using the Vectorizable trait
     * Scans multiple paths: app/Models, modules, Modules, app
     * 
     * @return array<string> Array of fully qualified class names
     */
    function discover_vectorizable_models(): array
    {
        $models = [];
        
        // Define paths to scan for models
        $searchPaths = [
            app_path('Models'),           // app/Models
            base_path('modules'),         // modules/*/Models
            base_path('Modules'),         // Modules/*/Models (capitalized)
            app_path(),                   // app/* (for custom structures)
        ];
        
        foreach ($searchPaths as $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }
            
            $models = array_merge($models, scan_path_for_vectorizable_models($basePath));
        }
        
        return array_unique($models);
    }
}

if (!function_exists('scan_path_for_vectorizable_models')) {
    /**
     * Scan a specific path for models using the Vectorizable trait
     * 
     * @param string $basePath
     * @return array<string>
     */
    function scan_path_for_vectorizable_models(string $basePath): array
    {
        $models = [];
        
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (\Exception $e) {
            return $models;
        }
        
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Read file content and check for Vectorizable trait usage
                $content = file_get_contents($file->getPathname());
                
                // Check if file uses Vectorizable trait
                if (preg_match('/use\s+LaravelAIEngine\\\\Traits\\\\Vectorizable\s*;/i', $content) ||
                    preg_match('/use\s+Vectorizable\s*;/i', $content)) {
                    
                    // Extract the fully qualified class name from the file
                    $className = extract_class_name_from_file($file->getPathname(), $content);
                    
                    if ($className) {
                        $models[] = $className;
                    }
                }
            }
        }
        
        return $models;
    }
}

if (!function_exists('extract_class_name_from_file')) {
    /**
     * Extract the fully qualified class name from a PHP file
     * 
     * @param string $filePath
     * @param string $content
     * @return string|null
     */
    function extract_class_name_from_file(string $filePath, string $content): ?string
    {
        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = trim($namespaceMatches[1]);
            
            // Extract class name
            if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                $className = trim($classMatches[1]);
                
                return $namespace . '\\' . $className;
            }
        }
        
        return null;
    }
}

if (!function_exists('is_vectorizable')) {
    /**
     * Check if a model class uses the Vectorizable trait
     * 
     * @param string $modelClass
     * @return bool
     */
    function is_vectorizable(string $modelClass): bool
    {
        if (!class_exists($modelClass)) {
            return false;
        }
        
        $traits = class_uses_recursive($modelClass);
        return in_array('LaravelAIEngine\Traits\Vectorizable', $traits);
    }
}

if (!function_exists('ai_set_user_resolver')) {
    /**
     * Set the global user ID resolver at runtime
     * 
     * @param string|null $resolverClass Fully qualified class name or null to reset
     * @return void
     * 
     * @example
     * // Set custom resolver
     * ai_set_user_resolver(\App\Services\AI\TenantUserIdResolver::class);
     * 
     * // Reset to config default
     * ai_set_user_resolver(null);
     */
    function ai_set_user_resolver(?string $resolverClass): void
    {
        \LaravelAIEngine\Services\AIEngineService::setUserIdResolver($resolverClass);
    }
}

if (!function_exists('ai_set_query_resolver')) {
    /**
     * Set the global query resolver at runtime
     * 
     * @param string|null $resolverClass Fully qualified class name or null to reset
     * @return void
     * 
     * @example
     * // Set custom resolver
     * ai_set_query_resolver(\App\Services\AI\TenantQueryResolver::class);
     * 
     * // Reset to config default
     * ai_set_query_resolver(null);
     */
    function ai_set_query_resolver(?string $resolverClass): void
    {
        \LaravelAIEngine\Services\CreditManager::setQueryResolver($resolverClass);
    }
}

if (!function_exists('ai_set_resolvers')) {
    /**
     * Set both user ID and query resolvers at once
     * 
     * @param string|null $userIdResolver User ID resolver class or null
     * @param string|null $queryResolver Query resolver class or null
     * @return void
     * 
     * @example
     * // Set both resolvers
     * ai_set_resolvers(
     *     \App\Services\AI\TenantUserIdResolver::class,
     *     \App\Services\AI\TenantQueryResolver::class
     * );
     * 
     * // Reset both to config defaults
     * ai_set_resolvers(null, null);
     */
    function ai_set_resolvers(?string $userIdResolver, ?string $queryResolver): void
    {
        ai_set_user_resolver($userIdResolver);
        ai_set_query_resolver($queryResolver);
    }
}

if (!function_exists('ai_set_lifecycle_handler')) {
    /**
     * Set the global credit lifecycle handler at runtime
     * 
     * @param string|null $handlerClass Fully qualified class name or null to reset
     * @return void
     * 
     * @example
     * // Set custom lifecycle handler
     * ai_set_lifecycle_handler(\App\Services\AI\ExpiringCreditHandler::class);
     * 
     * // Reset to config default
     * ai_set_lifecycle_handler(null);
     */
    function ai_set_lifecycle_handler(?string $handlerClass): void
    {
        \LaravelAIEngine\Services\CreditManager::setLifecycleHandler($handlerClass);
    }
}
