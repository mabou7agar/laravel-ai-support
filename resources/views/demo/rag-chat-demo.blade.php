<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>RAG Chat Demo - Laravel AI Engine</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .demo-container {
            max-width: 1200px;
            width: 100%;
        }
        
        .demo-header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .demo-header h1 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .demo-header p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .demo-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .feature-card h3 {
            font-size: 18px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .feature-card p {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.5;
        }
        
        .chat-wrapper {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="demo-container">
        <div class="demo-header">
            <h1>🚀 RAG Chat Demo</h1>
            <p>Intelligent AI Chat with Retrieval-Augmented Generation</p>
        </div>
        
        <div class="demo-features">
            <div class="feature-card">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                    </svg>
                    RAG-Powered
                </h3>
                <p>Searches your knowledge base to provide accurate, context-aware responses</p>
            </div>
            
            <div class="feature-card">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                    </svg>
                    Clickable Options
                </h3>
                <p>Numbered options are clickable directly in the response</p>
            </div>
            
            <div class="feature-card">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Smart Actions
                </h3>
                <p>Context-aware action buttons based on the response</p>
            </div>
            
            <div class="feature-card">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Source Citations
                </h3>
                <p>See exactly which sources were used for each response</p>
            </div>
        </div>
        
        <div class="chat-wrapper">
            <x-rag-chat
                :sessionId="'demo-' . uniqid()"
                engine="openai"
                model="gpt-4o"
                height="600px"
                :memory="true"
                :actions="true"
                :useIntelligentRAG="true"
                :ragCollections="['App\\Models\\Post']"
                placeholder="Ask me about Laravel..."
                :showSources="true"
                :showActions="true"
                :showOptions="true"
            />
        </div>
    </div>
</body>
</html>
