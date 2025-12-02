# 🔍 Node Connection Logging Guide

## Quick Start

### **View Recent Logs:**
```bash
php artisan ai-engine:node-logs
```

### **View Only Errors:**
```bash
php artisan ai-engine:node-logs --errors-only
```

### **Follow Logs in Real-Time:**
```bash
php artisan ai-engine:node-logs --follow
```

### **Filter by Node:**
```bash
php artisan ai-engine:node-logs --node=bites-posts-node
```

---

## 📋 Log Levels

### **1. ERROR (❌)**
Critical failures that prevent node communication:
```
Node ping exception
- Connection refused
- Timeout
- DNS resolution failed
- SSL/TLS errors
```

### **2. WARNING (⚠️)**
Non-critical issues:
```
Node ping failed
- HTTP 404, 500, 503
- Invalid response format
- Circuit breaker opened
```

### **3. DEBUG (ℹ️)**
Informational messages:
```
Node ping successful
- Response time
- Node metadata updates
- Circuit breaker status
```

---

## 🎯 Common Error Messages

### **Error 1: Connection Refused**
```
Node ping exception
error: Connection refused
error_class: GuzzleHttp\Exception\ConnectException
```

**Causes:**
- Node server not running
- Wrong port
- Firewall blocking

**Fix:**
```bash
# Check if node is running
curl http://bites-api.test/api/ai-engine/health

# Start node server
cd /path/to/child/node
php artisan serve
```

---

### **Error 2: Timeout**
```
Node ping exception
error: cURL error 28: Operation timed out
error_class: GuzzleHttp\Exception\ConnectException
```

**Causes:**
- Node too slow
- Network issues
- Server overloaded

**Fix:**
```bash
# Increase timeout in .env
AI_ENGINE_REQUEST_TIMEOUT=60

# Or in config
php artisan config:clear
```

---

### **Error 3: 404 Not Found**
```
Node ping failed
status_code: 404
```

**Causes:**
- Routes not loaded
- Wrong URL
- API not published

**Fix:**
```bash
# On child node
php artisan route:clear
php artisan config:clear
php artisan cache:clear

# Verify route exists
php artisan route:list | grep ai-engine
```

---

### **Error 4: ngrok Offline**
```
Node ping failed
error: The endpoint xxx.ngrok-free.app is offline
```

**Causes:**
- ngrok tunnel stopped
- ngrok URL expired

**Fix:**
```bash
# Restart ngrok
ngrok http 8000

# Update node URL
php artisan tinker
```

```php
$node = \LaravelAIEngine\Models\AINode::find(6);
$node->update(['url' => 'https://NEW-URL.ngrok-free.app']);
```

---

### **Error 5: SSL Certificate Error**
```
Node ping exception
error: SSL certificate problem
error_class: GuzzleHttp\Exception\RequestException
```

**Causes:**
- Self-signed certificate
- Expired certificate
- Certificate mismatch

**Fix:**
```bash
# For development only (not production!)
# Disable SSL verification in Http client
```

---

## 🔧 Enable Detailed Logging

### **1. Update .env:**
```env
# Enable node logging
AI_ENGINE_NODE_LOGGING=true

# Log all requests
AI_ENGINE_LOG_REQUESTS=true

# Log all responses
AI_ENGINE_LOG_RESPONSES=true

# Log all errors
AI_ENGINE_LOG_ERRORS=true

# Log circuit breaker events
AI_ENGINE_LOG_CIRCUIT_BREAKER=true

# Enable debug mode
APP_DEBUG=true
```

### **2. Clear Config:**
```bash
php artisan config:clear
```

### **3. Test Connection:**
```bash
php artisan ai-engine:node-ping
```

### **4. View Logs:**
```bash
php artisan ai-engine:node-logs --follow
```

---

## 📊 Log Format

### **Success Log:**
```json
[2025-12-02 00:05:49] local.DEBUG: Node ping successful {
  "node_id": 6,
  "node_slug": "bites-posts-node",
  "duration_ms": 145
}
```

### **Failure Log:**
```json
[2025-12-02 00:05:49] local.WARNING: Node ping failed {
  "node_id": 6,
  "node_slug": "bites-posts-node",
  "status_code": 404
}
```

### **Exception Log:**
```json
[2025-12-02 00:05:49] local.ERROR: Node ping exception {
  "node_id": 6,
  "node_slug": "bites-posts-node",
  "node_name": "Bites Posts Node",
  "node_url": "https://xxx.ngrok-free.app",
  "health_url": "https://xxx.ngrok-free.app/api/ai-engine/health",
  "error": "cURL error 6: Could not resolve host",
  "error_class": "GuzzleHttp\\Exception\\ConnectException",
  "trace": "..." // Only in debug mode
}
```

---

## 🎨 Command Options

