# 🎯 Node Registration Guide - AI-Friendly Descriptions

## Overview

When registering nodes, you should provide **rich descriptions** that help the AI automatically decide which nodes to use based on the user's query. The system uses this information for **context-aware node selection**.

---

## 📋 Node Information Fields

### **Required Fields:**
- `name` - Human-readable node name
- `url` - Base URL of the node

### **AI-Friendly Fields (Highly Recommended):**
- `description` - **What the node does** (most important!)
- `domains` - Business domains (ecommerce, blog, crm, etc.)
- `data_types` - Types of data available (products, posts, customers, etc.)
- `keywords` - Search keywords for better matching

### **Optional Fields:**
- `capabilities` - Technical capabilities (search, actions, rag)
- `weight` - Load balancing weight (default: 1)
- `type` - Node type (master/child, default: child)

---

## 🎨 How AI Uses This Information

The AI analyzes the user's query and automatically selects relevant nodes based on:

1. **Description matching** - Primary source of context
2. **Domain relevance** - Business area alignment
3. **Data type matching** - Specific data needs
4. **Keyword matching** - Search term relevance
5. **Health status** - Only healthy nodes are selected

### **Example Query Flow:**

**User Query:** "Show me the latest products in our e-commerce store"

**AI Analysis:**
- Detects keywords: "products", "e-commerce", "store"
- Finds nodes with:
  - Domain: "ecommerce"
  - Data types: "products"
  - Keywords: "shopping", "store", "catalog"
- Selects: **E-commerce Node**

---

## 💡 Registration Examples

### **Example 1: E-commerce Store**

```bash
php artisan ai-engine:node-register \
  "E-commerce Store" \
  https://shop.example.com \
  --description="Online shopping platform with products, orders, and customer data. Handles product catalog, inventory, pricing, and order management." \
  --domains=ecommerce \
  --domains=retail \
  --data-types=products \
  --data-types=orders \
  --data-types=customers \
  --data-types=inventory \
  --keywords=shopping \
  --keywords=store \
  --keywords=buy \
  --keywords=cart \
  --keywords=checkout \
  --capabilities=search \
  --capabilities=actions \
  --weight=2
```

**When AI Will Use This Node:**
- ✅ "Show me products"
- ✅ "What's in stock?"
- ✅ "Recent orders"
- ✅ "Customer purchases"
- ✅ "Shopping cart items"

---

### **Example 2: Blog/Content Platform**

```bash
php artisan ai-engine:node-register \
  "Blog Platform" \
  https://blog.example.com \
  --description="Content management system with articles, tutorials, and documentation. Contains blog posts, technical guides, and educational content." \
  --domains=blog \
  --domains=content \
  --domains=documentation \
  --data-types=posts \
  --data-types=articles \
  --data-types=tutorials \
  --data-types=guides \
  --keywords=blog \
  --keywords=article \
  --keywords=tutorial \
  --keywords=guide \
  --keywords=documentation \
  --keywords=learn \
  --capabilities=search \
  --weight=1
```

**When AI Will Use This Node:**
- ✅ "Find tutorials about Laravel"
- ✅ "Show me blog posts"
- ✅ "Latest articles"
- ✅ "Documentation on..."
- ✅ "How to guides"

---

### **Example 3: CRM System**

```bash
php artisan ai-engine:node-register \
  "CRM System" \
  https://crm.example.com \
  --description="Customer relationship management system tracking leads, contacts, deals, and sales pipeline. Manages customer interactions and sales processes." \
  --domains=crm \
  --domains=sales \
  --data-types=leads \
  --data-types=contacts \
  --data-types=deals \
  --data-types=customers \
  --data-types=pipeline \
  --keywords=crm \
  --keywords=sales \
  --keywords=leads \
  --keywords=contacts \
  --keywords=deals \
  --keywords=pipeline \
  --capabilities=search \
  --capabilities=actions \
  --weight=1
```

**When AI Will Use This Node:**
- ✅ "Show me recent leads"
- ✅ "Sales pipeline status"
- ✅ "Contact information for..."
- ✅ "Active deals"
- ✅ "Customer interactions"

---

### **Example 4: Support/Helpdesk**

```bash
php artisan ai-engine:node-register \
  "Support System" \
  https://support.example.com \
  --description="Customer support and helpdesk system with tickets, FAQs, and knowledge base. Handles customer inquiries and support requests." \
  --domains=support \
  --domains=helpdesk \
  --data-types=tickets \
  --data-types=faqs \
  --data-types=knowledge-base \
  --keywords=support \
  --keywords=help \
  --keywords=ticket \
  --keywords=faq \
  --keywords=issue \
  --keywords=problem \
  --capabilities=search \
  --weight=1
```

**When AI Will Use This Node:**
- ✅ "Open support tickets"
- ✅ "FAQ about..."
- ✅ "Help with..."
- ✅ "Customer issues"
- ✅ "Support requests"

---

### **Example 5: Analytics/Reporting**

```bash
php artisan ai-engine:node-register \
  "Analytics Platform" \
  https://analytics.example.com \
  --description="Business analytics and reporting system with metrics, dashboards, and insights. Provides data analysis and business intelligence." \
  --domains=analytics \
  --domains=reporting \
  --domains=business-intelligence \
  --data-types=metrics \
  --data-types=reports \
  --data-types=dashboards \
  --data-types=insights \
  --keywords=analytics \
  --keywords=metrics \
  --keywords=reports \
  --keywords=statistics \
  --keywords=data \
  --keywords=insights \
  --capabilities=search \
  --weight=1
```

