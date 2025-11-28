@props([
    'sessionId' => 'ai-chat-' . uniqid(),
    'engine' => 'openai',
    'model' => 'gpt-4o-mini',
    'theme' => 'light',
    'height' => '600px',
    'streaming' => true,
    'actions' => true,
    'memory' => true,
    'placeholder' => 'Type your message...',
    'suggestions' => [],
    'enableVoice' => true,
    'enableFileUpload' => true,
    'enableSearch' => true,
    'enableExport' => true,
    'enableRAG' => false,
    'ragModelClass' => null,
    'ragMaxContext' => 5,
    'ragMinScore' => 0.5,
    'showRAGSources' => true,
    'config' => []
])

<div 
    id="ai-chat-{{ $sessionId }}" 
    class="ai-chat-container enhanced {{ $theme }}"
    style="height: {{ $height }}"
    data-session-id="{{ $sessionId }}"
    data-engine="{{ $engine }}"
    data-model="{{ $model }}"
    data-config="{{ json_encode($config) }}"
>
    <!-- Chat Header -->
    <div class="ai-chat-header">
        <div class="header-left">
            <div class="status-indicator" id="status-{{ $sessionId }}">
                <span class="status-dot connected"></span>
                <span class="status-text">Ready</span>
            </div>
        </div>
        
        <div class="header-center">
            <div class="ai-chat-info">
                <span class="engine-badge">{{ strtoupper($engine) }}</span>
                <span class="model-name">{{ $model }}</span>
            </div>
        </div>
        
        <div class="header-right">
            @if($enableSearch)
            <button class="header-action-btn" id="search-toggle-{{ $sessionId }}" title="Search (Ctrl+K)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
            </button>
            @endif
            
            @if($enableExport)
            <button class="header-action-btn" id="export-{{ $sessionId }}" title="Export Chat">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
            </button>
            @endif
            
            <button class="header-action-btn" id="theme-toggle-{{ $sessionId }}" title="Toggle Theme (Ctrl+/)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>
            
            <button class="header-action-btn" id="clear-chat-{{ $sessionId }}" title="Clear Chat">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
            </button>
            
            <button class="header-action-btn" id="settings-{{ $sessionId }}" title="Settings">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Search Bar (Hidden by default) -->
    @if($enableSearch)
    <div class="search-container" id="search-container-{{ $sessionId }}" style="display: none;">
        <div class="search-wrapper">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <path d="m21 21-4.35-4.35"/>
            </svg>
            <input 
                type="text" 
                class="search-input" 
                placeholder="Search messages..."
                id="search-input-{{ $sessionId }}"
            />
            <button class="search-close-btn" id="search-close-{{ $sessionId }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="search-results" id="search-results-{{ $sessionId }}"></div>
    </div>
    @endif

    <!-- Messages Container -->
    <div class="ai-chat-messages" id="messages-{{ $sessionId }}">
        <!-- Welcome Message -->
        <div class="message assistant welcome-message">
            <div class="message-avatar">
                <div class="avatar-assistant">🤖</div>
            </div>
            <div class="message-content">
                <div class="message-text">
                    <h3>👋 Hello! I'm your AI assistant</h3>
                    <p>I'm powered by <strong>{{ ucfirst($engine) }}</strong> using the <code>{{ $model }}</code> model.</p>
                    <p>How can I help you today?</p>
                </div>
                
                @if(count($suggestions) > 0)
                    <div class="suggestion-chips">
                        @foreach($suggestions as $suggestion)
                            <button class="suggestion-chip" data-suggestion="{{ $suggestion }}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                                {{ $suggestion }}
                            </button>
                        @endforeach
                    </div>
                @endif
                
                <div class="welcome-features">
                    <div class="feature-badge">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                        </svg>
                        Real-time streaming
                    </div>
                    @if($enableVoice)
                    <div class="feature-badge">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                            <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                        </svg>
                        Voice input
                    </div>
                    @endif
                    @if($enableFileUpload)
                    <div class="feature-badge">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                        </svg>
                        File uploads
                    </div>
                    @endif
                    <div class="feature-badge">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="16 18 22 12 16 6"/>
                            <polyline points="8 6 2 12 8 18"/>
                        </svg>
                        Code highlighting
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Typing Indicator -->
    <div class="typing-indicator" id="typing-{{ $sessionId }}" style="display: none;">
        <div class="message assistant">
            <div class="message-avatar">
                <div class="avatar-assistant">🤖</div>
            </div>
            <div class="message-content">
                <div class="typing-animation">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Input Container -->
    <div class="ai-chat-input-container">
        <!-- File Upload Preview -->
        <div class="file-upload-preview" id="file-preview-{{ $sessionId }}" style="display: none;">
            <div class="file-preview-content">
                <div class="file-icon">📎</div>
                <div class="file-info">
                    <div class="file-name"></div>
                    <div class="file-size"></div>
                </div>
                <button class="file-remove-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="upload-progress">
                <div class="progress-bar" style="width: 0%"></div>
            </div>
        </div>
        
        <div class="input-wrapper">
            <!-- File Upload Button -->
            @if($enableFileUpload)
            <button class="input-action-btn file-upload-btn" id="file-upload-{{ $sessionId }}" title="Attach file">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
            </button>
            <input 
                type="file" 
                id="file-input-{{ $sessionId }}" 
                style="display: none;"
                accept="image/*,.pdf,.txt,.md"
            />
            @endif
            
            <textarea 
                id="message-input-{{ $sessionId }}"
                class="message-input"
                placeholder="{{ $placeholder }}"
                rows="1"
                maxlength="4000"
            ></textarea>
            
            <!-- Voice Input Button -->
            @if($enableVoice)
            <button class="input-action-btn voice-input-btn" id="voice-input-{{ $sessionId }}" title="Voice input">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                    <line x1="12" y1="19" x2="12" y2="23"/>
                    <line x1="8" y1="23" x2="16" y2="23"/>
                </svg>
            </button>
            @endif
            
            <!-- Send Button -->
            <button 
                id="send-btn-{{ $sessionId }}"
                class="send-button"
                disabled
                title="Send message (Ctrl+Enter)"
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22,2 15,22 11,13 2,9"/>
                </svg>
            </button>
        </div>
        
        <div class="input-footer">
            <div class="footer-left">
                <span class="char-count">
                    <span id="char-count-{{ $sessionId }}">0</span>/4000
                </span>
            </div>
            <div class="footer-right">
                <span class="keyboard-hint">
                    <kbd>Ctrl</kbd> + <kbd>Enter</kbd> to send
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Styles -->
<style>
.ai-chat-container.enhanced {
    display: flex;
    flex-direction: column;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    transition: all 0.3s ease;
}

