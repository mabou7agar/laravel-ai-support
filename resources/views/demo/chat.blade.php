<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'AI Chat Demo' }} - Laravel AI Engine</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 20px;
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .chat-wrapper {
            width: 100%;
            max-width: 1200px;
            height: 80vh;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .footer {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 16px;
            text-align: center;
            color: white;
            font-size: 13px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .demo-links {
            display: flex;
            gap: 12px;
            margin-top: 12px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .demo-link {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .demo-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .demo-link.active {
            background: white;
            color: #667eea;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🤖 {{ $title ?? 'AI Chat Demo' }}</h1>
        <p>Powered by Laravel AI Engine - Enhanced Chat with Modern Features</p>
        <div class="demo-links">
            <a href="{{ route('ai-engine.chat.index') }}" class="demo-link {{ request()->routeIs('ai-engine.chat.index') ? 'active' : '' }}">
                💬 Basic Chat
            </a>
            <a href="{{ route('ai-engine.chat.rag') }}" class="demo-link {{ request()->routeIs('ai-engine.chat.rag') ? 'active' : '' }}">
                📚 RAG Chat
            </a>
            <a href="{{ route('ai-engine.chat.voice') }}" class="demo-link {{ request()->routeIs('ai-engine.chat.voice') ? 'active' : '' }}">
                🎤 Voice Chat
            </a>
            <a href="{{ route('ai-engine.chat.multimodal') }}" class="demo-link {{ request()->routeIs('ai-engine.chat.multimodal') ? 'active' : '' }}">
                🎨 Multi-Modal
            </a>
        </div>
    </div>
    
    <div class="container">
        <div class="chat-wrapper">
            <x-ai-engine::ai-chat-enhanced
                sessionId="demo-chat-{{ uniqid() }}"
                engine="openai"
                model="gpt-4o-mini"
                theme="light"
                height="100%"
                :streaming="true"
                :actions="true"
                :memory="true"
                :enableVoice="true"
                :enableFileUpload="true"
                :enableSearch="true"
                :enableExport="true"
                :enableRAG="false"
                :suggestions="[
                    'What is Laravel?',
                    'Explain middleware',
                    'How do I use Eloquent?',
                    'Tell me about queues'
                ]"
            />
        </div>
    </div>
    
    <div class="footer">
        <div>
            Laravel AI Engine Demo • 
            <a href="https://github.com/m-tech-stack/laravel-ai-engine" target="_blank">GitHub</a> • 
            <a href="/ai-demo/vector-search">Vector Search Demo</a>
        </div>
        <div style="margin-top: 8px; opacity: 0.8; font-size: 12px;">
            This demo is only available in local environment or when AI_ENGINE_ENABLE_DEMO_ROUTES=true
        </div>
    </div>
    
    <!-- Load Enhanced Chat JavaScript -->
    <script>
        {!! file_get_contents(base_path('packages/laravel-ai-engine/resources/js/ai-chat-enhanced.js')) !!}
    </script>
</body>
</html>
