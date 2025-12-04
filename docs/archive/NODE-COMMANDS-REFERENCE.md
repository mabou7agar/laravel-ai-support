# 🎯 Node Commands - Quick Reference

## 📋 All Available Commands

### **1. Register Node**
```bash
php artisan ai-engine:node-register <name> <url> [options]
```

**Options:**
- `--description=` - Node description (what it does)
- `--type=` - Node type (master/child, default: child)
- `--capabilities=*` - Capabilities (search, actions, rag)
- `--domains=*` - Business domains (ecommerce, blog, crm)
- `--data-types=*` - Data types (products, posts, customers)
- `--keywords=*` - Search keywords
- `--weight=` - Load balancing weight (default: 1)

**Example:**
```bash
php artisan ai-engine:node-register \
  "Bites Posts Node" \
  https://bites-api.test \
  --description="Social media platform with posts" \
  --domains=social-media --domains=posts \
  --data-types=posts --data-types=comments \
  --keywords=bites --keywords=social \
  --weight=2
```

---

### **2. Update Node**
```bash
php artisan ai-engine:node-update <node-id-or-slug> [options]
```

**Options:**
- `--url=` - Update node URL
- `--name=` - Update node name
- `--description=` - Update description
- `--type=` - Update type (master/child)
- `--status=` - Update status (active/inactive/maintenance/error)
- `--weight=` - Update weight
- `--capabilities=*` - Update capabilities
- `--domains=*` - Update domains
- `--data-types=*` - Update data types
- `--keywords=*` - Update keywords
- `--regenerate-key` - Regenerate API key

**Examples:**
```bash
# Update URL
php artisan ai-engine:node-update 6 --url=https://new-url.com

# Update multiple fields
php artisan ai-engine:node-update bites-posts-node \
  --url=https://new-url.com \
  --description="Updated description" \
  --status=active

# Regenerate API key
php artisan ai-engine:node-update 6 --regenerate-key
```

---

### **3. List Nodes**
```bash
php artisan ai-engine:node-list [options]
```

**Options:**
- `--status=` - Filter by status (active/inactive/maintenance/error)
- `--type=` - Filter by type (master/child)

**Examples:**
```bash
# List all nodes
php artisan ai-engine:node-list

# List only active nodes
php artisan ai-engine:node-list --status=active

# List only child nodes
php artisan ai-engine:node-list --type=child
```

---

### **4. Ping Nodes**
```bash
php artisan ai-engine:node-ping
```

Tests connectivity to all registered nodes.

**Output:**
```
✅ Bites Posts Node: Healthy (145ms)
❌ Blog Platform: Unhealthy (timeout)
```

---

### **5. Node Statistics**
```bash
php artisan ai-engine:node-stats
```

Shows overall statistics about all nodes.

**Output:**
```
Total Nodes: 3
Active: 2
Inactive: 1
Healthy: 2
Average Response Time: 125ms
```

---

### **6. Monitor Nodes**
```bash
php artisan ai-engine:monitor-nodes [options]
```

**Options:**
- `--interval=` - Ping interval in seconds (default: 60)
- `--auto-recover` - Attempt to recover unhealthy nodes

**Example:**
```bash
# Monitor with 30-second interval and auto-recovery
php artisan ai-engine:monitor-nodes --interval=30 --auto-recover
```

---

### **7. View Logs**
```bash
php artisan ai-engine:node-logs [options]
```

**Options:**
- `--lines=` - Number of lines to show (default: 50)
- `--follow` - Follow logs in real-time
- `--errors-only` - Show only errors
- `--node=` - Filter by node slug

**Examples:**
```bash
# View last 100 lines
php artisan ai-engine:node-logs --lines=100

# Show only errors
php artisan ai-engine:node-logs --errors-only

# Follow specific node
php artisan ai-engine:node-logs --follow --node=bites-posts-node

# Combine options
php artisan ai-engine:node-logs --follow --errors-only
```

---

### **8. Test System**
```bash
php artisan ai-engine:test-nodes [options]
```

**Options:**
- `--quick` - Run quick tests only (10 tests)
- `--detailed` - Show detailed output

**Examples:**
```bash
# Full test (14 tests)
php artisan ai-engine:test-nodes

# Quick test
php artisan ai-engine:test-nodes --quick

# Detailed output
php artisan ai-engine:test-nodes --detailed
```

---

### **9. Demo System**
```bash
php artisan ai-engine:demo-nodes [options]
```

**Options:**
- `--skip-registration` - Skip node registration
- `--cleanup` - Clean up demo nodes after test

**Examples:**
```bash
# Run full demo
php artisan ai-engine:demo-nodes

# Run and cleanup
php artisan ai-engine:demo-nodes --cleanup

# Use existing nodes
php artisan ai-engine:demo-nodes --skip-registration
```

---

## 🎯 Common Workflows

