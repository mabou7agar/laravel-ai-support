# AI Chat Component

A powerful, reusable Laravel Blade component for AI conversations with real-time streaming, interactive actions, and multi-engine support.

## Features

- 🚀 **Real-time Streaming** - WebSocket-based streaming with HTTP fallback
- 🎯 **Interactive Actions** - Buttons, quick replies, and custom actions
- 🧠 **Multi-Engine Support** - OpenAI, Anthropic, Google Gemini
- 💾 **Conversation Memory** - Persistent chat history
- 🎨 **Customizable UI** - Light/dark themes, responsive design
- ⚡ **Automatic Failover** - Seamless provider switching
- 📱 **Mobile Responsive** - Works on all devices
- 🔧 **Highly Configurable** - Extensive customization options

## Quick Start

### 1. Installation

```bash
composer require m-tech-stack/laravel-ai-engine
php artisan vendor:publish --tag=ai-engine-config
php artisan vendor:publish --tag=ai-engine-assets
```

### 2. Configuration

Add your AI provider API keys to `.env`:

```env
OPENAI_API_KEY=your_openai_key
ANTHROPIC_API_KEY=your_anthropic_key
GEMINI_API_KEY=your_gemini_key
```

### 3. Basic Usage

```blade
<x-ai-chat 
    session-id="my-chat-session"
    placeholder="Ask me anything..."
    :suggestions="['Hello!', 'Help me with code', 'Tell me a joke']"
/>
```

## Component Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `session-id` | string | auto-generated | Unique session identifier |
| `engine` | string | 'openai' | AI engine (openai, anthropic, gemini) |
| `model` | string | 'gpt-4o' | AI model to use |
| `streaming` | boolean | true | Enable real-time streaming |
| `actions` | boolean | true | Enable interactive actions |
| `memory` | boolean | true | Enable conversation memory |
| `theme` | string | 'light' | UI theme (light, dark) |
| `height` | string | '600px' | Chat container height |
| `placeholder` | string | 'Type your message...' | Input placeholder text |
| `suggestions` | array | [] | Quick reply suggestions |
| `config` | array | [] | Additional configuration |

## Advanced Examples

### OpenAI GPT-4 with Custom Settings

```blade
<x-ai-chat 
    session-id="gpt4-chat"
    engine="openai"
    model="gpt-4o"
    theme="dark"
    height="500px"
    placeholder="Chat with GPT-4..."
    :suggestions="['Explain quantum physics', 'Write a poem', 'Debug my code']"
    :config="[
        'max_messages' => 100,
        'typing_indicator' => true,
        'auto_scroll' => true,
        'enable_markdown' => true,
        'enable_copy' => true,
    ]"
/>
```

### Anthropic Claude with Memory Disabled

```blade
<x-ai-chat 
    session-id="claude-stateless"
    engine="anthropic"
    model="claude-3-5-sonnet-20241022"
    :memory="false"
    placeholder="Ask Claude (no memory)..."
    :suggestions="['One-time question', 'Quick help', 'Analysis']"
/>
```

### Google Gemini with Custom Actions

```blade
<x-ai-chat 
    session-id="gemini-pro"
    engine="gemini"
    model="gemini-1.5-pro"
    :actions="true"
    :suggestions="['Code review', 'Creative writing', 'Problem solving']"
    :config="[
        'websocket_url' => 'ws://localhost:8080',
        'api_endpoint' => '/api/ai-chat',
        'show_timestamps' => true,
        'enable_regenerate' => true,
    ]"
/>
```

## Configuration Options

The `config` array accepts the following options:

```php
[
    'websocket_url' => 'ws://localhost:8080',  // WebSocket server URL
    'api_endpoint' => '/api/ai-chat',          // HTTP API endpoint
    'max_messages' => 50,                      // Maximum messages to display
    'typing_indicator' => true,                // Show typing indicator
    'auto_scroll' => true,                     // Auto-scroll to new messages
    'show_timestamps' => true,                 // Show message timestamps
    'enable_markdown' => true,                 // Parse markdown in responses
    'enable_copy' => true,                     // Show copy buttons
    'enable_regenerate' => true,               // Show regenerate buttons
    'reconnect_interval' => 3000,              // WebSocket reconnect interval (ms)
    'max_reconnect_attempts' => 5,             // Max reconnection attempts
    'heartbeat_interval' => 30000,             // WebSocket heartbeat interval (ms)
]
```

