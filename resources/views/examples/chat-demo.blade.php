<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Chat Component Demo</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .demo-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .demo-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .demo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .demo-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .demo-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #1f2937;
        }
        
        .demo-description {
            color: #6b7280;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .code-block {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 15px 0;
        }
        
        .feature-list {
            list-style: none;
            padding: 0;
        }
        
        .feature-list li {
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
        }
        
        .feature-list li:last-child {
            border-bottom: none;
        }
        
        .feature-icon {
            margin-right: 10px;
            color: #10b981;
        }
        
        @media (max-width: 768px) {
            .demo-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="demo-container">
        <div class="demo-header">
            <h1>AI Chat Component Demo</h1>
            <p>Explore different configurations and features of the AI Chat component</p>
        </div>

        <!-- Basic Chat -->
        <div class="demo-card">
            <h2 class="demo-title">Basic AI Chat</h2>
            <p class="demo-description">
                Simple chat interface with default settings. Includes real-time streaming, 
                interactive actions, and conversation memory.
            </p>
            
            <div class="code-block">
&lt;x-ai-chat 
    session-id="demo-basic"
    placeholder="Ask me anything..."
    :suggestions="['What can you help me with?', 'Tell me a joke', 'Explain AI']"
/&gt;
            </div>

            <x-ai-chat 
                session-id="demo-basic"
                placeholder="Ask me anything..."
                :suggestions="['What can you help me with?', 'Tell me a joke', 'Explain AI']"
            />
        </div>

        <div class="demo-grid">
            <!-- Anthropic Chat -->
            <div class="demo-card">
                <h3 class="demo-title">Anthropic Claude</h3>
                <p class="demo-description">
                    Chat powered by Anthropic's Claude with custom height and dark theme.
                </p>
                
                <div class="code-block">
&lt;x-ai-chat 
    session-id="demo-claude"
    engine="anthropic"
    model="claude-3-5-sonnet-20241022"
    theme="dark"
    height="400px"
    placeholder="Chat with Claude..."
/&gt;
                </div>

                <x-ai-chat 
                    session-id="demo-claude"
                    engine="anthropic"
                    model="claude-3-5-sonnet-20241022"
                    theme="dark"
                    height="400px"
                    placeholder="Chat with Claude..."
                />
            </div>

            <!-- Gemini Chat -->
            <div class="demo-card">
                <h3 class="demo-title">Google Gemini</h3>
                <p class="demo-description">
                    Chat with Google's Gemini model with custom suggestions.
                </p>
                
                <div class="code-block">
&lt;x-ai-chat 
    session-id="demo-gemini"
    engine="gemini"
    model="gemini-1.5-pro"
    height="400px"
    :suggestions="['Code review', 'Debug help', 'Best practices']"
/&gt;
                </div>

                <x-ai-chat 
                    session-id="demo-gemini"
                    engine="gemini"
                    model="gemini-1.5-pro"
                    height="400px"
                    :suggestions="['Code review', 'Debug help', 'Best practices']"
                />
            </div>
        </div>

        <!-- Advanced Configuration -->
        <div class="demo-card">
            <h2 class="demo-title">Advanced Configuration</h2>
            <p class="demo-description">
                Fully customized chat with specific configuration options and custom WebSocket settings.
            </p>
            
            <div class="code-block">
&lt;x-ai-chat 
    session-id="demo-advanced"
    engine="openai"
    model="gpt-4o"
    :streaming="true"
    :actions="true"
    :memory="true"
    theme="light"
    height="500px"
    placeholder="Advanced AI chat..."
    :suggestions="['Complex query', 'Multi-step task', 'Creative writing']"
    :config="[
        'websocket_url' => 'ws://localhost:8080',
        'api_endpoint' => '/api/ai-chat',
        'max_messages' => 50,
        'typing_indicator' => true,
        'auto_scroll' => true,
        'show_timestamps' => true,
        'enable_markdown' => true,
        'enable_copy' => true,
        'enable_regenerate' => true,
    ]"
/&gt;
            </div>

            <x-ai-chat 
                session-id="demo-advanced"
                engine="openai"
                model="gpt-4o"
                :streaming="true"
                :actions="true"
                :memory="true"
                theme="light"
                height="500px"
                placeholder="Advanced AI chat..."
                :suggestions="['Complex query', 'Multi-step task', 'Creative writing']"
                :config="[
                    'websocket_url' => 'ws://localhost:8080',
                    'api_endpoint' => '/api/ai-chat',
                    'max_messages' => 50,
                    'typing_indicator' => true,
                    'auto_scroll' => true,
                    'show_timestamps' => true,
                    'enable_markdown' => true,
                    'enable_copy' => true,
                    'enable_regenerate' => true,
                ]"
            />
        </div>

        <!-- Features Overview -->
        <div class="demo-card">
            <h2 class="demo-title">Component Features</h2>
            <div class="demo-grid">
                <div>
                    <h4>Core Features</h4>
                    <ul class="feature-list">
                        <li><span class="feature-icon">✅</span> Real-time WebSocket streaming</li>
                        <li><span class="feature-icon">✅</span> Interactive actions (buttons, quick replies)</li>
                        <li><span class="feature-icon">✅</span> Conversation memory</li>
                        <li><span class="feature-icon">✅</span> Multiple AI engines support</li>
                        <li><span class="feature-icon">✅</span> Automatic failover</li>
                        <li><span class="feature-icon">✅</span> Responsive design</li>
                    </ul>
                </div>
                <div>
                    <h4>Customization</h4>
                    <ul class="feature-list">
                        <li><span class="feature-icon">🎨</span> Light/Dark themes</li>
                        <li><span class="feature-icon">📱</span> Mobile responsive</li>
                        <li><span class="feature-icon">⚙️</span> Configurable height</li>
                        <li><span class="feature-icon">💬</span> Custom placeholders</li>
                        <li><span class="feature-icon">🔧</span> Extensive configuration options</li>
                        <li><span class="feature-icon">🎯</span> Suggestion prompts</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Usage Instructions -->
        <div class="demo-card">
            <h2 class="demo-title">Usage Instructions</h2>
            <div class="demo-description">
                <h4>1. Installation</h4>
                <div class="code-block">
composer require m-tech-stack/laravel-ai-engine
php artisan vendor:publish --tag=ai-engine-config
                </div>

                <h4>2. Configuration</h4>
                <p>Configure your AI engines in <code>config/ai-engine.php</code>:</p>
                <div class="code-block">
'engines' => [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],
],
                </div>

                <h4>3. WebSocket Server (Optional)</h4>
                <p>Start the WebSocket server for real-time streaming:</p>
                <div class="code-block">
php artisan ai-engine:streaming-server start --host=0.0.0.0 --port=8080
                </div>

                <h4>4. Use in Blade Templates</h4>
                <div class="code-block">
&lt;x-ai-chat 
    session-id="unique-session-id"
    engine="openai"
    model="gpt-4o"
    placeholder="Type your message..."
    :suggestions="['Hello', 'Help me with...']"
/&gt;
                </div>
            </div>
        </div>
    </div>

    <!-- Include the AI Chat JavaScript -->
    <script src="{{ asset('vendor/ai-engine/js/ai-chat.js') }}"></script>
</body>
</html>
