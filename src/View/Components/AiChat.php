<?php

namespace LaravelAIEngine\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AiChat extends Component
{
    public function __construct(
        public ?string $sessionId = null,
        public string $engine = 'openai',
        public string $model = 'gpt-4o',
        public bool $streaming = true,
        public bool $actions = true,
        public bool $memory = true,
        public string $theme = 'light',
        public string $height = '600px',
        public string $placeholder = 'Type your message...',
        public array $suggestions = [],
        public array $config = []
    ) {
        $this->sessionId = $sessionId ?? 'ai-chat-' . uniqid();
        
        // Merge default config with provided config
        $this->config = array_merge([
            'websocket_url' => config('ai-engine.streaming.websocket.host', 'ws://localhost:8080'),
            'api_endpoint' => '/api/ai-chat',
            'max_messages' => 100,
            'typing_indicator' => true,
            'auto_scroll' => true,
            'show_timestamps' => true,
            'enable_markdown' => true,
            'enable_copy' => true,
            'enable_regenerate' => true,
        ], $this->config);
    }

    public function render(): View
    {
        return view('ai-engine::components.ai-chat');
    }
}