## WebSocket Streaming

For real-time streaming, start the WebSocket server:

```bash
# Start the server
php artisan ai-engine:streaming-server start --host=0.0.0.0 --port=8080

# Check server status
php artisan ai-engine:streaming-server status

# Stop the server
php artisan ai-engine:streaming-server stop
```

The component automatically falls back to HTTP requests if WebSocket is unavailable.

## Interactive Actions

The component supports various interactive action types:

### Button Actions
```php
// Generated automatically based on AI response content
// Examples: Regenerate, Copy, Explain Code, More Details
```

### Quick Reply Actions
```php
// Set via suggestions property
:suggestions="['Yes', 'No', 'Tell me more', 'Explain further']"
```

### Custom Actions
```php
// Implemented via action execution in the controller
// Can trigger custom business logic
```

## API Endpoints

The component uses these API endpoints:

- `POST /api/ai-chat/send` - Send message to AI
- `POST /api/ai-chat/action` - Execute interactive action
- `GET /api/ai-chat/history/{session_id}` - Get chat history
- `DELETE /api/ai-chat/history/{session_id}` - Clear chat history
- `GET /api/ai-chat/engines` - Get available engines

## JavaScript Integration

For advanced customization, use the JavaScript classes directly:

```javascript
// Initialize WebSocket client
const client = new AiChatClient({
    websocketUrl: 'ws://localhost:8080',
    apiEndpoint: '/api/ai-chat'
});

// Initialize UI manager
const chatUI = new AiChatUI('chat-container', {
    sessionId: 'my-session',
    streaming: true,
    actions: true,
    theme: 'dark'
});

// Listen to events
client.on('responseChunk', (data) => {
    console.log('Received chunk:', data.chunk);
});

client.on('responseComplete', (data) => {
    console.log('Response complete:', data);
});
```

## Styling and Themes

### Custom CSS Classes

The component uses these CSS classes for styling:

```css
.ai-chat-container          /* Main container */
.ai-chat-header            /* Header section */
.ai-chat-messages          /* Messages container */
.ai-chat-input             /* Input section */
.message.user              /* User messages */
.message.assistant         /* AI messages */
.message-actions           /* Action buttons */
.typing-indicator          /* Typing animation */
.status-indicator          /* Connection status */
```

### Dark Theme

```blade
<x-ai-chat theme="dark" />
```

### Custom Height

```blade
<x-ai-chat height="800px" />
```

## Events and Analytics

The component fires various events for analytics:

- `AISessionStarted` - When a chat session begins
- `AIActionTriggered` - When an interactive action is executed
- `AIResponseChunk` - For each streaming chunk received
- `AIResponseComplete` - When a response is fully received
- `AIStreamingError` - When streaming encounters an error

## Error Handling

The component includes comprehensive error handling:

- **WebSocket Connection Errors** - Automatic fallback to HTTP
- **API Request Failures** - User-friendly error messages
- **Provider Failures** - Automatic failover to backup providers
- **Rate Limiting** - Graceful handling with retry logic

## Performance Optimization

- **Message Limiting** - Configurable maximum message history
- **Lazy Loading** - Messages loaded on demand
- **Connection Pooling** - Efficient WebSocket management
- **Caching** - Response caching for repeated queries

## Security Features

- **CSRF Protection** - All HTTP requests include CSRF tokens
- **Input Validation** - Server-side validation of all inputs
- **Rate Limiting** - Built-in rate limiting per session
- **Sanitization** - XSS protection for user inputs

## Troubleshooting

### Common Issues

1. **WebSocket Connection Failed**
   ```bash
   # Check if server is running
   php artisan ai-engine:streaming-server status
   
   # Start the server
   php artisan ai-engine:streaming-server start
   ```

2. **API Key Not Working**
   ```bash
   # Verify configuration
   php artisan config:cache
   php artisan config:clear
   ```

3. **Component Not Found**
   ```bash
   # Republish the package
   php artisan vendor:publish --tag=ai-engine-config --force
   ```

### Debug Mode

Enable debug logging in `config/ai-engine.php`:

```php
'debug' => env('AI_ENGINE_DEBUG', false),
'log_level' => 'debug',
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Support

For support, please:

1. Check the [documentation](README.md)
2. Search [existing issues](https://github.com/m-tech-stack/laravel-ai-engine/issues)
3. Create a [new issue](https://github.com/m-tech-stack/laravel-ai-engine/issues/new)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and updates.
