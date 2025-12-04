# 🔒 SSL Certificate Verification Configuration

## Overview

Control SSL certificate verification for node connections. Useful for development environments with self-signed certificates or testing scenarios.

---

## ⚙️ Configuration

### **Method 1: Environment Variable (Recommended)**

```env
# .env file
AI_ENGINE_VERIFY_SSL=false  # Disable SSL verification (development only!)
```

### **Method 2: Config File**

```php
// config/ai-engine.php
'nodes' => [
    'verify_ssl' => env('AI_ENGINE_VERIFY_SSL', true),
],
```

---

## 🎯 Use Cases

### **1. Development with Self-Signed Certificates**
```env
# When using self-signed SSL certificates in development
AI_ENGINE_VERIFY_SSL=false
```

### **2. Testing with ngrok**
```env
# ngrok sometimes has SSL certificate issues
AI_ENGINE_VERIFY_SSL=false
```

### **3. Local HTTPS Development**
```env
# Testing with localhost over HTTPS
AI_ENGINE_VERIFY_SSL=false
```

### **4. Docker Development**
```env
# Docker containers with self-signed certs
AI_ENGINE_VERIFY_SSL=false
```

---

## ⚠️ Security Warning

### **NEVER disable SSL verification in production!**

```env
# ❌ NEVER do this in production
AI_ENGINE_VERIFY_SSL=false

# ✅ Always enable in production
AI_ENGINE_VERIFY_SSL=true
```

### **Why?**
- Disabling SSL verification makes your application vulnerable to man-in-the-middle attacks
- SSL certificates ensure you're connecting to the correct server
- Production environments should always use valid SSL certificates

---

## 📋 Setup Guide

### **Development Environment:**

1. **Update .env:**
```env
AI_ENGINE_VERIFY_SSL=false
```

2. **Clear config:**
```bash
php artisan config:clear
```

3. **Test connection:**
```bash
php artisan ai-engine:node-ping
```

### **Production Environment:**

1. **Ensure SSL is enabled:**
```env
AI_ENGINE_VERIFY_SSL=true
# Or remove the line (defaults to true)
```

2. **Use valid SSL certificates:**
   - Let's Encrypt (free)
   - Commercial SSL providers
   - Cloud provider certificates

3. **Test connection:**
```bash
php artisan ai-engine:node-ping
```

---

## 🔍 Troubleshooting

### **Error: SSL certificate problem**

```
Node ping exception
error: SSL certificate problem: self signed certificate
error_class: GuzzleHttp\Exception\RequestException
```

**Solution:**
```env
# For development only
AI_ENGINE_VERIFY_SSL=false
```

### **Error: Certificate verification failed**

```
Node ping exception
error: cURL error 60: SSL certificate problem
```

**Solutions:**

**Option 1: Disable verification (development)**
```env
AI_ENGINE_VERIFY_SSL=false
```

**Option 2: Install valid certificate (production)**
```bash
# Using Let's Encrypt
certbot certonly --webroot -w /var/www/html -d yourdomain.com
```

**Option 3: Update CA certificates**
```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install ca-certificates

# macOS
brew install ca-certificates
```

---

## 🧪 Testing

### **Test with SSL Verification Enabled:**
```bash
# Enable SSL verification
echo "AI_ENGINE_VERIFY_SSL=true" >> .env
php artisan config:clear

# Test connection
php artisan ai-engine:node-ping

# View logs
php artisan ai-engine:node-logs --errors-only
```

### **Test with SSL Verification Disabled:**
```bash
# Disable SSL verification
echo "AI_ENGINE_VERIFY_SSL=false" >> .env
php artisan config:clear

# Test connection
php artisan ai-engine:node-ping

# View logs
php artisan ai-engine:node-logs --errors-only
```

---

## 📊 Configuration Matrix

| Environment | SSL Verification | Valid Certificate | Recommended |
|-------------|------------------|-------------------|-------------|
| Production | ✅ Enabled | ✅ Required | ✅ Yes |
| Staging | ✅ Enabled | ✅ Required | ✅ Yes |
| Development | ❌ Optional | ❌ Optional | ⚠️ OK |
| Local | ❌ Optional | ❌ Optional | ⚠️ OK |
| Testing | ❌ Optional | ❌ Optional | ⚠️ OK |

---

## 💡 Best Practices

### **1. Use Environment-Specific Configuration**

```env
# .env.production
AI_ENGINE_VERIFY_SSL=true

# .env.local
AI_ENGINE_VERIFY_SSL=false

# .env.testing
AI_ENGINE_VERIFY_SSL=false
```

### **2. Document SSL Configuration**

```php
// config/ai-engine.php
'nodes' => [
    // SSL certificate verification
    // Set to false only in development with self-signed certificates
    // NEVER disable in production!
    'verify_ssl' => env('AI_ENGINE_VERIFY_SSL', true),
],
```

### **3. Use Valid Certificates in Production**

```bash
# Free SSL with Let's Encrypt
certbot certonly --nginx -d yourdomain.com

# Or use cloud provider certificates
# AWS Certificate Manager
# Google Cloud SSL
# Azure SSL
```

### **4. Monitor SSL Expiration**

```bash
# Check certificate expiration
openssl s_client -connect yourdomain.com:443 -servername yourdomain.com 2>/dev/null | \
  openssl x509 -noout -dates
```

---

## 🎯 Quick Reference

### **Enable SSL Verification:**
```env
AI_ENGINE_VERIFY_SSL=true
```

### **Disable SSL Verification (Development Only):**
```env
AI_ENGINE_VERIFY_SSL=false
```

### **Test Connection:**
```bash
php artisan ai-engine:node-ping
```

### **View SSL Errors:**
```bash
php artisan ai-engine:node-logs --errors-only
```

### **Check Configuration:**
```bash
php artisan tinker --execute="echo config('ai-engine.nodes.verify_ssl') ? 'enabled' : 'disabled';"
```

---

## 🚀 Real-World Examples

### **Example 1: ngrok Development**

```bash
# Start ngrok
ngrok http 8000

# Update .env
AI_ENGINE_VERIFY_SSL=false

# Register node
php artisan ai-engine:node-register \
  "My Node" https://abc123.ngrok-free.app \
  --description="Development node"

# Test
php artisan ai-engine:node-ping
```

### **Example 2: Docker Development**

```yaml
# docker-compose.yml
services:
  app:
    environment:
      - AI_ENGINE_VERIFY_SSL=false
```

### **Example 3: Production Deployment**

```bash
# Install Let's Encrypt certificate
certbot certonly --nginx -d api.example.com

# Ensure SSL verification is enabled
grep AI_ENGINE_VERIFY_SSL .env || echo "AI_ENGINE_VERIFY_SSL=true" >> .env

# Test
php artisan ai-engine:node-ping
```

---

## 📚 Related Documentation

- **NODE-SETUP-GUIDE.md** - Child node setup
- **NODE-LOGGING-GUIDE.md** - Logging and debugging
- **NODE-COMMANDS-REFERENCE.md** - All commands
- **TROUBLESHOOTING.md** - Common issues

---

**🔒 Remember: Security first! Only disable SSL verification in development.** ✨