/* Light Theme */
.ai-chat-container.light {
    background: #ffffff;
    color: #1f2937;
}

.ai-chat-container.light .ai-chat-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.ai-chat-container.light .message.user .message-content {
    background: #667eea;
    color: white;
}

.ai-chat-container.light .message.assistant .message-content {
    background: #f3f4f6;
    color: #1f2937;
}

/* Dark Theme */
.ai-chat-container.dark {
    background: #1f2937;
    color: #f9fafb;
}

.ai-chat-container.dark .ai-chat-header {
    background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%);
    color: white;
}

.ai-chat-container.dark .message.user .message-content {
    background: #5b21b6;
    color: white;
}

.ai-chat-container.dark .message.assistant .message-content {
    background: #374151;
    color: #f9fafb;
}

.ai-chat-container.dark .message-input {
    background: #374151;
    color: #f9fafb;
    border-color: #4b5563;
}

/* Header */
.ai-chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.header-left, .header-center, .header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.status-dot.connected {
    background: #10b981;
}

.status-dot.disconnected {
    background: #ef4444;
}

.status-dot.connecting {
    background: #f59e0b;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.engine-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.model-name {
    font-size: 13px;
    opacity: 0.9;
}

.header-action-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    padding: 8px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    color: white;
}

.header-action-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px);
}

/* Search Container */
.search-container {
    padding: 12px 20px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}

.ai-chat-container.dark .search-container {
    background: #374151;
    border-color: #4b5563;
}

.search-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    background: white;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.ai-chat-container.dark .search-wrapper {
    background: #1f2937;
    border-color: #4b5563;
}

.search-input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 14px;
    background: transparent;
}

