# Intelligent Collection Selection with RAG Descriptions

This guide explains how the AI **automatically selects which collections to search** based on your query and each collection's RAG description.

## The Problem

When you search for "Milk", the system shouldn't search **all collections** (Products, Documents, Emails, Articles). It should be smart enough to know:
- "Milk" → Search **Products only**
- "Contract with Acme" → Search **Documents only**
- "Did John email me?" → Search **Emails only**
- "How to setup Laravel" → Search **Articles only**

## The Solution: RAG Descriptions

Add a `getRAGDescription()` method to your models that tells the AI what the collection contains:

```php
class Product extends Model
{
    use Vectorizable;
    
    /**
     * ✅ RAG Description - AI uses this to decide if it should search this collection
     */
    public static function getRAGDescription(): string
    {
        return "Products and items available for purchase. Includes product names, descriptions, categories (dairy, beverages, snacks, electronics, etc.), prices, and availability. Search here for queries about: products, items, inventory, stock, pricing, what's available, product features, specifications.";
    }
}
```

## How It Works

### Step 1: User Query

```
User: "Find Milk products"
```

### Step 2: AI Analyzes Query + RAG Descriptions

```
Available Collections:
- Product: "Products and items... dairy, beverages, snacks..."
- Document: "Company documents, reports, policies..."
- Email: "Personal email messages and communications..."
- Article: "Published articles, blog posts, tutorials..."

AI Analysis:
Query mentions "Milk" and "products"
→ Product description mentions "products", "items", "dairy"
→ Match found!
→ Select: Product collection ONLY
```

### Step 3: Search Executes

```
Search Product collection for "Milk"
✅ Results: Milk products found
❌ Not searched: Documents, Emails, Articles
```

## Complete Examples

### Example 1: Product Search

```php
namespace App\Models;

use LaravelAIEngine\Traits\Vectorizable;

class Product extends Model
{
    use Vectorizable;
    
    /**
     * ✅ Tell AI what this collection contains
     */
    public static function getRAGDescription(): string
    {
        return "Products and items available for purchase. Includes product names, descriptions, categories (dairy, beverages, snacks, electronics, clothing, furniture, etc.), prices, stock availability, and product specifications. Search here for queries about: products, items, inventory, stock, pricing, what's available to buy, product features, specifications, categories.";
    }
    
    /**
     * Optional: Custom display name
     */
    public static function getRAGDisplayName(): string
    {
        return "Product Catalog";
    }
}
```

**Queries that will select this collection:**
- "Find Milk products"
- "Show me electronics"
- "What snacks do we have?"
- "Is this item in stock?"
- "How much does X cost?"

### Example 2: Document Search

```php
class Document extends Model
{
    use Vectorizable;
    
    public static function getRAGDescription(): string
    {
        return "Company documents, reports, policies, procedures, and internal documentation. Includes contracts, agreements, meeting notes, project documentation, technical specifications, business reports, SOPs, and compliance documents. Search here for queries about: documents, reports, policies, procedures, contracts, agreements, documentation, meeting notes, specifications.";
    }
    
    public static function getRAGDisplayName(): string
    {
        return "Document Library";
    }
}
```

**Queries that will select this collection:**
- "Show me the contract with Acme Corp"
- "Find our privacy policy"
- "What's in the Q3 report?"
- "Meeting notes from last week"

### Example 3: Email Search

```php
class Email extends Model
{
    use Vectorizable;
    
    public static function getRAGDescription(): string
    {
        return "Personal email messages and communications. Includes email subjects, body content, sender information, attachments, and folder organization. Search here for queries about: emails, messages, communications, correspondence, inbox, sent items, specific senders, email conversations, who emailed me.";
    }
    
    public static function getRAGDisplayName(): string
    {
        return "Email Inbox";
    }
}
```

**Queries that will select this collection:**
- "Did John email me about the meeting?"
- "Show me emails from last week"
- "Find the email with the invoice"
- "What's in my inbox?"

### Example 4: Article Search

```php
class Article extends Model
{
    use Vectorizable;
    
    public static function getRAGDescription(): string
    {
        return "Published articles, blog posts, and public content. Includes tutorials, guides, how-to articles, news, announcements, educational content, and knowledge base articles. Search here for queries about: articles, blog posts, tutorials, guides, how-to content, news, announcements, learning resources, documentation.";
    }
    
    public static function getRAGDisplayName(): string
    {
        return "Knowledge Base";
    }
}
```