### **Basic Usage:**
```bash
# Show last 50 lines (default)
php artisan ai-engine:node-logs

# Show last 100 lines
php artisan ai-engine:node-logs --lines=100

# Show only errors
php artisan ai-engine:node-logs --errors-only

# Filter by node
php artisan ai-engine:node-logs --node=bites-posts-node

# Follow logs in real-time
php artisan ai-engine:node-logs --follow

# Combine options
php artisan ai-engine:node-logs --follow --errors-only --node=bites-posts-node
```

---

## 🔍 Manual Log Inspection

### **View Raw Logs:**
```bash
# View last 100 lines
tail -n 100 storage/logs/laravel.log

# Follow logs
tail -f storage/logs/laravel.log

# Search for node errors
grep "Node ping" storage/logs/laravel.log

# Search for specific node
grep "bites-posts-node" storage/logs/laravel.log

# Search for errors only
grep "ERROR.*Node ping" storage/logs/laravel.log
```

---

## 🎯 Debugging Workflow

### **Step 1: Check Node Status**
```bash
php artisan ai-engine:node-list
```

### **Step 2: Ping Node**
```bash
php artisan ai-engine:node-ping
```

### **Step 3: View Logs**
```bash
php artisan ai-engine:node-logs --errors-only
```

### **Step 4: Test Manually**
```bash
# Test health endpoint directly
curl -v http://bites-api.test/api/ai-engine/health

# With authentication
curl -v -H "Authorization: Bearer YOUR_TOKEN" \
  http://bites-api.test/api/ai-engine/health
```

### **Step 5: Check Circuit Breaker**
```bash
php artisan tinker
```

```php
$node = \LaravelAIEngine\Models\AINode::find(6);
$cb = app(\LaravelAIEngine\Services\Node\CircuitBreakerService::class);

// Check state
$stats = $cb->getStatistics($node);
print_r($stats);

// Reset if needed
$cb->reset($node);
```

---

## 📈 Log Analysis

### **Count Errors:**
```bash
grep -c "ERROR.*Node ping" storage/logs/laravel.log
```

### **Find Most Common Errors:**
```bash
grep "ERROR.*Node ping" storage/logs/laravel.log | \
  grep -oP '"error":"[^"]*"' | \
  sort | uniq -c | sort -rn
```

### **Check Error Rate:**
```bash
# Total pings
grep -c "Node ping" storage/logs/laravel.log

# Failed pings
grep -c "Node ping failed\|Node ping exception" storage/logs/laravel.log
```

### **Average Response Time:**
```bash
grep "duration_ms" storage/logs/laravel.log | \
  grep -oP 'duration_ms":\K\d+' | \
  awk '{sum+=$1; count++} END {print sum/count}'
```

---

## 🚨 Monitoring & Alerts

### **Setup Continuous Monitoring:**
```bash
# Monitor in separate terminal
php artisan ai-engine:monitor-nodes --auto-recover
```

### **Setup Log Monitoring:**
```bash
# Watch for errors
php artisan ai-engine:node-logs --follow --errors-only
```

### **Setup External Monitoring:**
```bash
# Use tools like:
# - Laravel Telescope
# - Sentry
# - Bugsnag
# - New Relic
```

---

## 💡 Pro Tips

### **1. Use Log Rotation**
```bash
# Prevent logs from growing too large
# Configure in config/logging.php
'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'level' => 'debug',
    'days' => 14,
],
```

### **2. Separate Node Logs**
```bash
# Create dedicated channel in config/logging.php
'ai-engine' => [
    'driver' => 'daily',
    'path' => storage_path('logs/ai-engine.log'),
    'level' => 'debug',
    'days' => 14,
],
```

### **3. Use Laravel Telescope**
```bash
composer require laravel/telescope
php artisan telescope:install
php artisan migrate

# View logs in browser
http://your-app.test/telescope
```

### **4. Export Logs for Analysis**
```bash
# Export last 1000 node logs
grep "Node ping" storage/logs/laravel.log | \
  tail -n 1000 > node-logs-export.txt
```

---

## 🎯 Quick Reference

### **View Logs:**
```bash
php artisan ai-engine:node-logs [--lines=N] [--errors-only] [--node=slug] [--follow]
```

### **Enable Logging:**
```env
AI_ENGINE_NODE_LOGGING=true
AI_ENGINE_LOG_REQUESTS=true
AI_ENGINE_LOG_RESPONSES=true
AI_ENGINE_LOG_ERRORS=true
```

### **Log Locations:**
```
Main: storage/logs/laravel.log
AI Engine: storage/logs/ai-engine.log (if configured)
```

### **Common Commands:**
```bash
# View errors
php artisan ai-engine:node-logs --errors-only

# Follow specific node
php artisan ai-engine:node-logs --follow --node=bites-posts-node

# Manual inspection
tail -f storage/logs/laravel.log | grep "Node ping"
```

---

**🎉 Happy Debugging!** 🔍✨
