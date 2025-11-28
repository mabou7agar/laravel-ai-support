<div 
    id="ai-chat-{{ $sessionId }}" 
    class="ai-chat-container {{ $theme }}"
    style="height: {{ $height }}"
    data-session-id="{{ $sessionId }}"
    data-engine="{{ $engine }}"
    data-model="{{ $model }}"
    data-streaming="{{ $streaming ? 'true' : 'false' }}"
    data-actions="{{ $actions ? 'true' : 'false' }}"
    data-memory="{{ $memory ? 'true' : 'false' }}"
    data-config="{{ json_encode($config) }}"
>
    <!-- Chat Header -->
    <div class="ai-chat-header">
        <div class="ai-chat-status">
            <div class="status-indicator" id="status-{{ $sessionId }}">
                <span class="status-dot"></span>
                <span class="status-text">Ready</span>
            </div>
        </div>
        <div class="ai-chat-info">
            <span class="engine-info">{{ ucfirst($engine) }} • {{ $model }}</span>
        </div>
        <div class="ai-chat-actions">
            <button class="chat-action-btn" id="clear-chat-{{ $sessionId }}" title="Clear Chat">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c0-1 1-2 2-2v2"/>
                </svg>
            </button>
            <button class="chat-action-btn" id="settings-{{ $sessionId }}" title="Settings">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M12 1v6m0 6v6m11-7h-6m-6 0H1"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Messages Container -->
    <div class="ai-chat-messages" id="messages-{{ $sessionId }}">
        <!-- Welcome Message -->
        <div class="message assistant welcome-message">
            <div class="message-avatar">
                <div class="avatar-ai">AI</div>
            </div>
            <div class="message-content">
                <div class="message-text">
                    Hello! I'm your AI assistant. How can I help you today?
                </div>
                @if(count($suggestions) > 0)
                    <div class="suggestion-actions">
                        @foreach($suggestions as $suggestion)
                            <button class="suggestion-btn" data-suggestion="{{ $suggestion }}">
                                {{ $suggestion }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Typing Indicator -->
    <div class="typing-indicator" id="typing-{{ $sessionId }}" style="display: none;">
        <div class="message assistant">
            <div class="message-avatar">
                <div class="avatar-ai">AI</div>
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
        <div class="input-wrapper">
            <textarea 
                id="message-input-{{ $sessionId }}"
                class="message-input"
                placeholder="{{ $placeholder }}"
                rows="1"
                maxlength="4000"
            ></textarea>
            <button 
                id="send-btn-{{ $sessionId }}"
                class="send-button"
                disabled
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22,2 15,22 11,13 2,9"/>
                </svg>
            </button>
        </div>
        <div class="input-footer">
            <div class="character-count">
                <span id="char-count-{{ $sessionId }}">0</span>/4000
            </div>
            @if($streaming)
                <div class="streaming-indicator">
                    <span class="streaming-icon">⚡</span>
                    Real-time
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Styles -->
<style>
.ai-chat-container {
    display: flex;
    flex-direction: column;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #ffffff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.ai-chat-container.dark {
    background: #1f2937;
    border-color: #374151;
    color: #f9fafb;
}

/* Header */
.ai-chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}

.dark .ai-chat-header {
    background: #111827;
    border-bottom-color: #374151;
}

.ai-chat-status {
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    font-weight: 500;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    animation: pulse 2s infinite;
}

.status-dot.connecting {
    background: #f59e0b;
}

.status-dot.error {
    background: #ef4444;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.engine-info {
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
}

.dark .engine-info {
    color: #9ca3af;
}

.ai-chat-actions {
    display: flex;
    gap: 8px;
}

.chat-action-btn {
    padding: 6px;
    border: none;
    background: none;
    border-radius: 6px;
    cursor: pointer;
    color: #6b7280;
    transition: all 0.2s;
}

.chat-action-btn:hover {
    background: #e5e7eb;
    color: #374151;
}