.search-close-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    opacity: 0.6;
    transition: opacity 0.2s;
}

.search-close-btn:hover {
    opacity: 1;
}

/* Messages */
.ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    scroll-behavior: smooth;
}

.message {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    animation: messageSlideIn 0.3s ease;
}

@keyframes messageSlideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.message-avatar {
    flex-shrink: 0;
}

.avatar-user, .avatar-assistant {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.message-content {
    flex: 1;
    max-width: 80%;
}

.message.user .message-content {
    border-radius: 18px 18px 4px 18px;
    padding: 12px 16px;
}

.message.assistant .message-content {
    border-radius: 18px 18px 18px 4px;
    padding: 12px 16px;
}

.message-text {
    line-height: 1.6;
    word-wrap: break-word;
}

.message-text h3 {
    margin: 0 0 8px 0;
    font-size: 16px;
}

.message-text p {
    margin: 8px 0;
}

.message-text code {
    background: rgba(0, 0, 0, 0.05);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
    font-size: 13px;
}

.message-text pre {
    background: #1f2937;
    color: #f9fafb;
    padding: 16px;
    border-radius: 8px;
    overflow-x: auto;
    margin: 12px 0;
    position: relative;
}

.message-text pre code {
    background: none;
    padding: 0;
    color: inherit;
}

.code-copy-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    color: white;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s;
}

.code-copy-btn:hover {
    background: rgba(255, 255, 255, 0.2);
}

.message-actions-bar {
    display: flex;
    gap: 6px;
    margin-top: 8px;
    opacity: 0;
    transition: opacity 0.2s;
}

.message:hover .message-actions-bar {
    opacity: 1;
}

.message-action-btn {
    background: rgba(0, 0, 0, 0.05);
    border: none;
    padding: 4px 8px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.message-action-btn:hover {
    background: rgba(0, 0, 0, 0.1);
    transform: scale(1.1);
}

.message-time {
    font-size: 11px;
    opacity: 0.6;
    margin-top: 6px;
}

/* Suggestions */
.suggestion-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.suggestion-chip {
    background: rgba(102, 126, 234, 0.1);
    border: 1px solid rgba(102, 126, 234, 0.3);
    padding: 8px 14px;
    border-radius: 20px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}

.suggestion-chip:hover {
    background: rgba(102, 126, 234, 0.2);
    transform: translateY(-2px);
}

.welcome-features {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
}

.feature-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    opacity: 0.7;
}

/* Typing Indicator */
.typing-animation {
    display: flex;
    gap: 4px;
    padding: 12px 0;
}

.typing-animation span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
    opacity: 0.4;
    animation: typing 1.4s infinite;
}

.typing-animation span:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-animation span:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes typing {
    0%, 60%, 100% {
        opacity: 0.4;
        transform: scale(1);
    }
    30% {
        opacity: 1;
        transform: scale(1.2);
    }
}

/* Input Container */
.ai-chat-input-container {
    border-top: 1px solid #e5e7eb;
    background: white;
}

.ai-chat-container.dark .ai-chat-input-container {
    background: #1f2937;
    border-color: #4b5563;
}

.file-upload-preview {
    padding: 12px 20px;
    border-bottom: 1px solid #e5e7eb;
}

.file-preview-content {
    display: flex;
    align-items: center;
    gap: 12px;
}

.file-icon {
    font-size: 24px;
}

.file-info {
    flex: 1;
}

.file-name {
    font-size: 13px;
    font-weight: 500;
}

.file-size {
    font-size: 11px;
    opacity: 0.6;
}

.file-remove-btn {
    background: #ef4444;
    border: none;
    padding: 6px;
    border-radius: 6px;
    color: white;
    cursor: pointer;
}

.upload-progress {
    height: 3px;
    background: #e5e7eb;
    border-radius: 2px;
    margin-top: 8px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: #667eea;
    transition: width 0.3s;
}

.input-wrapper {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    padding: 16px 20px;
}

.input-action-btn {
    background: transparent;
    border: 1px solid #e5e7eb;
    padding: 10px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    color: #6b7280;
    flex-shrink: 0;
}

.input-action-btn:hover {
    background: #f3f4f6;
    border-color: #667eea;
    color: #667eea;
}

