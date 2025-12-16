<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use App\Models\Product;
use App\Models\Document;

/**
 * Example: Workspace-Level Product Search
 * 
 * This example shows how to search products by workspace_id instead of user_id.
 * All users in the same workspace can see the same products.
 */
class WorkspaceProductSearchController extends Controller
{
    /**
     * Search products within user's workspace
     */
    public function search(Request $request, IntelligentRAGService $rag)
    {
        $request->validate([
            'query' => 'required|string|max:500',
        ]);
        
        $user = auth()->user();
        $workspaceId = $user->workspace_id;
        
        // Option 1: Search with workspace_id filter (keeps user_id too)
        $response = $rag->processMessage(
            message: $request->input('query'),
            sessionId: "workspace_{$workspaceId}_" . session()->getId(),
            availableCollections: [Product::class],
            conversationHistory: [],
            options: [
                'filters' => [
                    'workspace_id' => $workspaceId,  // Workspace filter
                    // user_id will be added automatically
                ],
                'threshold' => 0.7,
                'max_context' => 10,
            ],
            userId: $user->id
        );
        
        return response()->json([
            'success' => true,
            'response' => $response->getContent(),
            'sources' => $response->getMetadata()['sources'] ?? [],
        ]);
    }
    
    /**
     * Search products workspace-wide (skip user_id filtering)
     */
    public function searchWorkspaceWide(Request $request, IntelligentRAGService $rag)
    {
        $request->validate([
            'query' => 'required|string|max:500',
        ]);
        
        $user = auth()->user();
        $workspaceId = $user->workspace_id;
        
        // Option 2: Skip user_id filter entirely (workspace-level only)
        $response = $rag->processMessage(
            message: $request->input('query'),
            sessionId: "workspace_{$workspaceId}_" . session()->getId(),
            availableCollections: [Product::class],
            conversationHistory: [],
            options: [
                'filters' => [
                    'workspace_id' => $workspaceId,  // Only workspace filter
                ],
                'skip_user_filter' => true,  // Skip user_id filtering
                'threshold' => 0.7,
                'max_context' => 10,
            ],
            userId: $user->id  // Still passed for logging/admin checks
        );
        
        return response()->json([
            'success' => true,
            'response' => $response->getContent(),
            'sources' => $response->getMetadata()['sources'] ?? [],
        ]);
    }
    
    /**
     * Search public products (no user or workspace filtering)
     */
    public function searchPublic(Request $request, IntelligentRAGService $rag)
    {
        $request->validate([
            'query' => 'required|string|max:500',
        ]);
        
        // Option 3: Public search with custom filters only
        $response = $rag->processMessage(
            message: $request->input('query'),
            sessionId: "public_" . session()->getId(),
            availableCollections: [Product::class],
            conversationHistory: [],
            options: [
                'filters' => [
                    'is_public' => true,  // Only public products
                    'status' => 'active',
                ],
                'skip_user_filter' => true,  // No user filtering
                'threshold' => 0.7,
                'max_context' => 10,
            ],
            userId: auth()->id() ?? null  // Optional for guests
        );
        
        return response()->json([
            'success' => true,
            'response' => $response->getContent(),
            'sources' => $response->getMetadata()['sources'] ?? [],
        ]);
    }
    
    /**
     * Multi-tenant search across workspaces (admin only)
     */
    public function searchAllWorkspaces(Request $request, IntelligentRAGService $rag)
    {
        $request->validate([
            'query' => 'required|string|max:500',
            'workspace_ids' => 'array',
            'workspace_ids.*' => 'integer',
        ]);
        
        $user = auth()->user();
        
        // Check if user is admin
        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'error' => 'Admin access required',
            ], 403);
        }
        
        $workspaceIds = $request->input('workspace_ids', []);
        
        // Option 4: Admin search across multiple workspaces
        $response = $rag->processMessage(
            message: $request->input('query'),
            sessionId: "admin_" . session()->getId(),
            availableCollections: [Product::class, Document::class],
            conversationHistory: [],
            options: [
                'filters' => empty($workspaceIds) ? [] : [
                    'workspace_id' => ['$in' => $workspaceIds],  // Qdrant array filter
                ],
                'skip_user_filter' => true,  // Admin sees all
                'threshold' => 0.7,
                'max_context' => 20,
            ],
            userId: $user->id
        );
        
        return response()->json([
            'success' => true,
            'response' => $response->getContent(),
            'sources' => $response->getMetadata()['sources'] ?? [],
            'workspaces_searched' => $workspaceIds,
        ]);
    }
}

/**
 * Product Model with Workspace-Level Vector Metadata
 */
class Product extends Model
{
    use \LaravelAIEngine\Traits\Vectorizable;
    
    protected $fillable = [
        'name',
        'description',
        'workspace_id',
        'user_id',
        'is_public',
        'status',
    ];
    
    /**
     * Define what gets indexed in vector database
     */
    public function toVectorArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'tags' => implode(', ', $this->tags ?? []),
        ];
    }
    
    /**
     * Metadata for filtering in vector searches
     * This is stored in Qdrant payload
     */
    public function getVectorMetadata(): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'user_id' => $this->user_id,
            'is_public' => $this->is_public,
            'status' => $this->status,
            'category' => $this->category,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

/**
 * Routes
 */
// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    // User's workspace products
    Route::post('/products/search', [WorkspaceProductSearchController::class, 'search']);
    
    // All workspace products (not just user's)
    Route::post('/products/search/workspace', [WorkspaceProductSearchController::class, 'searchWorkspaceWide']);
    
    // Admin: search across workspaces
    Route::post('/products/search/admin', [WorkspaceProductSearchController::class, 'searchAllWorkspaces'])
        ->middleware('admin');
});

// Public search (no auth required)
Route::post('/products/search/public', [WorkspaceProductSearchController::class, 'searchPublic']);