**Queries that will select this collection:**
- "How to setup Laravel?"
- "Show me tutorials about React"
- "Latest news articles"
- "Guide for beginners"

### Example 5: Customer Search

```php
class Customer extends Model
{
    use Vectorizable;
    
    public static function getRAGDescription(): string
    {
        return "Customer information and profiles. Includes customer names, contact details, company information, purchase history, relationship notes, and account status. Search here for queries about: customers, clients, contacts, customer information, who bought, customer details, client relationships, account managers.";
    }
    
    public static function getRAGDisplayName(): string
    {
        return "Customer Database";
    }
}
```

**Queries that will select this collection:**
- "Who is our contact at Microsoft?"
- "Find customers in California"
- "Show me John Smith's account"
- "Which customers bought X?"

## Usage in Controllers

### Simple Search (AI Selects Collections)

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

public function search(Request $request, IntelligentRAGService $rag)
{
    // Provide ALL available collections
    // AI will intelligently select which ones to search
    $response = $rag->processMessage(
        message: $request->input('query'),
        sessionId: session()->getId(),
        availableCollections: [
            Product::class,
            Document::class,
            Email::class,
            Article::class,
            Customer::class,
        ],
        options: [],
        userId: auth()->id()
    );
    
    return response()->json([
        'response' => $response->getContent(),
        'sources' => $response->getMetadata()['sources'] ?? [],
    ]);
}
```

### Query Examples

```php
// Query: "Find Milk products"
// AI selects: Product only
// Searches: Product collection
// Result: Milk products

// Query: "Show me the contract with Acme"
// AI selects: Document only
// Searches: Document collection
// Result: Acme contract

// Query: "Did John email me?"
// AI selects: Email only
// Searches: Email collection
// Result: John's emails

// Query: "How to setup Laravel"
// AI selects: Article only
// Searches: Article collection
// Result: Laravel tutorials

// Query: "Who bought our products last month?"
// AI selects: Customer + Product
// Searches: Both collections
// Result: Customer purchase data
```

## AI Decision Process

### Single Collection Selection

```
Query: "Find Milk"

AI Analysis:
1. Check Product description: "products, items, dairy..." ✅ Match!
2. Check Document description: "documents, reports..." ❌ No match
3. Check Email description: "emails, messages..." ❌ No match
4. Check Article description: "articles, tutorials..." ❌ No match

Decision: Search Product ONLY
```

### Multiple Collection Selection

```
Query: "Show me customer emails about product issues"

AI Analysis:
1. Check Product description: "products..." ✅ Match (product issues)
2. Check Document description: "documents..." ❌ No match
3. Check Email description: "emails..." ✅ Match (customer emails)
4. Check Customer description: "customers..." ✅ Match (customer)

Decision: Search Email + Customer + Product
```

### No Collection Selection

```
Query: "Hello, how are you?"

AI Analysis:
1. Pure greeting, no information needed
2. No collection descriptions match

Decision: No search needed, direct response
```

## Best Practices for RAG Descriptions

### 1. Be Specific and Comprehensive

```php
// ❌ Bad: Too vague
public static function getRAGDescription(): string
{
    return "Products";
}

// ✅ Good: Specific and detailed
public static function getRAGDescription(): string
{
    return "Products and items available for purchase. Includes product names, descriptions, categories (dairy, beverages, snacks, electronics, clothing), prices, stock availability, and specifications. Search here for queries about: products, items, inventory, stock, pricing, availability, features, specifications.";
}
```

### 2. Include Keywords

```php
public static function getRAGDescription(): string
{
    return "Email messages and communications. "
         . "Keywords: emails, messages, inbox, sent, correspondence, "
         . "communications, mail, sender, recipient, subject, attachments.";
}
```

### 3. Mention Use Cases

```php
public static function getRAGDescription(): string
{
    return "Customer information and profiles. "
         . "Use cases: finding customer details, checking purchase history, "
         . "looking up contact information, viewing account status, "
         . "identifying customers by company or location.";
}
```

### 4. Include Domain-Specific Terms

```php
// For a medical records system
public static function getRAGDescription(): string
{
    return "Patient medical records and health information. "
         . "Includes diagnoses, treatments, prescriptions, lab results, "
         . "medical history, allergies, and visit notes. "
         . "Medical terms: symptoms, conditions, medications, procedures, "
         . "test results, vital signs, immunizations.";
}
```

## Advanced Patterns

### Pattern 1: Hierarchical Collections

```php
// Parent collection
class Product extends Model
{
    public static function getRAGDescription(): string
    {
        return "All products and items. Search here for general product queries.";
    }
}

