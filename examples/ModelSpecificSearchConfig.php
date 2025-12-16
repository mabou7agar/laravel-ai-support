<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

/**
 * Product Model with Custom Search Configuration
 * 
 * This model defines its own search filters and configuration
 * that will be automatically applied when searching across nodes.
 */
class Product extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'name',
        'description',
        'workspace_id',
        'user_id',
        'category',
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
     * Metadata stored in Qdrant for filtering
     * This is indexed and can be used in search filters
     */
    public function getVectorMetadata(): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'user_id' => $this->user_id,
            'category' => $this->category,
            'is_public' => $this->is_public,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
    
    /**
     * ✅ NEW: Define custom search filters for this model
     * 
     * This method is called automatically when searching this model.
     * It overrides default user_id filtering with workspace_id filtering.
     * 
     * @param int|string|null $userId Current user ID
     * @param array $baseFilters Additional filters from search request
     * @return array Final filters to apply
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        // Products are workspace-scoped, not user-scoped
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            // No user = only public products
            return array_merge($baseFilters, [
                'is_public' => true,
                'status' => 'active',
            ]);
        }
        
        // User has access to their workspace products
        return array_merge($baseFilters, [
            'workspace_id' => $user->workspace_id,
            'status' => 'active',
            // Note: No user_id filter - all workspace members see same products
        ]);
    }
    
    /**
     * ✅ NEW: Define search configuration for this model
     * 
     * @return array Configuration options
     */
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,  // Don't apply default user_id filtering
            'threshold' => 0.7,           // Minimum relevance score
            'boost_fields' => [           // Boost certain fields in search
                'name' => 2.0,
                'category' => 1.5,
            ],
        ];
    }
}

/**
 * Document Model with Different Search Configuration
 */
class Document extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'title',
        'content',
        'user_id',
        'organization_id',
        'is_confidential',
        'status',
    ];
    
    public function toVectorArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'summary' => $this->summary,
        ];
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'is_confidential' => $this->is_confidential,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
    
    /**
     * ✅ Documents use organization-level filtering
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            return array_merge($baseFilters, [
                'is_confidential' => false,
                'status' => 'published',
            ]);
        }
        
        // Organization-level access
        return array_merge($baseFilters, [
            'organization_id' => $user->organization_id,
            'status' => 'published',
            // Confidential docs require specific user access
            '$or' => [
                ['is_confidential' => false],
                ['user_id' => $userId],
            ],
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.75,  // Higher threshold for documents
            'boost_fields' => [
                'title' => 3.0,
                'summary' => 1.5,
            ],
        ];
    }
}

/**
 * Article Model with Public Search
 */
class Article extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'title',
        'content',
        'author_id',
        'is_published',
        'category',
    ];
    
    public function toVectorArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'excerpt' => $this->excerpt,
        ];
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'author_id' => $this->author_id,
            'is_published' => $this->is_published,
            'category' => $this->category,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
    
    /**
     * ✅ Articles are public - no user filtering
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        // Public articles - no user restrictions
        return array_merge($baseFilters, [
            'is_published' => true,
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.6,  // Lower threshold for public content
            'boost_fields' => [
                'title' => 2.5,
            ],
        ];
    }
}

/**
 * Email Model with Strict User Filtering
 */
class Email extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'subject',
        'body',
        'user_id',
        'mailbox_id',
        'folder_name',
    ];
    
    public function toVectorArray(): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
            'from' => $this->from_address,
        ];
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'user_id' => $this->user_id,
            'mailbox_id' => $this->mailbox_id,
            'folder_name' => $this->folder_name,
            'from_address' => $this->from_address,
            'received_at' => $this->received_at?->toIso8601String(),
        ];
    }
    
    /**
     * ✅ Emails are strictly user-scoped
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        if (!$userId) {
            // No access without user
            return array_merge($baseFilters, [
                'user_id' => '__no_access__',
            ]);
        }
        
        // Strict user filtering - only user's emails
        return array_merge($baseFilters, [
            'user_id' => $userId,
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => false,  // Keep default user filtering too
            'threshold' => 0.65,
            'boost_fields' => [
                'subject' => 2.0,
                'from' => 1.5,
            ],
        ];
    }
}