.voice-input-btn.recording {
    background: #ef4444;
    border-color: #ef4444;
    color: white;
    animation: recordingPulse 1s infinite;
}

@keyframes recordingPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.message-input {
    flex: 1;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 14px;
    resize: none;
    max-height: 150px;
    font-family: inherit;
    transition: all 0.2s;
}

.message-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.send-button {
    background: #667eea;
    border: none;
    padding: 12px 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    color: white;
    flex-shrink: 0;
}

.send-button:hover:not(:disabled) {
    background: #5568d3;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.send-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.input-footer {
    display: flex;
    justify-content: space-between;
    padding: 0 20px 12px;
    font-size: 11px;
    opacity: 0.6;
}

.keyboard-hint kbd {
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 4px;
    border: 1px solid #e5e7eb;
    font-family: monospace;
}

/* Notification */
.chat-notification {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #1f2937;
    color: white;
    padding: 12px 20px;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.3s;
    z-index: 1000;
}

.chat-notification.show {
    opacity: 1;
    transform: translateY(0);
}

/* Scrollbar */
.ai-chat-messages::-webkit-scrollbar {
    width: 6px;
}

.ai-chat-messages::-webkit-scrollbar-track {
    background: transparent;
}

.ai-chat-messages::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 3px;
}

.ai-chat-messages::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 0, 0, 0.3);
}

/* RAG Sources */
.rag-sources-container {
    margin-top: 16px;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
    padding-top: 12px;
}

.ai-chat-container.dark .rag-sources-container {
    border-color: rgba(255, 255, 255, 0.1);
}

.rag-sources-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    opacity: 0.8;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s;
}

.rag-sources-header:hover {
    background: rgba(0, 0, 0, 0.05);
}

.ai-chat-container.dark .rag-sources-header:hover {
    background: rgba(255, 255, 255, 0.05);
}

.toggle-sources-btn {
    margin-left: auto;
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    transition: transform 0.2s;
}

.toggle-sources-btn.expanded {
    transform: rotate(180deg);
}

.rag-sources-list {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.rag-source-item {
    background: rgba(102, 126, 234, 0.05);
    border: 1px solid rgba(102, 126, 234, 0.2);
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.2s;
}

.rag-source-item:hover {
    border-color: rgba(102, 126, 234, 0.4);
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.1);
}

.source-score-bar {
    height: 3px;
    background: rgba(0, 0, 0, 0.05);
}

.score-fill {
    height: 100%;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    transition: width 0.3s;
}

.source-content {
    padding: 12px;
}

.source-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.source-number {
    background: #667eea;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.source-title {
    flex: 1;
    font-weight: 500;
    font-size: 13px;
}

.source-score {
    background: rgba(102, 126, 234, 0.1);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    color: #667eea;
}

.source-preview {
    font-size: 12px;
    line-height: 1.5;
    opacity: 0.8;
    margin-bottom: 6px;
}

.source-metadata {
    font-size: 11px;
    opacity: 0.6;
}

/* Responsive */
@media (max-width: 768px) {
    .ai-chat-header {
        padding: 12px 16px;
    }
    
    .header-center {
        display: none;
    }
    
    .ai-chat-messages {
        padding: 16px;
    }
    
    .message-content {
        max-width: 90%;
    }
    
    .keyboard-hint {
        display: none;
    }
    
    .rag-source-item {
        font-size: 12px;
    }
}
</style>