.dark .chat-action-btn:hover {
    background: #374151;
    color: #d1d5db;
}

/* Messages */
.ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.message {
    display: flex;
    gap: 12px;
    max-width: 85%;
}

.message.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message-avatar {
    flex-shrink: 0;
}

.avatar-ai, .avatar-user {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: white;
}

.avatar-ai {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.avatar-user {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.message-content {
    flex: 1;
    min-width: 0;
}

.message-text {
    background: #f3f4f6;
    padding: 12px 16px;
    border-radius: 18px;
    line-height: 1.5;
    word-wrap: break-word;
}

.message.user .message-text {
    background: #3b82f6;
    color: white;
}

.dark .message-text {
    background: #374151;
    color: #f9fafb;
}

.dark .message.user .message-text {
    background: #2563eb;
}

.message-time {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 4px;
    padding: 0 16px;
}

/* Interactive Actions */
.message-actions {
    margin-top: 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 12px 0 0 0;
    border-top: 1px solid rgba(0, 0, 0, 0.06);
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.action-btn {
    padding: 8px 14px;
    border: 1.5px solid rgba(0, 0, 0, 0.06);
    background: linear-gradient(to bottom, #ffffff 0%, #fafbfc 100%);
    border-radius: 7px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    color: #374151;
    box-shadow: 
        0 1px 2px rgba(0, 0, 0, 0.04),
        0 0 0 1px rgba(0, 0, 0, 0.02) inset;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    position: relative;
    overflow: hidden;
    letter-spacing: 0.01em;
    min-height: 34px;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
}

.action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0) 100%);
    opacity: 0;
    transition: opacity 0.25s;
    pointer-events: none;
}

.action-btn::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.05);
    transform: translate(-50%, -50%);
    transition: width 0.4s, height 0.4s;
}

.action-btn:hover {
    background: linear-gradient(to bottom, #f9fafb 0%, #f3f4f6 100%);
    border-color: rgba(0, 0, 0, 0.1);
    box-shadow: 
        0 3px 8px rgba(0, 0, 0, 0.1),
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 0 0 1px rgba(0, 0, 0, 0.03) inset;
    transform: translateY(-2px);
    color: #111827;
}

.action-btn:hover::before {
    opacity: 1;
}

.action-btn:active {
    transform: translateY(0);
    box-shadow: 
        0 1px 2px rgba(0, 0, 0, 0.08),
        0 0 0 1px rgba(0, 0, 0, 0.04) inset;
    background: linear-gradient(to bottom, #f1f5f9 0%, #e2e8f0 100%);
}

.action-btn:active::after {
    width: 100%;
    height: 100%;
    transition: width 0s, height 0s;
}

.action-btn.primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border-color: #2563eb;
    color: white;
    box-shadow: 
        0 2px 6px rgba(37, 99, 235, 0.25),
        0 1px 3px rgba(37, 99, 235, 0.15),
        0 0 0 1px rgba(255, 255, 255, 0.1) inset;
    font-weight: 600;
}

.action-btn.primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 
        0 4px 12px rgba(37, 99, 235, 0.35),
        0 2px 6px rgba(37, 99, 235, 0.2),
        0 0 0 1px rgba(255, 255, 255, 0.15) inset;
    border-color: #1d4ed8;
    transform: translateY(-2px);
}

.action-btn.primary:active {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    box-shadow: 
        0 1px 3px rgba(37, 99, 235, 0.3),
        0 0 0 1px rgba(0, 0, 0, 0.1) inset;
}

.action-btn.secondary {
    background: linear-gradient(to bottom, #f9fafb 0%, #f3f4f6 100%);
    border-color: rgba(0, 0, 0, 0.08);
    color: #6b7280;
}

.action-btn.secondary:hover {
    background: linear-gradient(to bottom, #f3f4f6 0%, #e5e7eb 100%);
    color: #374151;
}

.action-btn.success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-color: #059669;
    color: white;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25);
}

.action-btn.success:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35);
}

