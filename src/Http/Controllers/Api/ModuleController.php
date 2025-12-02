<?php

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

class ModuleController extends Controller
{
    protected $ragDiscovery;

    public function __construct(RAGCollectionDiscovery $ragDiscovery)
    {
        $this->ragDiscovery = $ragDiscovery;
    }

    /**
     * Discover available modules (RAG collections, knowledge bases, etc.)
     */
    public function discover(Request $request)
    {
        try {
            // Get all available RAG collections (returns array of class names)
            $ragCollectionClasses = $this->ragDiscovery->discover();
            
            // Format as modules
            $modules = collect($ragCollectionClasses)->map(function ($className) {
                // Extract model name from class
                $modelName = class_basename($className);
                
                return [
                    'id' => $className,
                    'name' => $this->formatModelName($modelName),
                    'type' => 'rag_collection',
                    'description' => "Search through {$this->formatModelName($modelName)}",
                    'enabled' => true,
                    'icon' => $this->getCollectionIcon($modelName),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'modules' => $modules,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to discover modules',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format model name for display
     */
    protected function formatModelName(string $modelName): string
    {
        // Convert PascalCase to Title Case with spaces
        // Example: EmailMessage -> Email Message
        return ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $modelName));
    }

    /**
     * Get icon for collection based on name
     */
    protected function getCollectionIcon(string $name): string
    {
        $name = strtolower($name);
        
        if (str_contains($name, 'email')) return '📧';
        if (str_contains($name, 'document')) return '📄';
        if (str_contains($name, 'file')) return '📁';
        if (str_contains($name, 'post')) return '📝';
        if (str_contains($name, 'article')) return '📰';
        if (str_contains($name, 'knowledge')) return '🧠';
        if (str_contains($name, 'database')) return '💾';
        if (str_contains($name, 'api')) return '🔌';
        if (str_contains($name, 'user')) return '👤';
        if (str_contains($name, 'customer')) return '👥';
        if (str_contains($name, 'product')) return '🛍️';
        if (str_contains($name, 'order')) return '📦';
        
        return '📚';
    }
}