**When AI Will Use This Node:**
- ✅ "Show me analytics"
- ✅ "Sales metrics"
- ✅ "Generate report"
- ✅ "Dashboard data"
- ✅ "Business insights"

---

## 🎯 Best Practices

### **1. Write Clear Descriptions**

✅ **Good:**
```
"E-commerce platform with product catalog, inventory management, and order processing"
```

❌ **Bad:**
```
"Store"
```

### **2. Use Specific Domains**

✅ **Good:**
```
--domains=ecommerce --domains=retail --domains=inventory
```

❌ **Bad:**
```
--domains=general
```

### **3. Include Relevant Data Types**

✅ **Good:**
```
--data-types=products --data-types=orders --data-types=customers
```

❌ **Bad:**
```
--data-types=data
```

### **4. Add Comprehensive Keywords**

✅ **Good:**
```
--keywords=shop --keywords=buy --keywords=cart --keywords=checkout --keywords=purchase
```

❌ **Bad:**
```
--keywords=stuff
```

### **5. Think Like a User**

Ask yourself: **"What would a user say when they want data from this node?"**

Examples:
- "Show me products" → ecommerce node
- "Find blog posts" → blog node
- "Recent support tickets" → support node

---

## 🔄 Automatic vs Manual Node Selection

### **Automatic (Recommended):**

The AI automatically selects nodes based on query context:

```php
// User asks: "Show me recent products"
// AI automatically detects: ecommerce node needed
// Searches: E-commerce Store node
```

### **Manual (Optional):**

You can still manually specify nodes:

```php
$results = $federatedSearch->search(
    query: 'products',
    nodeIds: [1, 2], // Specific nodes
    limit: 10
);
```

---

## 📊 Node Information in AI Prompts

When the AI analyzes a query, it sees:

```
AVAILABLE NODES:

Node: E-commerce Store (slug: ecommerce-store)
  Capabilities: search, actions
  Description: Online shopping platform with products, orders, and customer data
  Domains: ecommerce, retail
  Data Types: products, orders, customers, inventory
  Keywords: shopping, store, buy, cart, checkout
  Status: ✅ Healthy

Node: Blog Platform (slug: blog-platform)
  Capabilities: search
  Description: Content management system with articles and tutorials
  Domains: blog, content
  Data Types: posts, articles, tutorials
  Keywords: blog, article, tutorial, guide
  Status: ✅ Healthy
```

The AI uses this information to make intelligent decisions!

---

## 🧪 Testing Node Selection

### **Test with Different Queries:**

```bash
# Test e-commerce node selection
"Show me products"
"What's in stock?"
"Recent orders"

# Test blog node selection
"Find tutorials"
"Latest articles"
"Blog posts about Laravel"

# Test CRM node selection
"Show me leads"
"Sales pipeline"
"Contact information"
```

### **View AI Decision:**

The AI will log which nodes it selected and why (check logs).

---

## 🎨 Real-World Example

### **Multi-Application Setup:**

```bash
# Register E-commerce
php artisan ai-engine:node-register \
  "Main Store" \
  https://shop.example.com \
  --description="Primary e-commerce store with 10,000+ products" \
  --domains=ecommerce \
  --data-types=products --data-types=orders \
  --keywords=shop --keywords=buy

# Register Blog
php artisan ai-engine:node-register \
  "Tech Blog" \
  https://blog.example.com \
  --description="Technology blog with Laravel tutorials and guides" \
  --domains=blog \
  --data-types=posts --data-types=tutorials \
  --keywords=blog --keywords=tutorial

# Register CRM
php artisan ai-engine:node-register \
  "Sales CRM" \
  https://crm.example.com \
  --description="Sales CRM tracking leads and deals" \
  --domains=crm --domains=sales \
  --data-types=leads --data-types=deals \
  --keywords=sales --keywords=leads
```

### **User Queries:**

1. **"Show me products"** → Selects: Main Store
2. **"Find Laravel tutorials"** → Selects: Tech Blog
3. **"Recent sales leads"** → Selects: Sales CRM
4. **"Products and blog posts"** → Selects: Main Store + Tech Blog

---

## 🚀 Quick Start

### **Minimal Registration:**

```bash
php artisan ai-engine:node-register \
  "My Node" \
  https://example.com \
  --description="Brief description of what this node does"
```

### **Full Registration:**

```bash
php artisan ai-engine:node-register \
  "My Node" \
  https://example.com \
  --description="Detailed description of capabilities and data" \
  --domains=domain1 --domains=domain2 \
  --data-types=type1 --data-types=type2 \
  --keywords=keyword1 --keywords=keyword2 \
  --capabilities=search --capabilities=actions \
  --weight=2
```

---

## 💡 Pro Tips

1. **Be Descriptive** - The more context, the better the AI's decisions
2. **Use Multiple Keywords** - Think of all ways users might ask
3. **Update Regularly** - Keep descriptions current as nodes evolve
4. **Test Queries** - Try different user questions to verify selection
5. **Monitor Logs** - Check which nodes are being selected

---

## 📚 Summary

**The AI automatically selects nodes based on:**
- ✅ Description (most important)
- ✅ Domains
- ✅ Data types
- ✅ Keywords
- ✅ Health status

**You should provide:**
- ✅ Clear, detailed descriptions
- ✅ Relevant business domains
- ✅ Specific data types
- ✅ Comprehensive keywords

**Result:**
- 🎯 Intelligent, automatic node selection
- 🚀 Better user experience
- 💡 Context-aware search
- ✨ No manual node specification needed

---

**🎉 Your nodes are now AI-ready!**