// Specialized collection
class ElectronicsProduct extends Model
{
    public static function getRAGDescription(): string
    {
        return "Electronic products specifically. Includes computers, phones, tablets, accessories, specifications, warranty information. Search here for queries about: electronics, computers, phones, tablets, tech specs, electronic devices.";
    }
}
```

### Pattern 2: Time-Based Collections

```php
class RecentEmail extends Model
{
    public static function getRAGDescription(): string
    {
        return "Recent emails from the last 30 days. Search here for queries about: recent emails, latest messages, today's emails, this week's correspondence.";
    }
}

class ArchivedEmail extends Model
{
    public static function getRAGDescription(): string
    {
        return "Archived emails older than 30 days. Search here for queries about: old emails, archived messages, historical correspondence, past communications.";
    }
}
```

### Pattern 3: Multi-Language Collections

```php
class EnglishArticle extends Model
{
    public static function getRAGDescription(): string
    {
        return "English articles and content. Search here for English language queries.";
    }
}

class ArabicArticle extends Model
{
    public static function getRAGDescription(): string
    {
        return "Arabic articles and content (محتوى عربي). Search here for Arabic language queries.";
    }
}
```

## Testing

### Test RAG Description

```php
// Check what description AI sees
$description = Product::getRAGDescription();
dd($description);

// Test with multiple collections
$collections = [Product::class, Document::class, Email::class];
foreach ($collections as $collection) {
    echo $collection::getRAGDescription() . "\n\n";
}
```

### Test AI Selection

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

$rag = app(IntelligentRAGService::class);

// Enable debug mode to see AI's decision
config(['ai-engine.debug' => true]);

$response = $rag->processMessage(
    message: "Find Milk products",
    sessionId: "test",
    availableCollections: [
        Product::class,
        Document::class,
        Email::class,
    ],
    options: [],
    userId: auth()->id()
);

// Check logs to see which collections AI selected
// storage/logs/ai-engine.log will show:
// "AI selected collections: [Product]"
```

## Performance Benefits

### Before (Search All Collections)

```
Query: "Find Milk"
Collections Searched: Product, Document, Email, Article, Customer
Vector Searches: 5
Response Time: 2.5 seconds
Results: 500 items (mostly irrelevant)
```

### After (Intelligent Selection)

```
Query: "Find Milk"
AI Analysis: 50ms
Collections Searched: Product only
Vector Searches: 1
Response Time: 0.4 seconds
Results: 20 items (highly relevant)
```

**Performance Improvement: 6x faster** ⚡  
**Relevance Improvement: 95% relevant results** 🎯

## Troubleshooting

### Issue: AI Selects Wrong Collection

**Problem**: AI selects Document instead of Product for "Find Milk"

**Solution**: Improve RAG description

```php
// ❌ Before: Vague description
public static function getRAGDescription(): string
{
    return "Products in the system";
}

// ✅ After: Detailed with keywords
public static function getRAGDescription(): string
{
    return "Products and items for purchase including food items (dairy, beverages, snacks), electronics, clothing. Keywords: products, items, buy, purchase, stock, inventory, Milk, cheese, bread, etc.";
}
```

### Issue: AI Selects Too Many Collections

**Problem**: AI selects all collections for simple queries

**Solution**: Make descriptions more distinct

```php
// Make each description unique and specific
Product: "Physical products and items for sale..."
Document: "Business documents and reports ONLY..."
Email: "Email communications and messages ONLY..."
```

### Issue: AI Doesn't Select Any Collection

**Problem**: AI returns "needs_context: false"

**Solution**: Add more trigger keywords

```php
public static function getRAGDescription(): string
{
    return "Products... Search triggers: product, item, buy, purchase, "
         . "stock, inventory, available, price, cost, sell, order, "
         . "catalog, listing, merchandise.";
}
```

## Summary

✅ **Add `getRAGDescription()` to models** - Tell AI what the collection contains  
✅ **AI automatically selects relevant collections** - No manual configuration  
✅ **Works across all nodes** - Same intelligence everywhere  
✅ **Improves performance** - Only searches relevant collections  
✅ **Better results** - Higher relevance, less noise  
✅ **Easy to maintain** - Update description in one place  

**Result: Smart, efficient, targeted searches that only query relevant collections!** 🎯