.action-btn.danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    border-color: #dc2626;
    color: white;
    box-shadow: 0 2px 6px rgba(239, 68, 68, 0.25);
}

.action-btn.danger:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
}

.action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.dark .action-btn {
    background: #374151;
    border-color: #4b5563;
    color: #f9fafb;
}

.dark .action-btn:hover {
    background: #4b5563;
}

/* Suggestions */
.suggestion-actions {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.suggestion-btn {
    padding: 6px 12px;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 16px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
    color: #6b7280;
}

.suggestion-btn:hover {
    background: #f9fafb;
    border-color: #3b82f6;
    color: #3b82f6;
}

.dark .suggestion-btn {
    background: #374151;
    border-color: #4b5563;
    color: #d1d5db;
}

/* Typing Indicator */
.typing-animation {
    display: flex;
    gap: 4px;
    padding: 12px 16px;
    background: #f3f4f6;
    border-radius: 18px;
    width: fit-content;
}

.dark .typing-animation {
    background: #374151;
}

.typing-animation span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #9ca3af;
    animation: typing 1.4s infinite ease-in-out;
}

.typing-animation span:nth-child(1) { animation-delay: -0.32s; }
.typing-animation span:nth-child(2) { animation-delay: -0.16s; }

@keyframes typing {
    0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
    40% { transform: scale(1); opacity: 1; }
}

/* Input */
.ai-chat-input-container {
    border-top: 1px solid #e5e7eb;
    padding: 16px;
    background: #ffffff;
}

.dark .ai-chat-input-container {
    border-top-color: #374151;
    background: #1f2937;
}

.input-wrapper {
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.message-input {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 20px;
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.5;
    resize: none;
    max-height: 120px;
    background: white;
    transition: border-color 0.2s;
}

.message-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.dark .message-input {
    background: #374151;
    border-color: #4b5563;
    color: #f9fafb;
}

.dark .message-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.send-button {
    padding: 12px;
    border: none;
    background: #3b82f6;
    color: white;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
}

.send-button:hover:not(:disabled) {
    background: #2563eb;
    transform: scale(1.05);
}

.send-button:disabled {
    background: #d1d5db;
    cursor: not-allowed;
    transform: none;
}

.dark .send-button:disabled {
    background: #4b5563;
}

.input-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
    font-size: 12px;
    color: #9ca3af;
}

.streaming-indicator {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #10b981;
}

.streaming-icon {
    font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
    .ai-chat-container {
        height: 100vh !important;
        border-radius: 0;
        border: none;
    }
    
    .message {
        max-width: 90%;
    }
}

/* Scrollbar */
.ai-chat-messages::-webkit-scrollbar {
    width: 6px;
}

.ai-chat-messages::-webkit-scrollbar-track {
    background: transparent;
}

.ai-chat-messages::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 3px;
}

.dark .ai-chat-messages::-webkit-scrollbar-thumb {
    background: #4b5563;
}

