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
            // Get all available RAG collections
            $ragCollections = $this->ragDiscovery->discoverCollections();
            
            // Format as modules
            $modules = collect($ragCollections)->map(function ($collection) {
                return [
                    'id' => $collection['id'] ?? $collection['name'],
                    'name' => $collection['name'],
                    'type' => 'rag_collection',
                    'description' => $collection['description'] ?? "Search through {$collection['name']}",
                    'enabled' => $collection['enabled'] ?? true,
                    'icon' => $this->getCollectionIcon($collection['name']),
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
