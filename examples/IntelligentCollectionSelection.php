<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

/**
 * Product Model with RAG Description
 * 
 * The AI uses the RAG description to decide if this collection
 * should be searched for a given query.
 */
class Product extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'name',
        'description',
        'workspace_id',
        'category',
        'price',
        'status',
    ];
    
    /**
     * ✅ RAG Description - Tells AI what this collection contains
     * 
     * The AI analyzes the user's query and this description to decide
     * if it should search this collection.
     */
    public static function getRAGDescription(): string
    {
        return "Products and items available for purchase. Includes product names, descriptions, categories (dairy, beverages, snacks, electronics, etc.), prices, and availability. Search here for queries about: products, items, inventory, stock, pricing, what's available, product features, specifications.";
    }
    
    /**
     * Workspace-scoped filtering
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            return array_merge($baseFilters, ['is_public' => true]);
        }
        
        return array_merge($baseFilters, [
            'workspace_id' => $user->workspace_id,
            'status' => 'active',
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.7,
        ];
    }
    
    public function toVectorArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
        ];
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'category' => $this->category,
            'status' => $this->status,
        ];
    }
}

/**
 * Document Model with RAG Description
 */
class Document extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'title',
        'content',
        'organization_id',
        'document_type',
        'status',
    ];
    
    /**
     * ✅ RAG Description for Documents
     */
    public static function getRAGDescription(): string
    {
        return "Company documents, reports, policies, procedures, and internal documentation. Includes contracts, agreements, meeting notes, project documentation, technical specifications, and business reports. Search here for queries about: documents, reports, policies, procedures, contracts, agreements, documentation, meeting notes.";
    }
    
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            return array_merge($baseFilters, ['is_public' => true]);
        }
        
        return array_merge($baseFilters, [
            'organization_id' => $user->organization_id,
            'status' => 'published',
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.75,
        ];
    }
    
    public function toVectorArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
        ];
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'organization_id' => $this->organization_id,
            'document_type' => $this->document_type,
            'status' => $this->status,
        ];
    }
}

/**
 * Email Model with RAG Description
 */
class Email extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'subject',
        'body',
        'user_id',
        'from_address',
        'folder_name',
    ];
    
    /**
     * ✅ RAG Description for Emails
     */
    public static function getRAGDescription(): string
    {
        return "Personal email messages and communications. Includes email subjects, body content, sender information, and folder organization. Search here for queries about: emails, messages, communications, correspondence, inbox, sent items, specific senders, email conversations.";
    }
    
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        if (!$userId) {
            return array_merge($baseFilters, ['user_id' => '__no_access__']);
        }
        
        return array_merge($baseFilters, [
            'user_id' => $userId,
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => false,
            'threshold' => 0.65,
        ];
    }
    
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
            'from_address' => $this->from_address,
            'folder_name' => $this->folder_name,
        ];
    }
}

/**
 * Article Model with RAG Description
 */
class Article extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'title',
        'content',
        'category',
        'is_published',
    ];
    
    /**
     * ✅ RAG Description for Articles
     */
    public static function getRAGDescription(): string
    {
        return "Published articles, blog posts, and public content. Includes tutorials, guides, news, announcements, and educational content. Search here for queries about: articles, blog posts, tutorials, guides, how-to content, news, announcements, public information.";
    }
    
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        return array_merge($baseFilters, [
            'is_published' => true,
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.6,
        ];
    }
    
    public function toVectorArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
        ];
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'category' => $this->category,
            'is_published' => $this->is_published,
        ];
    }
}

/**
 * Customer Model with RAG Description
 */
class Customer extends Model
{
    use Vectorizable;
    
    protected $fillable = [
        'name',
        'email',
        'company',
        'notes',
        'workspace_id',
    ];
    
    /**
     * ✅ RAG Description for Customers
     */
    public static function getRAGDescription(): string
    {
        return "Customer information and profiles. Includes customer names, contact details, company information, purchase history, and relationship notes. Search here for queries about: customers, clients, contacts, customer information, who bought, customer details, client relationships.";
    }
    
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            return array_merge($baseFilters, ['workspace_id' => '__no_access__']);
        }
        
        return array_merge($baseFilters, [
            'workspace_id' => $user->workspace_id,
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.7,
        ];
    }
    
    public function toVectorArray(): array
    {
        return [
            'name' => $this->name,
            'company' => $this->company,
            'notes' => $this->notes,
        ];
    }
    
    public function getVectorMetadata(): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'email' => $this->email,
        ];
    }
}

/**
 * Example Usage
 */

// Query: "Find Milk products"
// AI analyzes query + RAG descriptions
// AI decides: Search Product collection (mentions "products", "items")
// Result: Only searches Product, not Document/Email/Article

// Query: "Show me the contract with Acme Corp"
// AI analyzes query + RAG descriptions
// AI decides: Search Document collection (mentions "contracts", "agreements")
// Result: Only searches Document

// Query: "Did John email me about the meeting?"
// AI analyzes query + RAG descriptions
// AI decides: Search Email collection (mentions "email", "messages")
// Result: Only searches Email

// Query: "How to setup Laravel"
// AI analyzes query + RAG descriptions
// AI decides: Search Article collection (mentions "tutorials", "guides", "how-to")
// Result: Only searches Article

// Query: "Who is our contact at Microsoft?"
// AI analyzes query + RAG descriptions
// AI decides: Search Customer collection (mentions "contacts", "customer information")
// Result: Only searches Customer