### **Workflow 1: Register New Node**
```bash
# 1. Register node
php artisan ai-engine:node-register \
  "My Node" https://mynode.test \
  --description="My node description" \
  --domains=mydomain

# 2. Test connection
php artisan ai-engine:node-ping

# 3. View logs
php artisan ai-engine:node-logs --errors-only
```

---

### **Workflow 2: Update Node URL**
```bash
# 1. Update URL
php artisan ai-engine:node-update 6 --url=https://new-url.com

# 2. Test connection
php artisan ai-engine:node-ping

# 3. Check status
php artisan ai-engine:node-list
```

---

### **Workflow 3: Debug Connection Issues**
```bash
# 1. Check node status
php artisan ai-engine:node-list

# 2. Ping nodes
php artisan ai-engine:node-ping

# 3. View error logs
php artisan ai-engine:node-logs --errors-only

# 4. Test manually
curl http://node-url/api/ai-engine/health

# 5. Check circuit breaker
php artisan tinker
```

```php
$node = \LaravelAIEngine\Models\AINode::find(6);
$cb = app(\LaravelAIEngine\Services\Node\CircuitBreakerService::class);
$stats = $cb->getStatistics($node);
print_r($stats);
```

---

### **Workflow 4: Monitor Production**
```bash
# Terminal 1: Monitor nodes
php artisan ai-engine:monitor-nodes --interval=60 --auto-recover

# Terminal 2: Follow logs
php artisan ai-engine:node-logs --follow --errors-only

# Terminal 3: Check stats periodically
watch -n 60 'php artisan ai-engine:node-stats'
```

---

## 🔧 Troubleshooting Commands

### **Connection Issues:**
```bash
# 1. List nodes
php artisan ai-engine:node-list

# 2. Ping specific node
php artisan ai-engine:node-ping

# 3. View logs
php artisan ai-engine:node-logs --errors-only --node=problematic-node

# 4. Update URL if needed
php artisan ai-engine:node-update <node> --url=<new-url>

# 5. Test again
php artisan ai-engine:node-ping
```

---

### **Circuit Breaker Issues:**
```bash
# Check circuit breaker state
php artisan tinker
```

```php
$node = \LaravelAIEngine\Models\AINode::find(6);
$cb = app(\LaravelAIEngine\Services\Node\CircuitBreakerService::class);

// Get statistics
$stats = $cb->getStatistics($node);
print_r($stats);

// Reset if needed
$cb->reset($node);
```

---

### **Performance Issues:**
```bash
# Check response times
php artisan ai-engine:node-stats

# Monitor in real-time
php artisan ai-engine:monitor-nodes --interval=30

# View detailed logs
php artisan ai-engine:node-logs --lines=200
```

---

## 💡 Pro Tips

### **1. Use Aliases**
```bash
# Add to ~/.zshrc or ~/.bashrc
alias node-list='php artisan ai-engine:node-list'
alias node-ping='php artisan ai-engine:node-ping'
alias node-logs='php artisan ai-engine:node-logs'
alias node-stats='php artisan ai-engine:node-stats'
```

### **2. Quick Status Check**
```bash
# One-liner for quick status
php artisan ai-engine:node-list && \
php artisan ai-engine:node-ping && \
php artisan ai-engine:node-stats
```

### **3. Export Node Configuration**
```bash
php artisan tinker --execute="
\$nodes = \LaravelAIEngine\Models\AINode::all();
foreach (\$nodes as \$node) {
    echo json_encode([
        'name' => \$node->name,
        'url' => \$node->url,
        'type' => \$node->type,
        'status' => \$node->status,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
}
"
```

### **4. Batch Update**
```bash
# Update all nodes to active
php artisan tinker --execute="
\LaravelAIEngine\Models\AINode::query()->update(['status' => 'active']);
echo 'All nodes updated to active' . PHP_EOL;
"
```

---

## 📚 Related Documentation

- **NODE-REGISTRATION-GUIDE.md** - Complete registration guide
- **NODE-SETUP-GUIDE.md** - Child node setup
- **NODE-LOGGING-GUIDE.md** - Logging and debugging
- **TESTING-AND-DEPLOYMENT-GUIDE.md** - Testing guide
- **FINAL-SUMMARY.md** - Complete summary

---

## 🎯 Quick Reference Table

| Command | Purpose | Key Options |
|---------|---------|-------------|
| `node-register` | Register new node | `--description`, `--domains`, `--keywords` |
| `node-update` | Update existing node | `--url`, `--status`, `--regenerate-key` |
| `node-list` | List all nodes | `--status`, `--type` |
| `node-ping` | Test connectivity | None |
| `node-stats` | Show statistics | None |
| `monitor-nodes` | Continuous monitoring | `--interval`, `--auto-recover` |
| `node-logs` | View logs | `--follow`, `--errors-only`, `--node` |
| `test-nodes` | Test system | `--quick`, `--detailed` |
| `demo-nodes` | Run demo | `--cleanup` |

---

**🎉 Complete Node Management at Your Fingertips!** 🚀✨