/* Animations */
.message {
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.welcome-message {
    border: 2px dashed #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    background: #f9fafb;
}

.dark .welcome-message {
    border-color: #374151;
    background: #111827;
}
</style>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatContainer = document.getElementById('ai-chat-{{ $sessionId }}');
    const messagesContainer = document.getElementById('messages-{{ $sessionId }}');
    const messageInput = document.getElementById('message-input-{{ $sessionId }}');
    const sendButton = document.getElementById('send-btn-{{ $sessionId }}');
    const typingIndicator = document.getElementById('typing-{{ $sessionId }}');
    const statusIndicator = document.getElementById('status-{{ $sessionId }}');
    const charCount = document.getElementById('char-count-{{ $sessionId }}');
    const clearButton = document.getElementById('clear-chat-{{ $sessionId }}');
    
    const config = JSON.parse(chatContainer.dataset.config);
    const sessionId = chatContainer.dataset.sessionId;
    const streaming = chatContainer.dataset.streaming === 'true';
    const actionsEnabled = chatContainer.dataset.actions === 'true';
    
    let websocket = null;
    let messageHistory = [];
    
    // Initialize WebSocket if streaming is enabled
    if (streaming) {
        initializeWebSocket();
    }
    
    // Event Listeners
    messageInput.addEventListener('input', handleInputChange);
    messageInput.addEventListener('keydown', handleKeyDown);
    sendButton.addEventListener('click', sendMessage);
    clearButton.addEventListener('click', clearChat);
    
    // Suggestion buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('suggestion-btn')) {
            const suggestion = e.target.dataset.suggestion;
            messageInput.value = suggestion;
            handleInputChange();
            sendMessage();
        }
    });
    
    function initializeWebSocket() {
        try {
            websocket = new WebSocket(config.websocket_url);
            
            websocket.onopen = function() {
                updateStatus('connected', 'Connected');
                // Subscribe to session
                websocket.send(JSON.stringify({
                    type: 'subscribe',
                    session_id: sessionId
                }));
            };
            
            websocket.onmessage = function(event) {
                const data = JSON.parse(event.data);
                handleWebSocketMessage(data);
            };
            
            websocket.onclose = function() {
                updateStatus('disconnected', 'Disconnected');
                // Attempt to reconnect after 3 seconds
                setTimeout(initializeWebSocket, 3000);
            };
            
            websocket.onerror = function() {
                updateStatus('error', 'Connection Error');
            };
        } catch (error) {
            console.error('WebSocket initialization failed:', error);
            updateStatus('error', 'Connection Failed');
        }
    }
    
    function handleWebSocketMessage(data) {
        switch (data.type) {
            case 'ai.response.chunk':
                appendToLastMessage(data.chunk);
                break;
            case 'ai.response.complete':
                hideTypingIndicator();
                if (data.actions && actionsEnabled) {
                    addInteractiveActions(data.actions);
                }
                break;
            case 'ai.streaming.error':
                hideTypingIndicator();
                showError(data.error_message);
                break;
        }
    }
    
    function handleInputChange() {
        const value = messageInput.value;
        charCount.textContent = value.length;
        sendButton.disabled = value.trim().length === 0;
        
        // Auto-resize textarea
        messageInput.style.height = 'auto';
        messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
    }
    
    function handleKeyDown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!sendButton.disabled) {
                sendMessage();
            }
        }
    }
    
    async function sendMessage() {
        const message = messageInput.value.trim();
        if (!message) return;
        
        // Add user message to chat
        addMessage('user', message);
        messageInput.value = '';
        handleInputChange();
        
        // Show typing indicator
        showTypingIndicator();
        
        try {
            if (streaming && websocket && websocket.readyState === WebSocket.OPEN) {
                // Send via WebSocket for streaming
                websocket.send(JSON.stringify({
                    type: 'send_message',
                    session_id: sessionId,
                    message: message,
                    engine: chatContainer.dataset.engine,
                    model: chatContainer.dataset.model,
                    memory: chatContainer.dataset.memory === 'true'
                }));
            } else {
                // Fallback to HTTP request
                await sendHttpMessage(message);
            }
        } catch (error) {
            hideTypingIndicator();
            showError('Failed to send message. Please try again.');
            console.error('Send message error:', error);
        }
    }
    
    async function sendHttpMessage(message) {
        const response = await fetch(config.api_endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                message: message,
                session_id: sessionId,
                engine: chatContainer.dataset.engine,
                model: chatContainer.dataset.model,
                memory: chatContainer.dataset.memory === 'true',
                actions: actionsEnabled
            })
        });
        
        const data = await response.json();
        
        hideTypingIndicator();
        
        if (data.success) {
            addMessage('assistant', data.response);
            if (data.actions && actionsEnabled) {
                addInteractiveActions(data.actions);
            }
        } else {
            showError(data.error || 'An error occurred');
        }
    }
    
    function addMessage(role, content, timestamp = null) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${role}`;
        
        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'message-avatar';
        avatarDiv.innerHTML = `<div class="avatar-${role}">${role === 'user' ? 'You' : 'AI'}</div>`;
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        const textDiv = document.createElement('div');
        textDiv.className = 'message-text';
        textDiv.textContent = content;
        
        contentDiv.appendChild(textDiv);
        
        if (config.show_timestamps) {
            const timeDiv = document.createElement('div');
            timeDiv.className = 'message-time';
            timeDiv.textContent = timestamp || new Date().toLocaleTimeString();
            contentDiv.appendChild(timeDiv);
        }
        
        messageDiv.appendChild(avatarDiv);
        messageDiv.appendChild(contentDiv);
        
        messagesContainer.appendChild(messageDiv);
        
        if (config.auto_scroll) {
            scrollToBottom();
        }
        
        messageHistory.push({ role, content, timestamp: timestamp || new Date().toISOString() });
    }
    
    function appendToLastMessage(chunk) {
        const lastMessage = messagesContainer.querySelector('.message.assistant:last-child .message-text');
        if (lastMessage) {
            lastMessage.textContent += chunk;
            if (config.auto_scroll) {
                scrollToBottom();
            }
        } else {
            // Create new message if none exists
            addMessage('assistant', chunk);
        }
    }
    
    function addInteractiveActions(actions) {
        const lastMessage = messagesContainer.querySelector('.message.assistant:last-child .message-content');
        if (!lastMessage) return;
        
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'message-actions';
        
        actions.forEach(action => {
            const button = document.createElement('button');
            button.className = `action-btn ${action.style || ''}`;
            button.textContent = action.label;
            button.dataset.actionId = action.id;
            button.dataset.actionType = action.type;
            button.dataset.actionData = JSON.stringify(action.data || {});
            
            button.addEventListener('click', function() {
                handleActionClick(action);
            });
            
            actionsDiv.appendChild(button);
        });
        
        lastMessage.appendChild(actionsDiv);
    }
    
    function handleActionClick(action) {
        // Send action execution request
        if (websocket && websocket.readyState === WebSocket.OPEN) {
            websocket.send(JSON.stringify({
                type: 'execute_action',
                session_id: sessionId,
                action_id: action.id,
                action_type: action.type,
                payload: action.data || {}
            }));
        }
        
        // Add user message showing the action taken
        addMessage('user', `Selected: ${action.label}`);
    }
    
    function showTypingIndicator() {
        typingIndicator.style.display = 'block';
        if (config.auto_scroll) {
            scrollToBottom();
        }
    }
    
    function hideTypingIndicator() {
        typingIndicator.style.display = 'none';
    }
    
    function showError(message) {
        addMessage('assistant', `❌ Error: ${message}`);
    }
    
    function updateStatus(status, text) {
        const statusDot = statusIndicator.querySelector('.status-dot');
        const statusText = statusIndicator.querySelector('.status-text');
        
        statusDot.className = `status-dot ${status}`;
        statusText.textContent = text;
    }
    
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    function clearChat() {
        if (confirm('Are you sure you want to clear the chat?')) {
            messagesContainer.innerHTML = '';
            messageHistory = [];
            
            // Add welcome message back
            const welcomeMessage = `
                <div class="message assistant welcome-message">
                    <div class="message-avatar">
                        <div class="avatar-ai">AI</div>
                    </div>
                    <div class="message-content">
                        <div class="message-text">
                            Hello! I'm your AI assistant. How can I help you today?
                        </div>
                        @if(count($suggestions) > 0)
                            <div class="suggestion-actions">
                                @foreach($suggestions as $suggestion)
                                    <button class="suggestion-btn" data-suggestion="{{ $suggestion }}">
                                        {{ $suggestion }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            `;
            messagesContainer.innerHTML = welcomeMessage;
        }
    }
    
    // Initialize
    updateStatus('ready', 'Ready');
    handleInputChange();
});
</script>
