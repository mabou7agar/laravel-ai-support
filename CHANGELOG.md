# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2025-08-18

### Added
- **AI Chat Blade Component** - Complete reusable chat component for AI conversations
- **Real-time WebSocket Streaming** - Live streaming of AI responses with automatic fallback
- **Interactive Actions System** - Buttons, quick replies, and custom actions in chat
- **Multi-Engine Chat Support** - OpenAI, Anthropic, and Google Gemini integration
- **Enhanced JavaScript Client** - Modular WebSocket client with UI management
- **Chat API Controller** - RESTful endpoints for messaging and history management
- **Demo Page** - Interactive examples showcasing all chat features
- **Asset Publishing** - JavaScript files publishable to public directory
- **Comprehensive Documentation** - Complete usage guide with examples

### Enhanced
- **Service Provider** - Added Blade component registration and asset publishing
- **Configuration** - Extended config with WebSocket and streaming options
- **Event System** - New events for chat sessions, actions, and streaming
- **Analytics** - Enhanced tracking for chat interactions and streaming
- **Failover System** - Improved automatic provider switching for chat

### Features
- Session-based conversation memory
- Customizable themes (light/dark)
- Mobile-responsive design
- Typing indicators and connection status
- Message history management
- Automatic reconnection with fallback
- CSRF protection and input validation
- Rate limiting and security features

### Technical
- WebSocket server management commands
- Enhanced error handling and logging
- Performance optimizations for streaming
- Modular JavaScript architecture
- Comprehensive test coverage

## [2.0.0] - Previous Release

### Added
- Initial multi-AI engine support
- Credit management system
- Basic streaming capabilities
- Analytics and monitoring
- Failover mechanisms

---

## Upgrade Guide

### From 2.0.x to 2.1.0

1. **Publish New Assets:**
   ```bash
   php artisan vendor:publish --tag=ai-engine-assets
   ```

2. **Update Configuration:**
   ```bash
   php artisan vendor:publish --tag=ai-engine-config --force
   ```

3. **Start WebSocket Server (Optional):**
   ```bash
   php artisan ai-engine:streaming-server start --port=8080
   ```

4. **Use New Chat Component:**
   ```blade
   <x-ai-chat session-id="my-chat" placeholder="Ask me anything..." />
   ```

### Breaking Changes
- None in this release

### Deprecations
- None in this release

---

## Support

For support and questions:
- Documentation: [README-AI-CHAT.md](README-AI-CHAT.md)
- Issues: [GitHub Issues](https://github.com/m-tech-stack/laravel-ai-engine/issues)
- Email: m.abou7agar@gmail.com