<!-- Initialize Enhanced Chat -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatContainer = document.getElementById('ai-chat-{{ $sessionId }}');
    const sessionId = '{{ $sessionId }}';
    
    // Initialize Enhanced Chat UI
    const chatUI = new EnhancedAiChatUI('ai-chat-{{ $sessionId }}', {
        sessionId: sessionId,
        websocketUrl: '{{ config("ai-engine.websocket_url", "ws://localhost:8080") }}',
        apiEndpoint: '/{{ config("ai-engine.demo_route_prefix", "ai-demo") }}/api/chat',
        streaming: {{ $streaming ? 'true' : 'false' }},
        actions: {{ $actions ? 'true' : 'false' }},
        memory: {{ $memory ? 'true' : 'false' }},
        theme: '{{ $theme }}',
        enableVoice: {{ $enableVoice ? 'true' : 'false' }},
        enableFileUpload: {{ $enableFileUpload ? 'true' : 'false' }},
        enableSearch: {{ $enableSearch ? 'true' : 'false' }},
        enableExport: {{ $enableExport ? 'true' : 'false' }},
        enableRAG: {{ $enableRAG ? 'true' : 'false' }},
        ragModelClass: '{{ $ragModelClass }}',
        ragMaxContext: {{ $ragMaxContext }},
        ragMinScore: {{ $ragMinScore }},
        showRAGSources: {{ $showRAGSources ? 'true' : 'false' }}
    });
    
    // Setup event handlers
    const messageInput = document.getElementById('message-input-{{ $sessionId }}');
    const sendBtn = document.getElementById('send-btn-{{ $sessionId }}');
    const charCount = document.getElementById('char-count-{{ $sessionId }}');
    const clearBtn = document.getElementById('clear-chat-{{ $sessionId }}');
    const themeBtn = document.getElementById('theme-toggle-{{ $sessionId }}');
    const searchToggle = document.getElementById('search-toggle-{{ $sessionId }}');
    const exportBtn = document.getElementById('export-{{ $sessionId }}');
    
    // Auto-resize textarea
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
        charCount.textContent = this.value.length;
        sendBtn.disabled = !this.value.trim();
    });
    
    // Send message
    sendBtn.addEventListener('click', function() {
        const message = messageInput.value.trim();
        if (message) {
            chatUI.client.sendMessage(sessionId, message);
            chatUI.addMessage('user', message);
            chatUI.showTypingIndicator();
            messageInput.value = '';
            messageInput.style.height = 'auto';
            charCount.textContent = '0';
            sendBtn.disabled = true;
        }
    });
    
    // Enter to send
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendBtn.click();
        }
    });
    
    // Clear chat
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (confirm('Are you sure you want to clear the chat history?')) {
                chatUI.clearMessages();
            }
        });
    }
    
    // Toggle theme
    if (themeBtn) {
        themeBtn.addEventListener('click', function() {
            chatUI.toggleTheme();
        });
    }
    
    // Toggle search
    if (searchToggle) {
        const searchContainer = document.getElementById('search-container-{{ $sessionId }}');
        const searchClose = document.getElementById('search-close-{{ $sessionId }}');
        
        searchToggle.addEventListener('click', function() {
            searchContainer.style.display = searchContainer.style.display === 'none' ? 'block' : 'none';
            if (searchContainer.style.display === 'block') {
                document.getElementById('search-input-{{ $sessionId }}').focus();
            }
        });
        
        if (searchClose) {
            searchClose.addEventListener('click', function() {
                searchContainer.style.display = 'none';
            });
        }
    }
    
    // Export chat
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            chatUI.exportChat('json');
        });
    }
    
    // Suggestion chips
    document.querySelectorAll('.suggestion-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            const suggestion = this.dataset.suggestion;
            messageInput.value = suggestion;
            messageInput.dispatchEvent(new Event('input'));
            messageInput.focus();
        });
    });
    
    // File upload
    @if($enableFileUpload)
    const fileUploadBtn = document.getElementById('file-upload-{{ $sessionId }}');
    const fileInput = document.getElementById('file-input-{{ $sessionId }}');
    
    if (fileUploadBtn && fileInput) {
        fileUploadBtn.addEventListener('click', function() {
            fileInput.click();
        });
        
        fileInput.addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (file) {
                try {
                    const result = await chatUI.client.uploadFile(sessionId, file, (progress) => {
                        console.log('Upload progress:', progress);
                    });
                    chatUI.showNotification('File uploaded successfully!');
                } catch (error) {
                    chatUI.showNotification('File upload failed');
                }
            }
        });
    }
    @endif
    
    // Voice input
    @if($enableVoice)
    const voiceBtn = document.getElementById('voice-input-{{ $sessionId }}');
    
    if (voiceBtn) {
        voiceBtn.addEventListener('click', function() {
            if (chatUI.isRecording) {
                chatUI.stopVoiceRecording();
            } else {
                chatUI.startVoiceRecording();
            }
        });
    }
    @endif
});
</script>
